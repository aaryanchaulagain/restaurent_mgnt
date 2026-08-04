<?php

namespace App\Console\Commands;

use App\Services\Operations\BackupReadinessService;
use Illuminate\Console\Command;

class BackupReadinessCommand extends Command
{
    protected $signature = 'backup:readiness {--json : JSON output}';

    protected $description = 'Check backup destination readiness (does not dump databases)';

    public function handle(BackupReadinessService $service): int
    {
        $result = $service->evaluate();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return $result['exit_code'];
        }

        $report = $result['report'];
        $this->info('Backup readiness');
        $this->line('Database driver: '.$report['database_driver']);
        $this->line('Suggested tool: '.$report['database_backup_tool']);
        $this->line('Media: '.$report['media_backup']);
        $this->line('Temp writable: '.($report['temp_dir_writable'] ? 'yes' : 'no'));
        foreach ($report['warnings'] as $w) {
            $this->warn($w);
        }
        foreach ($report['failures'] as $f) {
            $this->error($f);
        }
        foreach ($report['notes'] as $n) {
            $this->comment($n);
        }
        $this->line('Status: '.$result['status']);

        return $result['exit_code'];
    }
}
