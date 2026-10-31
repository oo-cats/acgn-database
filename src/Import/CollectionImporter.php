<?php

declare(strict_types=1);

namespace Local\AcgnDatabase\Import;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\AcgnDatabase\Input;
use Local\AcgnDatabase\RecordActivity;

final class CollectionImporter
{
    public function __construct(private ConnectionInterface $db, private Bangumi $bangumi, private ?ImportRequests $requests = null) {}

    public function preview(array $data, User $actor): array
    {
        $this->authorize($actor);
        $this->keys($data, ['access_token', 'offset']);
        $accessToken = Input::text($data, 'access_token', 512);
        $offset = Input::integer($data['offset'] ?? 0, 'offset', 0, 100000);
        $token = bin2hex(random_bytes(24));
        $now = gmdate('Y-m-d H:i:s');
        $expired = $this->db->table('acgn_collection_imports')->where('expires_at', '<=', $now)->orderBy('user_id')->limit(100)->pluck('user_id')->all();
        if ($expired) $this->db->table('acgn_collection_imports')->whereIn('user_id', $expired)->where('expires_at', '<=', $now)->update(['token' => null, 'payload' => null, 'expires_at' => null]);
        $this->db->transaction(function () use ($actor, $token): void {
            $this->lockUser($actor);
            $slot = $this->db->table('acgn_collection_imports')->where('user_id', $actor->id)->first();
            if ($slot && $slot->requested_at > gmdate('Y-m-d H:i:s', time() - 10)) Input::fail('preview', '两次来源读取至少间隔十秒，请稍后再试。');
            $this->db->table('acgn_collection_imports')->updateOrInsert(['user_id' => $actor->id],
                ['token' => $token, 'payload' => null, 'expires_at' => null, 'requested_at' => gmdate('Y-m-d H:i:s')]);
        });
        $page = $this->bangumi->collectionPage($accessToken, $offset);
        unset($accessToken);
        $items = [];
        $seen = [];
        foreach ($page['data'] as $source) {
            if (!is_array($source)) throw new ImportException('来源收藏记录格式无效。');
            $sourceId = Input::integer($source['subject_id'] ?? null, 'subject_id', 1);
            if (isset($seen[$sourceId])) throw new ImportException('Bangumi 收藏分页包含重复条目。');
            $seen[$sourceId] = true;
            if (($source['subject_type'] ?? null) !== 2) throw new ImportException('来源返回了非动画收藏。');
            $status = match ($source['type'] ?? null) { 1 => 'planned', 2 => 'completed', 3 => 'current', 4 => 'on_hold', 5 => 'dropped', default => null };
            if ($status === null) throw new ImportException('来源收藏状态无效。');
            $score = Input::integer($source['rate'] ?? null, 'score', 0, 10);
            $record = Input::record(['status' => $status, 'score' => $score ?: null,
                'progress' => $source['ep_status'] ?? 0, 'comments' => $source['comment'] ?? '', 'favorite' => false]);
            $updated = Input::text($source, 'updated_at', 40);
            if ($updated !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $updated))) {
                throw new ImportException('来源更新时间格式无效。');
            }
            if (isset($source['subject']) && !is_array($source['subject'])) throw new ImportException('来源条目摘要格式无效。');
            $title = Input::text($source['subject'] ?? [], 'name_cn', 255);
            if ($title === '') $title = Input::text($source['subject'] ?? [], 'name', 255);
            $items[] = ['source_id' => $sourceId, 'title' => $title ?: 'Bangumi #'.$sourceId,
                'record' => $record, 'source_updated_at' => $updated ?: null];
        }
        $subjects = $this->db->table('acgn_subjects')->where('source', 'bangumi')->whereIn('source_id', array_column($items, 'source_id'))->get()->keyBy('source_id');
        $records = $this->db->table('acgn_records')->where('user_id', $actor->id)->whereIn('subject_id', $subjects->pluck('id')->all())->get()->keyBy('subject_id');
        foreach ($items as &$item) {
            $subject = $subjects->get($item['source_id']);
            $existing = $subject ? $records->get($subject->id) : null;
            $item['subject_id'] = $subject ? (int) $subject->id : null;
            $item['reason'] = !$subject ? 'missing' : ($subject->archived ? 'archived' : ($subject->type !== 'anime' ? 'type' : ($subject->total_units !== null && $item['record']['progress'] > $subject->total_units ? 'progress' : null)));
            $item['existing'] = $existing ? ['status' => $existing->status, 'score' => $existing->score === null ? null : (int) $existing->score,
                'progress' => (int) $existing->progress, 'comments' => $existing->comments ?? '', 'revision' => (int) $existing->revision] : null;
            $item['record_hash'] = $existing ? hash('sha256', json_encode((array) $existing, JSON_THROW_ON_ERROR)) : null;
        }
        unset($item);
        $payload = ['account' => $page['account'], 'offset' => $offset, 'total' => $page['total'],
            'next_offset' => count($page['data']) === 20 && $offset + 20 < $page['total'] && $offset + 20 <= 100000 ? $offset + 20 : null, 'items' => $items];
        $saved = $this->db->table('acgn_collection_imports')->where('user_id', $actor->id)->where('token', $token)->update([
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 600)]);
        if (!$saved) Input::fail('preview', '预览已被另一个请求替换，请重新读取。');
        return $payload + ['token' => $token, 'expires_in' => 600];
    }

    public function confirm(array $data, User $actor): array
    {
        $this->authorize($actor);
        $this->keys($data, ['token', 'source_ids', 'conflict', 'consent']);
        if (($data['consent'] ?? false) !== true) Input::fail('consent', '请确认评分将参与本站公开的匿名统计。');
        $token = $this->token($data);
        $conflict = Input::choice($data['conflict'] ?? 'skip', 'conflict', ['skip', 'replace']);
        $ids = $data['source_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 20) Input::fail('source_ids', '请选择一至二十条记录。');
        $ids = array_map(fn ($id) => Input::integer($id, 'source_ids', 1), $ids);
        if (count(array_unique($ids)) !== count($ids)) Input::fail('source_ids', '选择的记录不能重复。');
        $result = $this->db->transaction(function () use ($actor, $token, $ids, $conflict): array {
            $this->lockUser($actor);
            $draft = $this->db->table('acgn_collection_imports')->where('user_id', $actor->id)->where('token', $token)->lockForUpdate()->first();
            if (!$draft || !$draft->payload || !$draft->expires_at || $draft->expires_at <= gmdate('Y-m-d H:i:s')) Input::fail('token', '预览已过期、已使用或已替换，请重新预览。');
            $payload = json_decode($draft->payload, true, 64, JSON_THROW_ON_ERROR);
            $bySource = array_column($payload['items'], null, 'source_id');
            $selected = [];
            foreach ($ids as $id) {
                if (!isset($bySource[$id]) || !in_array($bySource[$id]['reason'], [null, 'missing'], true)) Input::fail('source_ids', '选择包含无法导入或未预览的条目。');
                $selected[] = $bySource[$id];
            }
            usort($selected, fn ($a, $b) => $a['source_id'] <=> $b['source_id']);
            $imported = $skipped = $requested = 0;
            foreach ($selected as $item) {
                if ($item['reason'] === 'missing' && !$this->db->table('acgn_subjects')->where(['source' => 'bangumi', 'source_id' => $item['source_id']])->exists()) {
                    if (!$this->requests) Input::fail('source_ids', '缺失条目申请服务不可用。');
                    $this->requests->submit($item, (int) $payload['account']['id'], $actor, $conflict);
                    $requested++; continue;
                }
                $status = (new CollectionRecord($this->db))->apply($item, (int) $actor->id, (int) $payload['account']['id'], $conflict);
                if ($status === 'unavailable') Input::fail('subject', '站内条目已变化，请重新预览。');
                if ($status === 'conflict') Input::fail('record', '个人记录在预览后已修改，请重新预览。');
                if ($status === 'skipped') $skipped++; else $imported++;
            }
            $this->clear($actor->id);
            return ['imported' => $imported, 'skipped' => $skipped, 'requested' => $requested];
        }, 3);
        if ($result['requested'] && $this->requests) $this->requests->bestEffort();
        return $result;
    }

    public function discard(array $data, User $actor): array
    {
        $this->authorize($actor);
        $this->keys($data, ['token']);
        $token = $this->token($data);
        $this->db->table('acgn_collection_imports')->where('user_id', $actor->id)->where('token', $token)->update(['token' => null, 'payload' => null, 'expires_at' => null]);
        return ['discarded' => true];
    }

    private function clear(int $userId): void
    {
        $this->db->table('acgn_collection_imports')->where('user_id', $userId)->update(['token' => null, 'payload' => null, 'expires_at' => null]);
    }

    private function authorize(User $actor): void
    {
        $actor->assertRegistered();
        foreach (['viewForum', 'acgn.view', 'acgn.collect', 'acgn.import_collection'] as $permission) $actor->assertCan($permission);
    }

    private function lockUser(User $actor): void
    {
        if (!$this->db->table('users')->where('id', $actor->id)->lockForUpdate()->first()) Input::fail('user', '账号不可用。');
    }

    private function token(array $data): string
    {
        $token = Input::text($data, 'token', 48);
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) Input::fail('token', '预览凭据无效。');
        return $token;
    }

    private function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) Input::fail('body', '请求包含不支持的字段。');
    }
}
