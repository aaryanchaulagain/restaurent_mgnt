<?php

namespace App\Services\Operations;

use App\Support\StripeKeyMode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only production/staging configuration validator.
 * Never prints environment-variable values.
 */
class ProductionReadinessService
{
    /**
     * @return array{status: string, exit_code: int, results: list<array{status: string, key: string, message: string}>}
     */
    public function evaluate(?string $forceEnv = null): array
    {
        $env = $forceEnv ?? (string) config('app.env', 'production');
        $isProd = in_array($env, ['production', 'prod'], true);
        $isStaging = $env === 'staging';
        $isHardened = $isProd || $isStaging;
        $results = [];

        $results[] = $this->check(
            'APP_ENV',
            in_array($env, ['local', 'testing', 'staging', 'production', 'prod'], true),
            'Application environment is set.',
            'APP_ENV is missing or unusual.',
            warn: ! $isHardened && $env === 'local',
            warnMessage: 'APP_ENV is local — expected for development only.',
        );

        $debug = (bool) config('app.debug');
        if ($isHardened && $debug) {
            $results[] = $this->fail('APP_DEBUG', 'APP_DEBUG must be false in '.$env.'.');
        } else {
            $results[] = $this->pass('APP_DEBUG', $debug ? 'Debug enabled (non-production).' : 'Debug disabled.');
        }

        $key = (string) config('app.key');
        $results[] = $key !== ''
            ? $this->pass('APP_KEY', 'Application key is configured.')
            : $this->fail('APP_KEY', 'APP_KEY is required.');

        $url = (string) config('app.url');
        if ($isHardened) {
            $https = str_starts_with(strtolower($url), 'https://');
            $results[] = $https
                ? $this->pass('APP_URL', 'APP_URL uses HTTPS.')
                : $this->fail('APP_URL', 'APP_URL must be HTTPS in '.$env.'.');
        } else {
            $results[] = $url !== ''
                ? $this->pass('APP_URL', 'APP_URL is configured.')
                : $this->fail('APP_URL', 'APP_URL is required.');
        }

        $frontendConfigured = filled(env('FRONTEND_URL'));
        $results[] = $frontendConfigured
            ? $this->pass('FRONTEND_URL', 'Frontend URL is configured.')
            : $this->fail('FRONTEND_URL', 'FRONTEND_URL is required for Sanctum/CORS.');

        $db = config('database.default');
        $results[] = filled($db)
            ? $this->pass('DB_CONNECTION', 'Database connection is configured.')
            : $this->fail('DB_CONNECTION', 'Database connection is missing.');

        try {
            DB::connection()->getPdo();
            $results[] = $this->pass('DATABASE_REACHABLE', 'Database is reachable.');
        } catch (Throwable) {
            $results[] = $this->fail('DATABASE_REACHABLE', 'Database is not reachable.');
        }

        $queue = (string) config('queue.default');
        if ($isHardened && in_array($queue, ['sync', 'null'], true)) {
            $results[] = $this->fail('QUEUE_CONNECTION', 'Queue driver "'.$queue.'" is unsafe for '.$env.'; use redis/database/sqs.');
        } elseif (in_array($queue, ['sync', 'null'], true)) {
            $results[] = $this->warn('QUEUE_CONNECTION', 'Queue driver is '.$queue.' — acceptable for local only.');
        } else {
            $results[] = $this->pass('QUEUE_CONNECTION', 'Queue driver is production-capable.');
        }

        $results[] = filled(config('cache.default'))
            ? $this->pass('CACHE_STORE', 'Cache store is configured.')
            : $this->fail('CACHE_STORE', 'Cache store is missing.');

        $results[] = filled(config('session.driver'))
            ? $this->pass('SESSION_DRIVER', 'Session driver is configured.')
            : $this->fail('SESSION_DRIVER', 'Session driver is missing.');

        if ($isHardened && config('session.secure') !== true) {
            $results[] = $this->warn('SESSION_SECURE_COOKIE', 'SESSION_SECURE_COOKIE should be true behind HTTPS.');
        } else {
            $results[] = $this->pass('SESSION_SECURE_COOKIE', 'Session cookie security setting reviewed.');
        }

        $mailer = (string) config('mail.default');
        if ($isProd && in_array($mailer, ['log', 'array'], true)) {
            $results[] = $this->fail('MAIL_MAILER', 'Mailer "'.$mailer.'" will not deliver email in production.');
        } elseif ($isStaging && in_array($mailer, ['log', 'array'], true)) {
            $results[] = $this->warn('MAIL_MAILER', 'Staging mailer is '.$mailer.' — use a sandbox SMTP for invitation/reset tests.');
        } elseif (in_array($mailer, ['log', 'array'], true)) {
            $results[] = $this->warn('MAIL_MAILER', 'Mailer is '.$mailer.' — local only.');
        } else {
            $results[] = $this->pass('MAIL_MAILER', 'Mail transport is configured.');
        }

        $results[] = filled(config('filesystems.default'))
            ? $this->pass('FILESYSTEM_DISK', 'Filesystem disk is configured.')
            : $this->fail('FILESYSTEM_DISK', 'Filesystem disk is missing.');

        $secretKey = config('payments.stripe.secret_key');
        $pubKey = config('payments.stripe.publishable_key');
        $paymentsEnabled = filled($secretKey) || filled($pubKey);
        if ($paymentsEnabled || $isHardened) {
            $secret = filled($secretKey);
            $pub = filled($pubKey);
            $wh = filled(config('payments.stripe.webhook_secret'));
            $results[] = $secret
                ? $this->pass('STRIPE_SECRET_KEY', 'Stripe secret key is present.')
                : ($isHardened ? $this->fail('STRIPE_SECRET_KEY', 'Required when card payments are enabled.') : $this->warn('STRIPE_SECRET_KEY', 'Missing (payments disabled locally).'));
            $results[] = $pub
                ? $this->pass('STRIPE_PUBLISHABLE_KEY', 'Stripe publishable key is present.')
                : ($isHardened ? $this->warn('STRIPE_PUBLISHABLE_KEY', 'Missing publishable key.') : $this->warn('STRIPE_PUBLISHABLE_KEY', 'Missing (local).'));
            if ($secret || $isHardened) {
                $results[] = $wh
                    ? $this->pass('STRIPE_WEBHOOK_SECRET', 'Webhook secret is present.')
                    : $this->fail('STRIPE_WEBHOOK_SECRET', 'Required when Stripe payments are enabled.');
            }

            $mode = StripeKeyMode::compare(
                is_string($secretKey) ? $secretKey : null,
                is_string($pubKey) ? $pubKey : null,
            );
            if (! $mode['consistent']) {
                $results[] = $this->fail('STRIPE_MODE', $mode['message']);
            } elseif ($isStaging && ($mode['secret_mode'] === 'live' || $mode['publishable_mode'] === 'live')) {
                $results[] = $this->fail('STRIPE_MODE', 'Staging must use Stripe test keys only.');
            } elseif ($secret || $pub) {
                $results[] = $this->pass('STRIPE_MODE', $mode['message'].($mode['secret_mode'] ? ' ('.$mode['secret_mode'].')' : ''));
            }
        } else {
            $results[] = $this->warn('PAYMENTS', 'Stripe keys not configured — card payments unavailable.');
        }

        if ($isStaging) {
            $cachePrefix = (string) config('cache.prefix');
            $redisPrefix = (string) config('database.redis.options.prefix', config('database.redis.prefix', ''));
            if ($cachePrefix === '' || (! str_contains(strtolower($cachePrefix), 'stag') && ! str_contains(strtolower($cachePrefix), 'staging'))) {
                $results[] = $this->warn('CACHE_PREFIX', 'Prefer a staging-specific CACHE_PREFIX to isolate from production.');
            } else {
                $results[] = $this->pass('CACHE_PREFIX', 'Cache prefix appears staging-scoped.');
            }
            unset($redisPrefix);
        }

        $results[] = $this->pass('TRUSTED_PROXIES', 'Configure TrustProxies behind a TLS terminator (see production runbook).');

        $storage = storage_path('app');
        $framework = storage_path('framework');
        $logs = storage_path('logs');
        foreach (['STORAGE_APP' => $storage, 'STORAGE_FRAMEWORK' => $framework, 'STORAGE_LOGS' => $logs] as $key => $path) {
            $results[] = (File::isDirectory($path) && File::isWritable($path))
                ? $this->pass($key, 'Writable: '.$key)
                : $this->fail($key, 'Directory not writable: '.$key);
        }

        $publicStorage = public_path('storage');
        if ($isHardened) {
            $results[] = (File::exists($publicStorage) || is_link($publicStorage))
                ? $this->pass('STORAGE_LINK', 'Public storage link exists.')
                : $this->warn('STORAGE_LINK', 'Run php artisan storage:link when public media is required.');
        } else {
            $results[] = $this->pass('STORAGE_LINK', 'Storage link checked (optional locally).');
        }

        $pending = $this->pendingMigrationCount();
        if ($pending === null) {
            $results[] = $this->warn('MIGRATIONS', 'Could not determine migration status.');
        } elseif ($pending > 0) {
            $results[] = $isHardened
                ? $this->fail('MIGRATIONS', $pending.' pending migration(s). Run after backup.')
                : $this->warn('MIGRATIONS', $pending.' pending migration(s).');
        } else {
            $results[] = $this->pass('MIGRATIONS', 'No pending migrations.');
        }

        $failedDriver = config('queue.failed.driver');
        $results[] = filled($failedDriver)
            ? $this->pass('QUEUE_FAILED_DRIVER', 'Failed-job storage is configured.')
            : $this->warn('QUEUE_FAILED_DRIVER', 'Failed-job driver not configured.');

        $seedPasswords = [
            env('SEED_SUPER_ADMIN_PASSWORD'),
            env('SEED_CUSTOMER_PASSWORD'),
            env('SEED_RESTAURANT_OWNER_PASSWORD'),
        ];
        $seedFilled = collect($seedPasswords)->filter(fn ($p) => filled($p))->isNotEmpty();
        if ($isProd && $seedFilled) {
            $results[] = $this->fail('SEED_CREDENTIALS', 'Seed passwords must be empty/absent in production.');
        } else {
            $results[] = $this->pass('SEED_CREDENTIALS', 'Seed credential placeholders reviewed.');
        }

        $hasFail = collect($results)->contains(fn ($r) => $r['status'] === 'FAIL');
        $hasWarn = collect($results)->contains(fn ($r) => $r['status'] === 'WARN');

        return [
            'status' => $hasFail ? 'FAIL' : ($hasWarn ? 'WARN' : 'PASS'),
            'exit_code' => $hasFail ? 1 : 0,
            'results' => $results,
        ];
    }

