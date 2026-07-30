<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['ok'] === true);

        $payload = [
            'service' => 'suvakamana-api',
            'version' => 'v1',
            'environment' => config('app.env'),
            'database_driver' => config('database.default'),
            'cache_store' => config('cache.default'),
            'queue_connection' => config('queue.default'),
            'session_driver' => config('session.driver'),
            'checks' => $checks,
        ];

        if ($healthy) {
            return ApiResponse::success(
                data: $payload,
                message: 'Suvakamana API is healthy.',
            );
        }

        return ApiResponse::error(
            message: 'Suvakamana API is degraded.',
            status: 503,
            data: $payload,
        );
    }

    /**
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            $charset = DB::selectOne('select @@character_set_database as charset, @@collation_database as collation');

            return [
                'ok' => true,
                'message' => 'MySQL connection successful.',
                'details' => [
                    'charset' => $charset->charset ?? null,
                    'collation' => $charset->collation ?? null,
                ],
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'message' => 'Database connection failed.',
            ];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function checkCache(): array
    {
        try {
            $key = 'suvakamana:health:'.uniqid('', true);
            Cache::put($key, 'ok', 10);
            $value = Cache::pull($key);

            if ($value !== 'ok') {
                return [
                    'ok' => false,
                    'message' => 'Cache store read/write failed.',
                ];
            }

            return [
                'ok' => true,
                'message' => 'Cache store ('.config('cache.default').') working.',
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'message' => 'Cache store check failed.',
            ];
        }
    }
}
