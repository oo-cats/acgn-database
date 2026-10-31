<?php
namespace Local\AcgnDatabase\Import;

final class ApprovalWorkCommand extends \Flarum\Console\AbstractCommand
{
    public function __construct(private ImportRequests $requests) { parent::__construct(); }
    protected function configure() { $this->setName('acgn:imports:work')->setDescription('Continue bounded ACGN approval delivery'); }
    protected function fire()
    {
        try { $result = $this->requests->work(); $this->info('['.gmdate('c').'] Processed '.$result['processed']); return 0; }
        catch (\Throwable) { $this->error('ACGN approval delivery failed; retry acgn:imports:work.'); return 1; }
    }
}
