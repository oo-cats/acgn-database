<?php
declare(strict_types=1);

namespace Local\AcgnDatabase\Import;

use Illuminate\Database\ConnectionInterface;
use Local\AcgnDatabase\RecordActivity;

/** Caller locks the user first; the subject lock serializes all record writers. */
final class CollectionRecord
{
    public function __construct(private ConnectionInterface $db) {}

    public function apply(array $item, int $user, int $account, string $conflict): string
    {
        $subject = $this->db->table('acgn_subjects')->where(['source' => 'bangumi', 'source_id' => $item['source_id']])->lockForUpdate()->first();
        if (!$subject || $subject->archived || $subject->type !== 'anime' || (isset($item['subject_id']) && (int) $subject->id !== (int) $item['subject_id'])
            || ($subject->total_units !== null && $item['record']['progress'] > $subject->total_units)) return 'unavailable';
        $key = ['subject_id' => $subject->id, 'user_id' => $user];
        $existing = $this->db->table('acgn_records')->where($key)->first();
        if ($existing && $conflict === 'skip') return 'skipped';
        $hash = $existing ? hash('sha256', json_encode((array) $existing, JSON_THROW_ON_ERROR)) : null;
        if ($hash !== $item['record_hash']) return 'conflict';
        $now = gmdate('Y-m-d H:i:s');
        $fields = $item['record']; $fields['favorite'] = $existing ? (bool) $existing->favorite : false;
        $fields += RecordActivity::changes($fields, $existing, true);
        $fields += ['share_visibility' => 'self', 'share_score' => false, 'share_progress' => false,
            'revision' => $existing ? $existing->revision + 1 : 0, 'updated_at' => $now, 'imported_from' => 'bangumi',
            'imported_account' => $account, 'source_updated_at' => $item['source_updated_at'], 'imported_at' => $now];
        if ($existing) $this->db->table('acgn_records')->where($key)->update($fields);
        else $this->db->table('acgn_records')->insert($key + $fields + ['created_at' => $now]);
        return 'imported';
    }
}
