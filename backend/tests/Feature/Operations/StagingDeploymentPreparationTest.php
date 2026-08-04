<?php

namespace Tests\Feature\Operations;

use App\Jobs\StagingQueueProbeJob;
use App\Services\Operations\ProductionReadinessService;
use App\Support\ReleaseIdentifier;
use App\Support\StripeKeyMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StagingDeploymentPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_rejects_debug_true(): void
    {
        config([
            'app.debug' => true,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://api-staging.example.com',
            'queue.default' => 'redis',
            'mail.default' => 'smtp',
            'session.secure' => true,
            'payments.stripe.secret_key' => 'sk_test_x',
            'payments.stripe.publishable_key' => 'pk_test_x',
            'payments.stripe.webhook_secret' => 'whsec_x',
        ]);

        $report = app(ProductionReadinessService::class)->evaluate('staging');
        $debug = collect($report['results'])->firstWhere('key', 'APP_DEBUG');

        $this->assertSame('FAIL', $debug['status']);
        $this->assertSame(1, $report['exit_code']);
    }

    public function test_staging_accepts_safe_staging_config(): void
    {
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://api-staging.example.com',
            'app.env' => 'staging',
            'queue.default' => 'redis',
            'cache.default' => 'redis',
            'cache.prefix' => 'suvakamana-staging-cache-',
            'session.driver' => 'redis',
            'session.secure' => true,
            'mail.default' => 'smtp',
            'filesystems.default' => 'local',
            'payments.stripe.secret_key' => 'sk_test_x',
            'payments.stripe.publishable_key' => 'pk_test_x',
            'payments.stripe.webhook_secret' => 'whsec_x',
        ]);

        $report = app(ProductionReadinessService::class)->evaluate('staging');
        $this->assertNotSame('FAIL', collect($report['results'])->firstWhere('key', 'APP_DEBUG')['status']);
        $this->assertSame('PASS', collect($report['results'])->firstWhere('key', 'STRIPE_MODE')['status']);
    }

    public function test_mixed_stripe_live_test_keys_are_rejected(): void
    {
        $mode = StripeKeyMode::compare('sk_live_abc', 'pk_test_abc');
        $this->assertFalse($mode['consistent']);

        config([
            'app.env' => 'staging',
            'payments.stripe.secret_key' => 'sk_live_abc',
            'payments.stripe.publishable_key' => 'pk_test_abc',
            'payments.stripe.webhook_secret' => 'whsec_x',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://api-staging.example.com',
            'queue.default' => 'redis',
            'mail.default' => 'smtp',
            'session.secure' => true,
        ]);

        $report = app(ProductionReadinessService::class)->evaluate('staging');
        $stripeMode = collect($report['results'])->firstWhere('key', 'STRIPE_MODE');
        $this->assertSame('FAIL', $stripeMode['status']);
        $encoded = json_encode($report);
        $this->assertStringNotContainsString('sk_live_abc', (string) $encoded);
    }

    public function test_staging_seed_refuses_production(): void
    {
        config(['app.env' => 'production']);
        $exit = Artisan::call('staging:seed-minimal', ['--force' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing', Artisan::output());
    }

    public function test_staging_seed_requires_force_flag(): void
    {
        config(['app.env' => 'staging']);
        $exit = Artisan::call('staging:seed-minimal');
        $this->assertSame(1, $exit);
    }

    public function test_queue_probe_dispatches_harmless_job_and_refuses_production(): void
    {
        config(['app.env' => 'production']);
        $this->assertSame(1, Artisan::call('queue:staging-probe'));

        config(['app.env' => 'staging']);
        Queue::fake();
        Artisan::call('queue:staging-probe', ['--json' => true]);
        Queue::assertPushed(StagingQueueProbeJob::class, function (StagingQueueProbeJob $job) {
            $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/i', $job->probeToken);

            return true;
        });
    }

    public function test_build_identifier_is_safely_exposed(): void
    {
        config(['app.version' => 'staging', 'app.release_sha' => 'abcdef0123456789']);
        $payload = ReleaseIdentifier::forHealth();
        $this->assertSame('staging', $payload['version']);
        $this->assertSame('abcdef0', $payload['release']);

        $response = $this->getJson('/api/health/live');
        $response->assertOk();
        $response->assertJsonPath('release', 'abcdef0');
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('abcdef0123456789', $body);
    }

    public function test_staging_env_examples_are_placeholders_only(): void
    {
        $backend = file_get_contents(base_path('.env.staging.example'));
        $this->assertIsString($backend);
        $this->assertStringContainsString('APP_ENV=staging', $backend);
        $this->assertStringContainsString('sk_test_replace_me', $backend);
        $this->assertDoesNotMatchRegularExpression('/sk_live_[A-Za-z0-9]+/', $backend);
        $this->assertDoesNotMatchRegularExpression('/PASSWORD=.+[A-Za-z0-9]{12,}/', $backend);

        $frontendPath = dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'.env.staging.example';
        $this->assertFileExists($frontendPath);
        $frontend = file_get_contents($frontendPath);
        $this->assertStringContainsString('NEXT_PUBLIC_API_URL=', (string) $frontend);
        $this->assertStringNotContainsString('sk_live_', (string) $frontend);
        $this->assertStringNotContainsString('STRIPE_SECRET', (string) $frontend);
    }

    public function test_deploy_scripts_contain_no_embedded_credentials(): void
    {
        $root = dirname(base_path());
        foreach (['deploy-staging.sh', 'verify-staging.sh', 'rollback-staging.sh'] as $script) {
            $path = $root.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.$script;
            $this->assertFileExists($path);
            $text = file_get_contents($path);
            $this->assertIsString($text);
            // Detector patterns may mention mode prefixes; forbid assigned live secrets.
            $this->assertDoesNotMatchRegularExpression('/sk_live_[A-Za-z0-9]{8,}/', $text);
            $this->assertDoesNotMatchRegularExpression('/whsec_[A-Za-z0-9]{8,}/', $text);
            $this->assertDoesNotMatchRegularExpression('/PASSWORD=\S+/', $text);
            $this->assertStringNotContainsString('C:\\Users\\', $text);
        }
    }
}
