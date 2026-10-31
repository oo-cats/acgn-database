<?php
declare(strict_types=1);

namespace Local\AcgnDatabase\Facts;

use Illuminate\Database\ConnectionInterface;
use Local\AcgnDatabase\RecordActivity;

final class FirstCompletion
{
    public function __construct(private ConnectionInterface $db) {}

    /** Call inside the writer's transaction, after serializing the record. */
    public function observe(int $user, int $subject, ?object $before, ?string $after, ?int $actor): void
    {
        // Existing installations stay usable until their administrator applies 006.
        if (!$this->db->getSchemaBuilder()->hasTable('acgn_first_completions')) return;
        if (!$this->db->getPdo()->inTransaction()) throw new \LogicException('First completion requires the record transaction.');
        $origin = $before && $before->status === 'completed' ? 'import' : ($after === 'completed' ? ($actor === $user ? 'self' : 'import') : null);
        if ($origin === null) return;
        $this->db->table('acgn_first_completions')->insertOrIgnore(['user_id' => $user, 'subject_id' => $subject,
            'observed_at' => RecordActivity::now(), 'origin' => $origin]);
    }
}
