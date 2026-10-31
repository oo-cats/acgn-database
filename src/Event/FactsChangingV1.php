<?php
declare(strict_types=1);

namespace Local\AcgnDatabase\Event;

use Illuminate\Database\ConnectionInterface;

/** Synchronous, transaction-local metadata; listeners must use this PDO. */
class FactsChangingV1
{
    public readonly \PDO $pdo;
    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $sourceKey,
        public readonly int $userId,
        public readonly array $subjectIds,
        public readonly array $discussionIds,
        public readonly string $kind,
        public readonly string $changeId,
    ) {
        $this->pdo = $connection->getPdo();
        if (!$this->pdo->inTransaction()) throw new \LogicException('ACGN facts events require the source transaction.');
    }
}
