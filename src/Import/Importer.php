<?php

declare(strict_types=1);

namespace Local\AcgnDatabase\Import;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\AcgnDatabase\{Catalog, Input};
use Local\AcgnDatabase\Facts\FactsMutation;

final class Importer
{
    public function __construct(private ConnectionInterface $db, private Bangumi $bangumi, private Catalog $catalog) {}

    public function preview(array $data, User $actor): array
    {
        $actor->assertRegistered();
        $actor->assertCan('viewForum'); $actor->assertCan('acgn.view');
        $actor->assertCan('acgn.create');
        $type = Input::choice($data['type'] ?? null, 'type', Input::TYPES);
        $id = Bangumi::subjectId(Input::text($data, 'subject', 300));
        $payload = $this->bangumi->preview($id, $type);
        $local = $this->db->table('acgn_subjects')->where(['source' => 'bangumi', 'source_id' => $id])->first();
        $payload['local_id'] = $local ? (int) $local->id : null;
        $payload['local_revision'] = $local ? (int) $local->revision : null;
        $now = gmdate('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(24));
        $this->db->transaction(function () use ($payload, $now, $token, $actor): void {
            $this->db->table('acgn_imports')->where('expires_at', '<=', $now)->delete();
            $this->db->table('acgn_imports')->where('user_id', $actor->id)->delete();
            $this->db->table('acgn_imports')->insert(['token' => $token, 'user_id' => $actor->id,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 600)]);
        });
        return $payload + ['token' => $token, 'expires_in' => 600];
    }

    public function confirm(array $data, User $actor): array
    {
        return $this->catalog->detail($this->consume($data, $actor), $actor);
    }

    /** Consume a server-held preview; approval may only create or reuse a subject. */
    public function consume(array $data, User $actor, ?int $sourceId = null, bool $createOnly = false): int
    {
        $actor->assertRegistered();
        $actor->assertCan('viewForum'); $actor->assertCan('acgn.view');
        $actor->assertCan('acgn.create');
        $token = Input::text($data, 'token', 48);
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) Input::fail('token', '预览凭据无效，请重新预览。');
        return (new FactsMutation($this->db))->run('acgn-catalog', 0, [], [], 'subject.import', function () use ($token, $actor, $sourceId, $createOnly): int {
            $draft = $this->db->table('acgn_imports')->where('token', $token)->where('user_id', $actor->id)->lockForUpdate()->first();
            if (!$draft || $draft->expires_at <= gmdate('Y-m-d H:i:s')) Input::fail('token', '预览已过期或已使用，请重新预览。');
            $payload = json_decode($draft->payload, true, 64, JSON_THROW_ON_ERROR);
            if ($sourceId !== null && ((int) $payload['source_id'] !== $sourceId || $payload['subject']['type'] !== 'anime')) Input::fail('token', '预览与申请作品不匹配。');
            $sourceKey = ['source' => 'bangumi', 'source_id' => $payload['source_id']];
            $subject = $payload['subject'];
            unset($subject['source_score']); // Also reject aggregate ratings in pre-upgrade snapshots.
            $existing = $this->db->table('acgn_subjects')->where($sourceKey)->lockForUpdate()->first();
            if ($existing && ($existing->archived || $existing->type !== $subject['type'])) Input::fail('subject', '既有条目已归档或类型不匹配。');
            $update = $existing && !$createOnly && $actor->can('acgn.update') && ($payload['local_id'] ?? null) === (int) $existing->id;
            if ($existing && !$update) {
                $this->db->table('acgn_imports')->where('token', $token)->delete();
                return (int) $existing->id;
            }
            if ($existing && ($payload['local_revision'] ?? null) !== (int) $existing->revision) Input::fail('revision', '条目在预览后已修改，请重新预览。');
            $now = gmdate('Y-m-d H:i:s');
            $subject['imported_at'] = $now;
            [$id, $created] = $this->merge('acgn_subjects', $sourceKey, $subject, (bool) $update);
            if (!$created && !$update) {
                $this->db->table('acgn_imports')->where('token', $token)->delete();
                return $id;
            }
            $personIds = [];
            // Deterministic lock order prevents opposite-order imports from deadlocking.
            $people = $payload['people'];
            usort($people, fn ($a, $b) => [$a['kind'], $a['source_id']] <=> [$b['kind'], $b['source_id']]);
            foreach ($people as $person) {
                $key = $person['kind'].':'.$person['source_id'];
                $personIds[$key] = $this->merge('acgn_people', ['source' => 'bangumi', 'source_id' => $person['source_id'], 'kind' => $person['kind']], $person, $actor->can('acgn.update') && !$createOnly)[0];
            }
            foreach ($payload['credits'] as $credit) $this->db->table('acgn_credits')->insertOrIgnore(['subject_id' => $id, 'person_id' => $personIds[$credit['key']], 'role' => $credit['role']]);
            $this->db->table('acgn_imports')->where('token', $token)->delete();
            return $id;
        });
    }

    private function merge(string $table, array $key, array $fields, bool $update): array
    {
        foreach (['aliases', 'tags'] as $field) if (isset($fields[$field])) $fields[$field] = json_encode($fields[$field], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $this->db->table($table)->insertOrIgnore($key + $fields + ['created_at' => $now, 'updated_at' => $now]);
        $row = $this->db->table($table)->where($key)->lockForUpdate()->first();
        if (!$row || $row->archived) Input::fail('source', '来源对应资料已归档，请管理员先恢复。');
        if ($table === 'acgn_subjects' && $row->type !== $fields['type']) Input::fail('type', '该来源条目已存在，且本地分类不同，请先编辑既有条目。');
        if (!$inserted && !$update) return [(int) $row->id, false];
        $changes = [];
        foreach ($fields as $field => $value) {
            // Identity and classification are immutable during import; manual nonempty data wins.
            if (in_array($field, ['kind', 'source_id', 'type'], true)) continue;
            if ($field === 'total_units' && $value !== null && $this->db->table('acgn_records')->where('subject_id', $row->id)->max('progress') > $value) continue;
            if ($field === 'imported_at' || $row->$field === '' || $row->$field === null || $row->$field === '[]') $changes[$field] = $value;
        }
        if ($changes) $this->db->table($table)->where('id', $row->id)->update($changes + ['revision' => $row->revision + 1, 'updated_at' => $now]);
        return [(int) $row->id, (bool) $inserted];
    }
}
