<?php

declare(strict_types=1);

namespace Local\AcgnDatabase\Profile;

use Flarum\User\{User, UserRepository};
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Local\AcgnDatabase\Input;
use Local\AcgnDatabase\RecordActivity;

/** Shared HTTP/PHP read boundary. Consumers never need to read private ACGN tables. */
final class RecordShowcase
{
    private const SCOPES = ['self', 'members', 'public'];

    public function __construct(private ConnectionInterface $db, private UserRepository $users) {}

    public function forViewer(User $viewer, int $ownerId, string $kind, int $limit = 6, bool $withProgress = false): array
    {
        $this->readable($viewer);
        Input::integer($ownerId, 'user', 1);
        Input::choice($kind, 'kind', ['watching', 'ratings']);
        Input::integer($limit, 'limit', 1, 6);
        $this->users->findOrFail($ownerId, $viewer);
        $own = !$viewer->isGuest() && (int) $viewer->id === $ownerId;
        $scopes = ['public'];
        if (!$viewer->isGuest() && (bool) $viewer->is_email_confirmed) $scopes[] = 'members';
        $settings = $this->db->table('acgn_showcase_settings')->where('user_id', $ownerId)->first();
        $scope = $settings->{$kind === 'watching' ? 'watching_visibility' : 'ratings_visibility'} ?? 'self';
        $empty = ['schemaVersion' => 1, 'kind' => $kind, 'items' => []];
        if (!$own && !in_array($scope, $scopes, true)) return $empty;
        $query = $this->db->table('acgn_records as r')->join('acgn_subjects as s', 's.id', '=', 'r.subject_id')
            ->where('r.user_id', $ownerId)->where('s.archived', false);
        $clock = $kind === 'watching' ? 'watch_updated_at' : 'rated_at';
        $query->whereNotNull('r.'.$clock);
        if ($kind === 'watching') $query->where('s.type', 'anime')->whereIn('r.status', ['current', 'completed']);
        else $query->whereBetween('r.score', [1, 10]);
        if (!$own) {
            $query->whereIn('r.share_visibility', $scopes);
            if ($kind === 'ratings') $query->where('r.share_score', true);
        }
        $rows = $query->select(['s.id', 's.type', 's.title', 'r.status', 'r.score', 'r.progress', 'r.share_progress'])
            ->orderByDesc('r.'.$clock)->orderByDesc('r.subject_id')->limit($limit)->get();
        $items = [];
        foreach ($rows as $row) {
            $item = ['subjectId' => (int) $row->id, 'type' => $row->type, 'title' => $row->title, 'status' => $row->status];
            if ($kind === 'ratings') $item['score'] = (int) $row->score;
            if ($withProgress && ($own || $row->share_progress)) $item['progress'] = (int) $row->progress;
            $items[] = $item;
        }
        return ['schemaVersion' => 1, 'kind' => $kind, 'items' => $items];
    }

    public function settings(User $actor): array
    {
        $this->writable($actor);
        $this->users->findOrFail($actor->id, $actor);
        return $this->settingsData($this->db->table('acgn_showcase_settings')->where('user_id', $actor->id)->first());
    }

    public function saveSettings(array $data, User $actor): array
    {
        $this->writable($actor);
        $this->keys($data, ['revision', 'watching_visibility', 'ratings_visibility']);
        $revision = Input::integer($data['revision'] ?? null, 'revision', 0);
        $fields = [];
        foreach (['watching_visibility', 'ratings_visibility'] as $key) $fields[$key] = Input::choice($data[$key] ?? null, $key, self::SCOPES);
        return $this->db->transaction(function () use ($actor, $revision, $fields): array {
            if (!$this->db->table('users')->where('id', $actor->id)->lockForUpdate()->first()) throw new ModelNotFoundException();
            $row = $this->db->table('acgn_showcase_settings')->where('user_id', $actor->id)->first();
            if ($revision !== (int) ($row->revision ?? 0)) Input::fail('revision', '展柜设置已修改，请重新读取。');
            $now = gmdate('Y-m-d H:i:s');
            $values = $fields + ['revision' => $revision + 1, 'updated_at' => $now];
            if ($row) $this->db->table('acgn_showcase_settings')->where('user_id', $actor->id)->update($values);
            else $this->db->table('acgn_showcase_settings')->insert($values + ['user_id' => $actor->id, 'created_at' => $now]);
            return $this->settingsData((object) $values);
        }, 3);
    }

