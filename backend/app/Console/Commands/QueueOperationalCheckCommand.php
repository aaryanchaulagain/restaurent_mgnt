<?php

namespace App\Console\Commands;

use App\Services\Operations\QueueOperationalCheckService;
use Illuminate\Console\Command;

class QueueOperationalCheckCommand extends Command
{
    protected $signature = 'queue:operational-check {--json : JSON output}';

    protected $description = 'Report queue driver, failed jobs, and worker requirements (no payloads)';

    public function handle(QueueOperationalCheckService $service): int
    {
        $result = $service->evaluate();
        $report = $result['report'];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return $result['exit_code'];
        }

        $this->info('Queue operational check');
        $this->line('Driver: '.$report['driver']);
        $this->line('Failed jobs: '.$report['failed_job_count']);
        $this->line('Oldest failed: '.($report['oldest_failed_at'] ?? 'n/a'));
        $this->line('Pending (database driver): '.($report['pending_count'] ?? 'n/a'));
        $this->line('Required queues: '.implode(', ', $report['required_worker_queues']));
        $this->line('Recommended: '.$report['recommended_worker']);
        foreach ($report['warnings'] as $w) {
            $this->warn($w);
        }
        foreach ($report['notes'] as $n) {
            $this->comment($n);
        }
        $this->line('Status: '.$result['status']);

        return $result['exit_code'];
    }
}
