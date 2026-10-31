<?php
declare(strict_types=1);

namespace Local\AcgnDatabase\Facts;

use Illuminate\Database\ConnectionInterface;
use Local\AcgnDatabase\Event\{FactsChangingV1, FactsChangedV1};

final class FactsMutation
{
    public function __construct(private ConnectionInterface $db) {}

    public function run(string $source, int $user, array $subjects, array $discussions, string $kind, callable $work): mixed
    {
        return $this->db->transaction(function () use ($source, $user, $subjects, $discussions, $kind, $work) {
            $subjects = array_values(array_unique(array_map('intval', $subjects))); sort($subjects, SORT_NUMERIC);
            $discussions = array_values(array_unique(array_map('intval', $discussions))); sort($discussions, SORT_NUMERIC);
            $id = bin2hex(random_bytes(16));
            $dispatcher = $this->db->getEventDispatcher();
            $dispatcher?->dispatch(new FactsChangingV1($this->db, $source, $user, $subjects, $discussions, $kind, $id));
            $result = $work();
            $dispatcher?->dispatch(new FactsChangedV1($this->db, $source, $user, $subjects, $discussions, $kind, $id));
            return $result;
        }, $this->db->transactionLevel() ? 1 : 3);
    }
}
