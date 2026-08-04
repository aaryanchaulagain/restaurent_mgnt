<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Harmless staging/queue probe — no orders, payments, mail, or PII.
 */
class StagingQueueProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $probeToken) {}

    public function handle(): void
    {
        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', $this->probeToken) ?? '';
        if ($token === '' || strlen($token) > 64) {
            return;
        }

        Cache::put('staging:queue-probe:'.$token, 'processed', now()->addMinutes(10));
        Log::info('staging.queue_probe.processed', [
            'probe' => $token,
            // Never log secrets or business payloads.
        ]);
    }
}