    private function pendingMigrationCount(): ?int
    {
        try {
            Artisan::call('migrate:status', ['--pending' => true]);
            $output = trim(Artisan::output());
            if ($output === '' || str_contains(strtolower($output), 'no pending')) {
                return 0;
            }
            // Count non-empty lines that look like pending migration names.
            $lines = array_filter(preg_split('/\R/', $output) ?: [], fn ($l) => trim($l) !== '' && ! str_starts_with(trim($l), 'Migration'));

            return count($lines);
        } catch (Throwable) {
            try {
                if (! Schema::hasTable('migrations')) {
                    return null;
                }

                return null;
            } catch (Throwable) {
                return null;
            }
        }
    }

    /** @return array{status: string, key: string, message: string} */
    private function pass(string $key, string $message): array
    {
        return ['status' => 'PASS', 'key' => $key, 'message' => $message];
    }

    /** @return array{status: string, key: string, message: string} */
    private function warn(string $key, string $message): array
    {
        return ['status' => 'WARN', 'key' => $key, 'message' => $message];
    }

    /** @return array{status: string, key: string, message: string} */
    private function fail(string $key, string $message): array
    {
        return ['status' => 'FAIL', 'key' => $key, 'message' => $message];
    }

    /** @return array{status: string, key: string, message: string} */
    private function check(string $key, bool $ok, string $pass, string $fail, bool $warn = false, ?string $warnMessage = null): array
    {
        if ($ok && $warn) {
            return $this->warn($key, $warnMessage ?? $pass);
        }

        return $ok ? $this->pass($key, $pass) : $this->fail($key, $fail);
    }
}
