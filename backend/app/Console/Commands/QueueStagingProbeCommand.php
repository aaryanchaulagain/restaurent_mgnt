<?php

namespace App\Console\Commands;

use App\Jobs\StagingQueueProbeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class QueueStagingProbeCommand extends Command
{
    protected $signature = 'queue:staging-probe
        {--wait=0 : Seconds to wait for worker to process (0 = dispatch only)}
        {--json : JSON output}';

    protected $description = 'Dispatch a harmless staging queue probe (no business writes or email)';

    public function handle(): int
    {
        $env = (string) config('app.env');
        if (in_array($env, ['production', 'prod'], true)) {
            $this->error('Refusing to run queue:staging-probe in production.');

            return self::FAILURE;
        }

        $token = (string) Str::uuid();
        StagingQueueProbeJob::dispatch($token)->onQueue('default');

        $wait = max(0, (int) $this->option('wait'));
        $processed = false;
        if ($wait > 0) {
            $deadline = microtime(true) + $wait;
            while (microtime(true) < $deadline) {
                if (Cache::get('staging:queue-probe:'.$token) === 'processed') {
                    $processed = true;
                    break;
                }
                usleep(200_000);
            }
        }

        $report = [
            'dispatched' => true,
            'probe_token' => $token,
            'queue' => 'default',
            'driver' => config('queue.default'),
            'waited_seconds' => $wait,
            'processed' => $processed,
            'notes' => [
                'Payload contains only a random probe token — no PII or payment data.',
                'If --wait > 0 and processed=false, ensure a queue worker is running.',
                'This command refuses to run when APP_ENV is production.',
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode(['status' => $processed || $wait === 0 ? 'PASS' : 'WARN', 'report' => $report], JSON_PRETTY_PRINT));
        } else {
            $this->info('Staging queue probe dispatched.');
            $this->line('Token: '.$token);
            $this->line('Driver: '.$report['driver']);
            if ($wait > 0) {
                $this->line('Processed: '.($processed ? 'yes' : 'no'));
            } else {
                $this->comment('Dispatch-only. Run a worker, then: php artisan queue:staging-probe --wait=15');
            }
        }

        return ($wait > 0 && ! $processed) ? self::FAILURE : self::SUCCESS;
    }
}
