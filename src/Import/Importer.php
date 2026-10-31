<?php

declare(strict_types=1);

namespace Local\AcgnDatabase\Import;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\AcgnDatabase\{Catalog, Input};

final class Importer
{
    public function __construct(private ConnectionInterface $db, private Bangumi $bangumi, private Catalog $catalog) {}

    public function preview(array $data, User $actor): array
    {
        $actor->assertRegistered();
        $actor->assertCan('acgn.import');
        $type = Input::choice($data['type'] ?? null, 'type', Input::TYPES);
        $id = Bangumi::subjectId(Input::text($data, 'subject', 300));
        $payload = $this->bangumi->preview($id, $type);
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
        $actor->assertRegistered();
        $actor->assertCan('acgn.import');
        $token = Input::text($data, 'token', 48);
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) Input::fail('token', '预览凭据无效，请重新预览。');
        $id = $this->db->transaction(function () use ($token, $actor): int {
            $draft = $this->db->table('acgn_imports')->where('token', $token)->where('user_id', $actor->id)->lockForUpdate()->first();
            if (!$draft || $draft->expires_at <= gmdate('Y-m-d H:i:s')) Input::fail('token', '预览已过期或已使用，请重新预览。');
            $payload = json_decode($draft->payload, true, 64, JSON_THROW_ON_ERROR);
            $sourceKey = ['source' => 'bangumi', 'source_id' => $payload['source_id']];
            $subject = $payload['subject'];
            $now = gmdate('Y-m-d H:i:s');
            $subject['imported_at'] = $now;
            $id = $this->merge('acgn_subjects', $sourceKey, $subject);
            $personIds = [];
            // Deterministic lock order prevents opposite-order imports from deadlocking.
            $people = $payload['people'];
            usort($people, fn ($a, $b) => [$a['kind'], $a['source_id']] <=> [$b['kind'], $b['source_id']]);
            foreach ($people as $person) {
                $key = $person['kind'].':'.$person['source_id'];
                $personIds[$key] = $this->merge('acgn_people', ['source' => 'bangumi', 'source_id' => $person['source_id'], 'kind' => $person['kind']], $person);
            }
            foreach ($payload['credits'] as $credit) $this->db->table('acgn_credits')->insertOrIgnore(['subject_id' => $id, 'person_id' => $personIds[$credit['key']], 'role' => $credit['role']]);
            $this->db->table('acgn_imports')->where('token', $token)->delete();
            return $id;
        }, 3);
        return $this->catalog->detail($id, $actor);
    }

    private function merge(string $table, array $key, array $fields): int
    {
        foreach (['aliases', 'tags'] as $field) if (isset($fields[$field])) $fields[$field] = json_encode($fields[$field], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table($table)->insertOrIgnore($key + $fields + ['created_at' => $now, 'updated_at' => $now]);
        $row = $this->db->table($table)->where($key)->lockForUpdate()->first();
        if (!$row || $row->archived) Input::fail('source', '来源对应资料已归档，请管理员先恢复。');
        if ($table === 'acgn_subjects' && $row->type !== $fields['type']) Input::fail('type', '该来源条目已存在，且本地分类不同，请先编辑既有条目。');
        $changes = [];
        foreach ($fields as $field => $value) {
            // Identity and classification are immutable during import; manual nonempty data wins.
            if (in_array($field, ['kind', 'source_id', 'type'], true)) continue;
            if ($field === 'total_units' && $value !== null && $this->db->table('acgn_records')->where('subject_id', $row->id)->max('progress') > $value) continue;
            if (in_array($field, ['source_score', 'imported_at'], true) || $row->$field === '' || $row->$field === null || $row->$field === '[]') $changes[$field] = $value;
        }
        if ($changes) $this->db->table($table)->where('id', $row->id)->update($changes + ['revision' => $row->revision + 1, 'updated_at' => $now]);
        return (int) $row->id;
    }
}
