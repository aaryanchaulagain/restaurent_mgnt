<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ReleaseIdentifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class HealthReadyController extends Controller
{
    /** Readiness — safe dependency probes; no secrets or hosts. */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->okOrFail(fn () => DB::select('select 1')),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
        ];

        $ok = ! in_array('fail', $checks, true);
        $payload = array_merge([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
        ], ReleaseIdentifier::forHealth());

        return response()
            ->json($payload, $ok ? 200 : 503)
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
            $key = 'health:ready:'.bin2hex(random_bytes(8));
            Cache::put($key, '1', 5);
            $ok = Cache::pull($key) === '1';

            return $ok ? 'ok' : 'fail';
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
