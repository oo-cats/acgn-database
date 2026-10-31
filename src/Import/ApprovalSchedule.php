<?php
namespace Local\AcgnDatabase\Import;

use Flarum\Foundation\Paths;
use Illuminate\Console\Scheduling\Event;
use Psr\Log\LoggerInterface;

final class ApprovalSchedule
{
    public function __construct(private Paths $paths, private LoggerInterface $logger) {}
    public function __invoke(Event $event): void
    {
        $event->everyMinute()->withoutOverlapping(5)->sendOutputTo($this->paths->storage.'/logs/acgn-imports.log')
            ->onFailure(function (): void { $this->logger->warning('ACGN approval delivery failed; inspect acgn-imports.log.'); });
    }
}
