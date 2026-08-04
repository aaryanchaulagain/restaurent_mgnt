<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Backup destination readiness — does not dump databases.
 */
class BackupReadinessService
{
    /**
     * @return array{status: string, exit_code: int, report: array<string, mixed>}
     */
    public function evaluate(): array
    {
        $warnings = [];
        $fails = [];

        $tmp = storage_path('app/backup-tmp');
        try {
            if (! File::isDirectory($tmp)) {
                File::makeDirectory($tmp, 0755, true);
            }
            $writable = File::isWritable($tmp);
        } catch (Throwable) {
            $writable = false;
        }

        if (! $writable) {
            $fails[] = 'Temporary backup directory not writable.';
        }

        $driver = (string) config('database.default');
        $supported = in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true);
        if (! $supported) {
            $warnings[] = 'Database driver may need a custom backup tool.';
        }

        $disk = (string) config('filesystems.default');
        $diskOk = filled($disk);

        $report = [
            'database_driver' => $driver,
            'database_backup_tool' => match ($driver) {
                'mysql', 'mariadb' => 'mysqldump --single-transaction (use defaults-extra-file for credentials)',
                'pgsql' => 'pg_dump (use .pgpass or env vars — never inline passwords)',
                'sqlite' => 'Copy the sqlite file after brief maintenance if needed',
                default => 'Use infrastructure-native backup',
            },
            'media_backup' => 'Back up storage/app (private docs) and public disk / object storage bucket separately.',
            'temp_dir_writable' => $writable,
            'filesystem_disk_configured' => $diskOk,
            'last_backup_metadata' => null,
            'notes' => [
                'This command does not create backups.',
                'Never place DB passwords on the shell command line.',
                'Encrypt off-site backups and restrict access.',
                'Restore only into isolated non-production environments first.',
            ],
            'warnings' => $warnings,
            'failures' => $fails,
        ];

        $status = $fails !== [] ? 'FAIL' : ($warnings !== [] ? 'WARN' : 'PASS');

        return [
            'status' => $status,
            'exit_code' => $fails !== [] ? 1 : 0,
            'report' => $report,
        ];
    }
}
