<?php

namespace Modules\MailboxAutoDistributor\Console\Commands;

use Illuminate\Console\Command;
use Modules\MailboxAutoDistributor\Services\PendingProcessor;

class ProcessPendingAssignments extends Command
{
    protected $signature = 'mailboxautodistributor:process {--limit=50 : Max pending items to process per run}';
    protected $description = 'Process deferred mailbox auto-distributor assignments';

    public function handle(PendingProcessor $processor)
    {
        $limit = (int)$this->option('limit') ?: 50;
        $processed = $processor->processDue($limit);

        $this->info('Processed: ' . $processed);
        return 0;
    }
}
