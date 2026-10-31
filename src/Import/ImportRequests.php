<?php
declare(strict_types=1);

namespace Local\AcgnDatabase\Import;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Local\AcgnDatabase\Input;
use Local\AcgnDatabase\Facts\FactsMutation;

final class ImportRequests
{
    private array $cacheUsers = [];
    public function __construct(private ConnectionInterface $db, private Importer $importer, private ?\Closure $users = null) {}

    private function user(int $id): ?User { return $this->users ? ($this->users)($id) : User::find($id); }

    public function authorize(User $actor, bool $review = false): void
    {
        $actor->assertRegistered();
        foreach ($review ? ['viewForum', 'acgn.view', 'acgn.approve', 'acgn.create'] : ['viewForum', 'acgn.view'] as $permission) $actor->assertCan($permission);
    }

    /** Called inside collection confirmation, after locking the applicant's user row. */
    public function submit(array $item, int $account, User $actor, string $conflict): int
    {
        $this->authorize($actor);
        $actor->assertCan('acgn.collect'); $actor->assertCan('acgn.import_collection');
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('acgn_import_requests')->insertOrIgnore(['source_id' => $item['source_id'], 'title' => $item['title'], 'created_at' => $now, 'updated_at' => $now]);
        $request = $this->db->table('acgn_import_requests')->where('source_id', $item['source_id'])->lockForUpdate()->first();
        if ($request->status === 'rejected' && $request->updated_at > gmdate('Y-m-d H:i:s', time() - 7 * 86400)) Input::fail('request', '该作品申请已被拒绝，七天后可重新申请。');
        $key = ['request_id' => $request->id, 'user_id' => $actor->id];
        $old = $this->db->table('acgn_import_applications')->where($key)->first();
        if ($old && $old->status === 'pending' && $old->expires_at > $now) return (int) $request->id;
        if ($this->db->table('acgn_import_applications')->where('user_id', $actor->id)->where('status', 'pending')->where('expires_at', '>', $now)->count() >= 100) Input::fail('request', '待处理申请最多一百项，请先处理或撤回已有申请。');
        if ($request->status !== 'pending') {
            $this->db->table('acgn_import_requests')->where('id', $request->id)->update(['status' => 'pending', 'local_subject_id' => null, 'revision' => $request->revision + 1,
                'generation' => $request->generation + 1, 'notify_after' => 0, 'notify_done' => false, 'decided_by' => null, 'decided_at' => null, 'updated_at' => $now]);
            $request->generation++;
        }
        // Keep only selected personal data. Credentials never enter this payload.
        $payload = ['source_id' => $item['source_id'], 'record' => $item['record'], 'record_hash' => null,
            'source_updated_at' => $item['source_updated_at'], 'account' => $account, 'conflict' => $conflict];
        $fields = ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'status' => 'pending', 'generation' => $request->generation,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 30 * 86400), 'notified_at' => null, 'updated_at' => $now];
        if ($old) $this->db->table('acgn_import_applications')->where($key)->update($fields);
        else $this->db->table('acgn_import_applications')->insert($key + $fields + ['created_at' => $now]);
        return (int) $request->id;
    }

    public function listing(array $params, User $actor): array
    {
        $scope = Input::choice($params['scope'] ?? 'mine', 'scope', ['mine', 'review']);
        $this->authorize($actor, $scope === 'review');
        $after = Input::integer($params['after'] ?? 0, 'after', 0);
        if ($scope === 'mine') {
            $rows = $this->db->table('acgn_import_applications as a')->join('acgn_import_requests as r', 'r.id', '=', 'a.request_id')
                ->where('a.user_id', $actor->id)->where('a.id', '>', $after)->orderBy('a.id')->limit(21)
                ->get(['a.id', 'a.request_id', 'a.status', 'a.expires_at', 'r.title', 'r.source_id', 'r.local_subject_id as subject_id'])->all();
        } else {
            $status = Input::choice($params['status'] ?? 'pending', 'status', ['pending', 'approved', 'rejected']);
            $rows = $this->db->table('acgn_import_requests')->where('status', $status)->where('id', '>', $after)->orderBy('id')->limit(21)
                ->get(['id', 'source_id', 'title', 'status', 'local_subject_id as subject_id', 'revision', 'created_at'])->all();
        }
        $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
        return ['items' => array_map(fn($row) => (array) $row, $rows), 'next' => $more ? (int) end($rows)->id : null];
    }

    public function preview(int $id, array $data, User $actor): array
    {
        $this->authorize($actor, true);
        $request = $this->request($id);
        if ($request->status !== 'pending') Input::fail('request', '该申请已经处理。');
        if (Input::integer($data['revision'] ?? null, 'revision', 0) !== (int) $request->revision) Input::fail('revision', '申请已变化，请刷新。');
        return $this->importer->preview(['subject' => (string) $request->source_id, 'type' => 'anime'], $actor) + ['request_id' => $id, 'revision' => (int) $request->revision];
    }

    public function decide(int $id, array $data, User $actor): array
    {
        $this->authorize($actor, true);
        $decision = Input::choice($data['decision'] ?? null, 'decision', ['approved', 'rejected']);
        $revision = Input::integer($data['revision'] ?? null, 'revision', 0);
        $this->db->transaction(function () use ($id, $data, $actor, $decision, $revision): void {
            $row = $this->request($id, true);
            if ($row->status === $decision) return; // Retried decisions do not replay public writes.
            if ($row->status !== 'pending' || (int) $row->revision !== $revision) Input::fail('revision', '申请已经处理或已变化，请刷新。');
            $subject = $decision === 'approved' ? $this->importer->consume($data, $actor, (int) $row->source_id, true) : null;
            $this->db->table('acgn_import_requests')->where('id', $id)->update(['status' => $decision, 'local_subject_id' => $subject,
                'revision' => $row->revision + 1, 'decided_by' => $actor->id, 'decided_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'), 'notify_done' => true]);
        }, 3);
        $this->bestEffort($id);
        return ['id' => $id, 'status' => $this->request($id)->status];
    }

    public function cancel(int $id, User $actor): array
    {
        $this->authorize($actor);
        return $this->db->transaction(function () use ($id, $actor): array {
            $this->db->table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $row = $this->db->table('acgn_import_applications')->where(['id' => $id, 'user_id' => $actor->id])->lockForUpdate()->first();
            if (!$row) throw new ModelNotFoundException();
            if ($row->status === 'pending') $this->db->table('acgn_import_applications')->where('id', $id)->update(['status' => 'cancelled', 'payload' => null, 'updated_at' => gmdate('Y-m-d H:i:s'), 'notified_at' => gmdate('Y-m-d H:i:s')]);
            if (!$this->db->table('acgn_import_applications')->where('request_id', $row->request_id)->where('status', 'pending')->exists()) {
                $this->db->table('acgn_import_requests')->where('id', $row->request_id)->where('status', 'pending')->update(['status' => 'cancelled', 'notify_done' => true, 'revision' => $this->db->raw('revision + 1')]);
            }
            return ['id' => $id, 'status' => $row->status === 'pending' ? 'cancelled' : $row->status];
        }, 3);
    }

    private function request(int $id, bool $lock = false): object
    {
        $query = $this->db->table('acgn_import_requests')->where('id', $id);
        if ($lock) $query->lockForUpdate();
        return $query->first() ?? throw new ModelNotFoundException();
    }

    public function bestEffort(?int $request = null): void
    {
        try { $this->work(20, $request); }
        catch (\Throwable) { error_log('ACGN import approval follow-up deferred; run acgn:imports:work.'); }
    }

    /** Bounded continuation for large fan-out and interrupted post-commit notification. */
    public function work(int $limit = 20, ?int $request = null): array
    {
        try {
            $limit = max(1, min(20, $limit)); $processed = 0; $until = microtime(true) + 5;
            $query = $this->db->table('acgn_import_applications as a')->join('acgn_import_requests as r', 'r.id', '=', 'a.request_id')
                ->where('a.status', 'pending')->where(fn($q) => $q->whereIn('r.status', ['approved', 'rejected'])->orWhere('a.expires_at', '<=', gmdate('Y-m-d H:i:s')));
            if ($request !== null) $query->where('r.id', $request);
            foreach ($query->orderBy('a.id')->limit($limit)->get(['a.id', 'a.user_id']) as $candidate) {
                if (microtime(true) >= $until) break;
                (new FactsMutation($this->db))->run('acgn-records', (int) $candidate->user_id, [], [], 'record.approval-import', function () use ($candidate): void {
                    $this->db->table('users')->where('id', $candidate->user_id)->lockForUpdate()->first();
                    $app = $this->db->table('acgn_import_applications')->where('id', $candidate->id)->lockForUpdate()->first();
                    if (!$app || $app->status !== 'pending') return;
                    $row = $this->request((int) $app->request_id);
                    if ($app->expires_at <= gmdate('Y-m-d H:i:s') || (int) $app->generation !== (int) $row->generation) $status = 'expired';
                    elseif ($row->status === 'rejected') $status = 'rejected';
                    elseif ($row->status !== 'approved') return;
                    else {
                        $owner = $this->user((int) $app->user_id);
                        $allowed = $owner && !$owner->isGuest();
                        foreach (['viewForum', 'acgn.view', 'acgn.collect', 'acgn.import_collection'] as $permission) $allowed = $allowed && $owner->can($permission);
                        if (!$allowed) $status = 'blocked';
                        else {
                            $payload = json_decode($app->payload, true, 64, JSON_THROW_ON_ERROR);
                            $status = (new CollectionRecord($this->db))->apply($payload, (int) $app->user_id, $payload['account'], $payload['conflict']);
                        }
                    }
                    $this->db->table('acgn_import_applications')->where('id', $app->id)->update(['status' => $status, 'payload' => null, 'updated_at' => gmdate('Y-m-d H:i:s')]);
                });
                $processed++;
            }
            $notice = $this->db->table('acgn_import_requests')->where('notify_done', false)->where('status', 'pending');
            if ($request !== null) $notice->where('id', $request);
            foreach ($notice->orderBy('id')->limit($limit)->get(['id']) as $row) {
                if (microtime(true) >= $until) break;
                $this->notifyReviewers((int) $row->id, 1);
            }
            $results = $this->db->table('acgn_import_applications')->whereNull('notified_at')->where('status', '<>', 'pending');
            if ($request !== null) $results->where('request_id', $request);
            foreach ($results->orderBy('id')->limit($limit)->get(['id']) as $candidate) {
                if (microtime(true) >= $until) break;
                $this->db->transaction(function () use ($candidate): void {
                    $app = $this->db->table('acgn_import_applications')->where('id', $candidate->id)->lockForUpdate()->first();
                    if (!$app || $app->notified_at !== null) return;
                    $row = $this->request((int) $app->request_id);
                    $this->notify((int) $app->user_id, ['scope' => 'mine', 'request_id' => $row->id, 'title' => $row->title, 'status' => $app->status]);
                    $this->db->table('acgn_import_applications')->where('id', $app->id)->update(['notified_at' => gmdate('Y-m-d H:i:s')]);
                }, 3);
            }
            return ['processed' => $processed];
        } finally {
            // Clear counts after SQL delivery commits, including a partially completed batch.
            if (!$this->users && $this->cacheUsers) {
                $cache = resolve('cache.store');
                foreach (array_keys($this->cacheUsers) as $id) {
                    $cache->forget('user.'.$id.'.unread_notification_count'); $cache->forget('user.'.$id.'.new_notification_count');
                }
                $this->cacheUsers = [];
            }
        }
    }

    private function notifyReviewers(int $id, int $limit): void
    {
        $this->db->transaction(function () use ($id, $limit): void {
            $row = $this->request($id, true);
            if ($row->notify_done || $row->status !== 'pending') return;
            if (!$this->db->table('acgn_import_applications')->where('request_id', $row->id)->where('status', 'pending')->where('generation', $row->generation)->where('expires_at', '>', gmdate('Y-m-d H:i:s'))->exists()) {
                $this->db->table('acgn_import_requests')->where('id', $id)->update(['status' => 'expired', 'notify_done' => true, 'revision' => $row->revision + 1]);
                return;
            }
            $groups = $this->db->table('group_permission')->where('permission', 'acgn.approve')->select('group_id');
            $members = $this->db->table('group_user')->where(fn($q) => $q->where('group_id', 1)->orWhereIn('group_id', $groups))->select('user_id');
            $ids = $this->db->table('users')->where('id', '>', $row->notify_after)->where(fn($q) => $q->where('id', 1)->orWhereIn('id', $members))->orderBy('id')->limit($limit)->pluck('id');
            foreach ($ids as $id) {
                $user = $this->user((int) $id); $allowed = $user && !$user->isGuest();
                foreach (['viewForum', 'acgn.view', 'acgn.approve', 'acgn.create'] as $permission) $allowed = $allowed && $user->can($permission);
                if ($allowed) $this->notify((int) $id, ['scope' => 'review', 'request_id' => $row->id, 'title' => $row->title, 'status' => 'pending']);
            }
            $this->db->table('acgn_import_requests')->where('id', $row->id)->update(['notify_after' => $ids->isEmpty() ? $row->notify_after : $ids->last(), 'notify_done' => $ids->count() < $limit]);
        }, 3);
    }

    private function notify(int $id, array $data): void
    {
        $user = $this->user($id);
        if (!$user || !$user->shouldAlert('acgnImportRequest')) return;
        $this->db->table('notifications')->insert(['user_id' => $id, 'from_user_id' => null, 'subject_id' => $data['request_id'], 'type' => 'acgnImportRequest',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => gmdate('Y-m-d H:i:s')]);
        // Database rows and delivery markers share a transaction; invalidate after commit.
        if (!$this->users) $this->cacheUsers[$id] = true;
    }
}
