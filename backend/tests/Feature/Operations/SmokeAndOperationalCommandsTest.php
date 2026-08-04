<?php

namespace Tests\Feature\Operations;

use App\Models\MenuItemInventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmokeAndOperationalCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_scheduled_commands_are_registered(): void
    {
        Artisan::call('schedule:list');
        $out = Artisan::output();
        foreach ([
            'orders:expire-unaccepted',
            'payments:expire-pending',
            'payments:reconcile',
            'payments:retry-webhooks',
            'inventory:release-expired-reservations',
        ] as $cmd) {
            $this->assertStringContainsString($cmd, $out);
        }
    }

    public function test_queue_check_reports_driver_without_payloads(): void
    {
        Artisan::call('queue:operational-check', ['--json' => true]);
        $out = Artisan::output();
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('driver', $json['report']);
        $this->assertSame(config('queue.default'), $json['report']['driver']);
        $this->assertStringNotContainsString('password', $out);
    }

    public function test_payments_operational_check_detects_missing_webhook_secret(): void
    {
        config([
            'payments.stripe.secret_key' => 'sk_test_x',
            'payments.stripe.publishable_key' => 'pk_test_x',
            'payments.stripe.webhook_secret' => null,
        ]);

        $exit = Artisan::call('payments:operational-check', ['--json' => true]);
        $out = Artisan::output();
        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('sk_test_x', $out);
        $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET', $out);
    }

    public function test_payments_operational_check_does_not_make_live_charge(): void
    {
        Artisan::call('payments:operational-check', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertFalse($json['report']['live_api_calls']);
    }

    public function test_smoke_command_performs_no_writes(): void
    {
        $this->seedMinimalRoles();

        Notification::fake();
        Mail::fake();

        $ordersBefore = Order::query()->count();
        $paymentsBefore = Payment::query()->count();
        $inventoryChecksum = (int) MenuItemInventory::query()->sum('quantity_on_hand');

        $json = app(\App\Services\Operations\SmokeTestService::class)->run();

        $this->assertSame($ordersBefore, Order::query()->count());
        $this->assertSame($paymentsBefore, Payment::query()->count());
        $this->assertSame($inventoryChecksum, (int) MenuItemInventory::query()->sum('quantity_on_hand'));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertTrue($json['guarantees']['no_orders_created']);
        $this->assertTrue($json['guarantees']['no_payments_created']);
        $this->assertTrue($json['guarantees']['no_inventory_changed']);
        $this->assertTrue($json['guarantees']['no_email_sent']);
        $this->assertContains($json['status'], ['PASS', 'WARN', 'FAIL']);
    }

    public function test_cors_does_not_allow_wildcard_with_credentials(): void
    {
        $origins = config('cors.allowed_origins');
        $this->assertNotContains('*', $origins);
        $this->assertTrue(config('cors.supports_credentials'));
    }

    public function test_env_example_exists_and_gitignore_covers_env(): void
    {
        $this->assertFileExists(base_path('.env.example'));
        $gitignore = file_get_contents(base_path('.gitignore'));
        $this->assertStringContainsString('.env', (string) $gitignore);
    }

    private function seedMinimalRoles(): void
    {
        foreach (['super_admin', 'restaurant_owner', 'restaurant_manager', 'restaurant_staff', 'customer'] as $slug) {
            Role::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline($slug), 'guard' => 'web'],
            );
        }
        foreach (config('suvakamana.permissions', []) as $slug) {
            Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline($slug)],
            );
        }
    }
}
