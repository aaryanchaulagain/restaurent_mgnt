<?php

namespace App\Console\Commands;

use App\Services\Operations\SmokeTestService;
use Illuminate\Console\Command;

class AppSmokeTestCommand extends Command
{
    protected $signature = 'app:smoke-test {--json : JSON output}';

    protected $description = 'Non-destructive production smoke checks (no orders, payments, emails, or stock changes)';

    public function handle(SmokeTestService $service): int
    {
        $report = $service->run();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return $report['exit_code'];
        }

        $this->info('Application smoke test (read-only)');
        foreach ($report['results'] as $row) {
            $line = "{$row['status']} {$row['key']} — {$row['message']}";
            match ($row['status']) {
                'FAIL' => $this->error($line),
                'WARN' => $this->warn($line),
                default => $this->line($line),
            };
        }
        $this->newLine();
        $this->comment('Guarantees: no orders, payments, inventory writes, or emails.');
        $this->line('Overall: '.$report['status']);

        return $report['exit_code'];
    }
}
