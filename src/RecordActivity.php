<?php

declare(strict_types=1);

namespace Local\AcgnDatabase;

final class RecordActivity
{
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    /** Activity clocks must not advance for comments, favorites, sharing or imports. */
    public static function changes(array $fields, ?object $existing, bool $imported = false, string $type = 'anime'): array
    {
        $now = self::now();
        $watching = $type === 'anime' && in_array($fields['status'], ['current', 'completed'], true);
        $watchChanged = !$existing || $fields['status'] !== $existing->status || $fields['progress'] !== (int) $existing->progress;
        $oldScore = $existing && $existing->score !== null ? (int) $existing->score : null;
        $scoreChanged = !$existing || $fields['score'] !== $oldScore;
        return [
            'watch_updated_at' => !$watching ? null : ($watchChanged ? ($imported ? null : $now) : ($existing->watch_updated_at ?? null)),
            'rated_at' => $fields['score'] === null ? null : ($scoreChanged ? ($imported ? null : $now) : ($existing->rated_at ?? null)),
        ];
    }
}
