<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only queue operational status. Never exposes job payloads.
 */
class QueueOperationalCheckService
{
    /**
     * @return array{status: string, exit_code: int, report: array<string, mixed>}
     */
    public function evaluate(): array
    {
        $driver = (string) config('queue.default');
        $failedCount = 0;
        $oldestFailedAt = null;
        $pending = null;

        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
                $failedCount = (int) DB::table('failed_jobs')->count();
                $oldest = DB::table('failed_jobs')->orderBy('failed_at')->value('failed_at');
                $oldestFailedAt = $oldest ? (string) $oldest : null;
            }
        } catch (Throwable) {
            // ignore
        }

        try {
            if ($driver === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                $pending = (int) DB::table('jobs')->count();
            }
        } catch (Throwable) {
            $pending = null;
        }

        $requiredQueues = ['default', 'notifications'];
        $warnings = [];
        $fails = [];

        if (in_array($driver, ['sync', 'null'], true)) {
            $warnings[] = 'Queue driver is '.$driver.' — unsafe for production email/notification load.';
        }

        $report = [
            'driver' => $driver,
            'failed_job_count' => $failedCount,
            'oldest_failed_at' => $oldestFailedAt,
            'pending_count' => $pending,
            'required_worker_queues' => $requiredQueues,
            'recommended_worker' => 'php artisan queue:work --queue=notifications,default --sleep=3 --tries=3 --timeout=120',
            'notes' => [
                'Notifications implement ShouldQueue and must be processed by a worker in production.',
                'Order placement and inventory reservation remain synchronous — do not move them to queues.',
                'Failed job payloads are never printed by this command.',
                'Configure Supervisor/systemd separately; this check does not install process managers.',
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
