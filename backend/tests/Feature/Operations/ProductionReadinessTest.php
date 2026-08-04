<?php

namespace Tests\Feature\Operations;

use App\Services\Operations\ProductionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    public function test_production_debug_mode_is_rejected(): void
    {
        config(['app.debug' => true, 'app.env' => 'production']);

        $report = app(ProductionReadinessService::class)->evaluate('production');
        $debug = collect($report['results'])->firstWhere('key', 'APP_DEBUG');

        $this->assertSame('FAIL', $debug['status']);
        $this->assertSame(1, $report['exit_code']);
    }

    public function test_missing_app_key_is_detected(): void
    {
        config(['app.key' => '']);

        $report = app(ProductionReadinessService::class)->evaluate('local');
        $row = collect($report['results'])->firstWhere('key', 'APP_KEY');

        $this->assertSame('FAIL', $row['status']);
    }

    public function test_missing_database_config_is_detected(): void
    {
        config(['database.default' => null]);

        $report = app(ProductionReadinessService::class)->evaluate('local');
        $row = collect($report['results'])->firstWhere('key', 'DB_CONNECTION');

        $this->assertSame('FAIL', $row['status']);
    }

    public function test_unsafe_queue_driver_fails_in_production(): void
    {
        config(['queue.default' => 'sync']);

        $report = app(ProductionReadinessService::class)->evaluate('production');
        $row = collect($report['results'])->firstWhere('key', 'QUEUE_CONNECTION');

        $this->assertSame('FAIL', $row['status']);
    }

    public function test_missing_webhook_secret_when_stripe_enabled(): void
    {
        config([
            'payments.stripe.secret_key' => 'sk_test_placeholder',
            'payments.stripe.publishable_key' => 'pk_test_placeholder',
            'payments.stripe.webhook_secret' => null,
        ]);

        $report = app(ProductionReadinessService::class)->evaluate('local');
        $row = collect($report['results'])->firstWhere('key', 'STRIPE_WEBHOOK_SECRET');

        $this->assertSame('FAIL', $row['status']);
        $encoded = json_encode($report);
        $this->assertStringNotContainsString('sk_test_placeholder', (string) $encoded);
    }

    public function test_secret_values_are_never_printed_by_command(): void
    {
        config([
            'payments.stripe.secret_key' => 'sk_test_SHOULD_NOT_APPEAR',
            'payments.stripe.webhook_secret' => 'whsec_SHOULD_NOT_APPEAR',
            'payments.stripe.publishable_key' => 'pk_test_SHOULD_NOT_APPEAR',
        ]);

        $this->artisan('app:production-readiness')
            ->expectsOutputToContain('APP_DEBUG')
            ->assertExitCode(0);

        // Capture via json flag
        Artisan::call('app:production-readiness', ['--json' => true]);
        $out = Artisan::output();
        $this->assertStringNotContainsString('sk_test_SHOULD_NOT_APPEAR', $out);
        $this->assertStringNotContainsString('whsec_SHOULD_NOT_APPEAR', $out);
        $this->assertStringNotContainsString('pk_test_SHOULD_NOT_APPEAR', $out);
    }

    public function test_demo_seed_credentials_fail_in_production(): void
    {
        // Put seed password into env for this process without echoing it in assertions beyond presence check.
        putenv('SEED_SUPER_ADMIN_PASSWORD=temporary-local-only');
        $_ENV['SEED_SUPER_ADMIN_PASSWORD'] = 'temporary-local-only';
        $_SERVER['SEED_SUPER_ADMIN_PASSWORD'] = 'temporary-local-only';

        $report = app(ProductionReadinessService::class)->evaluate('production');
        $row = collect($report['results'])->firstWhere('key', 'SEED_CREDENTIALS');

        $this->assertSame('FAIL', $row['status']);

        putenv('SEED_SUPER_ADMIN_PASSWORD');
        unset($_ENV['SEED_SUPER_ADMIN_PASSWORD'], $_SERVER['SEED_SUPER_ADMIN_PASSWORD']);
    }

    public function test_command_exit_codes_are_meaningful(): void
    {
        config(['app.debug' => true]);
        $fail = app(ProductionReadinessService::class)->evaluate('production');
        $this->assertSame(1, $fail['exit_code']);

        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://example.com',
            'queue.default' => 'redis',
            'mail.default' => 'smtp',
            'payments.stripe.secret_key' => 'sk_test_x',
            'payments.stripe.publishable_key' => 'pk_test_x',
            'payments.stripe.webhook_secret' => 'whsec_x',
            'session.secure' => true,
        ]);
        // May still warn/fail on storage link etc — exit only non-zero on FAIL.
        $okish = app(ProductionReadinessService::class)->evaluate('production');
        if ($okish['status'] === 'FAIL') {
            $this->assertSame(1, $okish['exit_code']);
        } else {
            $this->assertSame(0, $okish['exit_code']);
        }
    }
}
