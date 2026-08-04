<?php

namespace App\Console\Commands;

use App\Services\Operations\ProductionReadinessService;
use Illuminate\Console\Command;

class ProductionReadinessCommand extends Command
{
    protected $signature = 'app:production-readiness {--env= : Evaluate as if APP_ENV were this value} {--json : JSON output}';

    protected $description = 'Validate production configuration (read-only; never prints secret values)';

    public function handle(ProductionReadinessService $service): int
    {
        $report = $service->evaluate($this->option('env') ? (string) $this->option('env') : null);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return $report['exit_code'];
        }

        $this->info('Production readiness (secrets never printed)');
        foreach ($report['results'] as $row) {
            $line = "{$row['status']} {$row['key']} — {$row['message']}";
            match ($row['status']) {
                'FAIL' => $this->error($line),
                'WARN' => $this->warn($line),
                default => $this->line($line),
            };
        }

        $this->newLine();
        $this->line('Overall: '.$report['status']);

        return $report['exit_code'];
    }
}