    public function shareRecord(int $subjectId, array $data, User $actor): array
    {
        $this->writable($actor);
        $this->keys($data, ['revision', 'visibility', 'share_score', 'share_progress']);
        $revision = Input::integer($data['revision'] ?? null, 'revision', 0);
        $visibility = Input::choice($data['visibility'] ?? null, 'visibility', self::SCOPES);
        $fields = ['share_visibility' => $visibility, 'share_score' => Input::boolean($data['share_score'] ?? null, 'share_score'),
            'share_progress' => Input::boolean($data['share_progress'] ?? null, 'share_progress')];
        return $this->db->transaction(function () use ($subjectId, $actor, $revision, $fields): array {
            $subject = $this->db->table('acgn_subjects')->where('id', $subjectId)->lockForUpdate()->first();
            $key = ['subject_id' => $subjectId, 'user_id' => $actor->id];
            $record = $this->db->table('acgn_records')->where($key)->first();
            if (!$subject || !$record) throw new ModelNotFoundException();
            if ($subject->archived && $fields['share_visibility'] !== 'self') Input::fail('visibility', '归档作品只能撤回分享。');
            if ((int) $record->revision !== $revision) Input::fail('revision', '记录已修改，请重新读取。');
            $this->db->table('acgn_records')->where($key)->update($fields + ['revision' => $revision + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            return ['visibility' => $fields['share_visibility'], 'share_score' => $fields['share_score'],
                'share_progress' => $fields['share_progress'], 'revision' => $revision + 1];
        }, 3);
    }

    /** Explicitly record another viewing/rating without fabricating a value change. */
    public function recordActivity(int $subjectId, array $data, User $actor): array
    {
        $this->writable($actor);
        $this->keys($data, ['revision', 'kind']);
        $revision = Input::integer($data['revision'] ?? null, 'revision', 0);
        $kind = Input::choice($data['kind'] ?? null, 'kind', ['watching', 'ratings']);
        return $this->db->transaction(function () use ($subjectId, $actor, $revision, $kind): array {
            $subject = $this->db->table('acgn_subjects')->where('id', $subjectId)->lockForUpdate()->first();
            $key = ['subject_id' => $subjectId, 'user_id' => $actor->id];
            $record = $this->db->table('acgn_records')->where($key)->first();
            if (!$subject || !$record) throw new ModelNotFoundException();
            if ($subject->archived) Input::fail('subject', '归档作品不能记录新活动。');
            if ((int) $record->revision !== $revision) Input::fail('revision', '记录已修改，请重新读取。');
            if ($kind === 'watching' && ($subject->type !== 'anime' || !in_array($record->status, ['current', 'completed'], true))) Input::fail('kind', '仅能为正在看或已看完的动画记录观看活动。');
            if ($kind === 'ratings' && ($record->score === null || $record->score < 1 || $record->score > 10)) Input::fail('kind', '请先保存有效评分。');
            $now = RecordActivity::now();
            $clock = $kind === 'watching' ? 'watch_updated_at' : 'rated_at';
            $this->db->table('acgn_records')->where($key)->update([$clock => $now, 'revision' => $revision + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            return ['kind' => $kind, 'recorded_at' => str_replace(' ', 'T', $now).'Z', 'revision' => $revision + 1];
        }, 3);
    }

    private function readable(User $actor): void
    {
        $actor->assertCan('viewForum');
        $actor->assertCan('acgn.view');
    }

    private function writable(User $actor): void
    {
        $actor->assertRegistered();
        $this->readable($actor);
        $actor->assertCan('acgn.collect');
    }

    private function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) Input::fail('body', '请求包含不支持的字段。');
    }

    private function settingsData(?object $row): array
    {
        return ['watching_visibility' => $row->watching_visibility ?? 'self', 'ratings_visibility' => $row->ratings_visibility ?? 'self',
            'revision' => (int) ($row->revision ?? 0)];
    }
}
