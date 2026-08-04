<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ReleaseIdentifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Legacy health entrypoint — returns a minimal safe payload (no env/driver/credentials).
 * Prefer /api/health/live and /api/health/ready for probes.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->okOrFail(fn () => DB::select('select 1')),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
        ];

        $ok = ! in_array('fail', $checks, true);

        return response()
            ->json(array_merge([
                'status' => $ok ? 'ok' : 'degraded',
                'checks' => $checks,
            ], ReleaseIdentifier::forHealth()), $ok ? 200 : 503)
            ->header('X-Request-Id', (string) request()->attributes->get('request_id', ''));
    }

    private function okOrFail(callable $fn): string
    {
        try {
            $fn();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }

    private function checkCache(): string
    {
        try {
            $key = 'health:legacy:'.bin2hex(random_bytes(8));
            Cache::put($key, '1', 5);

            return Cache::pull($key) === '1' ? 'ok' : 'fail';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }

    private function checkStorage(): string
    {
        try {
            $path = storage_path('app');

            return (File::isDirectory($path) && File::isWritable($path)) ? 'ok' : 'fail';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
