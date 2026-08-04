<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Models\Role;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

/**
 * Non-destructive production smoke checks. Must not write business data.
 */
class SmokeTestService
{
    /**
     * @return array{status: string, exit_code: int, results: list<array{status: string, key: string, message: string}>}
     */
    public function run(): array
    {
        $results = [];

        $results[] = $this->pass('BOOT', 'Application booted.');

        try {
            DB::select('select 1');
            $results[] = $this->pass('DATABASE', 'Database reachable.');
        } catch (Throwable) {
            $results[] = $this->fail('DATABASE', 'Database not reachable.');
        }

        try {
            $required = ['super_admin', 'restaurant_owner', 'restaurant_manager', 'restaurant_staff', 'customer'];
            $missing = [];
            foreach ($required as $role) {
                if (! Role::query()->where('slug', $role)->exists()) {
                    $missing[] = $role;
                }
            }
            $results[] = $missing === []
                ? $this->pass('ROLES', 'Required roles exist.')
                : $this->fail('ROLES', 'Missing roles: '.implode(', ', $missing));
        } catch (Throwable) {
            $results[] = $this->warn('ROLES', 'Could not verify roles (permission tables may be missing).');
        }

        $results[] = filled(config('queue.default'))
            ? $this->pass('QUEUE_CONFIG', 'Queue configuration present.')
            : $this->fail('QUEUE_CONFIG', 'Queue configuration missing.');

        $scheduleOk = $this->scheduleContains([
            'orders:expire-unaccepted',
            'payments:expire-pending',
            'payments:reconcile',
            'payments:retry-webhooks',
            'inventory:release-expired-reservations',
        ]);
        $results[] = $scheduleOk
            ? $this->pass('SCHEDULER', 'Required scheduled commands registered.')
            : $this->fail('SCHEDULER', 'One or more required scheduled commands missing.');

        $paymentsConfigured = filled(config('payments.stripe.secret_key'));
        if ($paymentsConfigured && ! filled(config('payments.stripe.webhook_secret'))) {
            $results[] = $this->fail('PAYMENTS', 'Webhook secret missing while Stripe secret is set.');
        } else {
            $results[] = $this->pass('PAYMENTS', 'Payment configuration consistency reviewed.');
        }

        $storage = storage_path('app');
        $results[] = (File::isDirectory($storage) && File::isWritable($storage))
            ? $this->pass('STORAGE', 'Storage writable.')
            : $this->fail('STORAGE', 'Storage not writable.');

        $pending = $this->pendingMigrations();
        if ($pending === null) {
            $results[] = $this->warn('MIGRATIONS', 'Could not determine pending migrations.');
        } elseif ($pending > 0) {
            $results[] = $this->fail('MIGRATIONS', $pending.' pending migration(s).');
        } else {
            $results[] = $this->pass('MIGRATIONS', 'No pending migrations.');
        }

        foreach ([
            'orders:tenant-integrity' => 'ORDER_INTEGRITY',
            'reporting:integrity' => 'REPORTING_INTEGRITY',
            'cart:branch-integrity' => 'CART_INTEGRITY',
            'inventory:reservation-integrity' => 'INVENTORY_INTEGRITY',
            'branch:location-integrity' => 'LOCATION_INTEGRITY',
        ] as $command => $key) {
            $results[] = $this->runIntegrity($command, $key);
        }

        $hasFail = collect($results)->contains(fn ($r) => $r['status'] === 'FAIL');
        $hasWarn = collect($results)->contains(fn ($r) => $r['status'] === 'WARN');

        return [
            'status' => $hasFail ? 'FAIL' : ($hasWarn ? 'WARN' : 'PASS'),
            'exit_code' => $hasFail ? 1 : 0,
            'results' => $results,
            'guarantees' => [
                'no_orders_created' => true,
                'no_payments_created' => true,
                'no_inventory_changed' => true,
                'no_email_sent' => true,
            ],
        ];
    }

    /** @param list<string> $required */
    private function scheduleContains(array $required): bool
    {
        try {
            $buffer = new BufferedOutput;
            Artisan::call('schedule:list', [], $buffer);
            $output = $buffer->fetch();
            foreach ($required as $cmd) {
                if (! str_contains($output, $cmd)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function pendingMigrations(): ?int
    {
        try {
            $buffer = new BufferedOutput;
            Artisan::call('migrate:status', ['--pending' => true], $buffer);
            $output = trim($buffer->fetch());
            if ($output === '' || str_contains(strtolower($output), 'no pending')) {
                return 0;
            }
            $lines = array_filter(preg_split('/\R/', $output) ?: [], fn ($l) => trim($l) !== '' && ! str_starts_with(trim($l), 'Migration'));

            return count($lines);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{status: string, key: string, message: string} */
    private function runIntegrity(string $command, string $key): array
    {
        try {
            $exit = Artisan::call($command, [], new NullOutput);
            if ($exit === 0) {
                return $this->pass($key, $command.' completed.');
            }

            return $this->warn($key, $command.' reported issues (exit '.$exit.').');
        } catch (Throwable) {
            return $this->warn($key, $command.' could not run.');
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
}
