<?php

namespace Tests\Feature\Operations;

use App\Services\Auth\AuditLogger;
use App\Support\SensitiveDataRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SensitiveDataRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_redacted(): void
    {
        $out = SensitiveDataRedactor::scrub(['password' => 'secret-value', 'email' => 'a@b.c']);
        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertSame('a@b.c', $out['email']);
    }

    public function test_invitation_token_is_redacted(): void
    {
        $out = SensitiveDataRedactor::scrub(['invitation_token' => 'plain-token']);
        $this->assertSame('[REDACTED]', $out['invitation_token']);
    }

    public function test_authorization_header_is_redacted(): void
    {
        $out = SensitiveDataRedactor::scrub(['Authorization' => 'Bearer abc', 'authorization' => 'Bearer abc']);
        $this->assertSame('[REDACTED]', $out['Authorization']);
        $this->assertSame('[REDACTED]', $out['authorization']);
    }

    public function test_payment_token_is_redacted(): void
    {
        $out = SensitiveDataRedactor::scrub(['payment_token' => 'tok_123', 'client_secret' => 'cs_123']);
        $this->assertSame('[REDACTED]', $out['payment_token']);
        $this->assertSame('[REDACTED]', $out['client_secret']);
    }

    public function test_webhook_signature_is_redacted(): void
    {
        $out = SensitiveDataRedactor::scrub([
            'stripe-signature' => 't=1,v1=abc',
            'stripe_signature' => 't=1,v1=abc',
        ]);
        $this->assertSame('[REDACTED]', $out['stripe-signature']);
        $this->assertSame('[REDACTED]', $out['stripe_signature']);
    }

    public function test_browser_coordinates_are_redacted(): void
    {
        $out = SensitiveDataRedactor::scrub([
            'latitude' => -33.8,
            'longitude' => 151.2,
            'browser_coordinates' => ['lat' => 1, 'lng' => 2],
        ]);
        $this->assertSame('[REDACTED]', $out['latitude']);
        $this->assertSame('[REDACTED]', $out['longitude']);
        $this->assertSame('[REDACTED]', $out['browser_coordinates']);
    }

    public function test_audit_logger_persists_redacted_values(): void
    {
        app(AuditLogger::class)->log(
            action: 'test.redaction',
            newValues: [
                'password' => 'should-not-persist',
                'invitation_token' => 'tok',
                'safe' => 'ok',
            ],
            metadata: [
                'Authorization' => 'Bearer x',
                'payment_token' => 'pt',
            ],
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'test.redaction',
        ]);

        $row = \App\Models\AuditLog::query()->where('action', 'test.redaction')->first();
        $this->assertSame('[REDACTED]', $row->new_values['password']);
        $this->assertSame('[REDACTED]', $row->new_values['invitation_token']);
        $this->assertSame('ok', $row->new_values['safe']);
        $this->assertSame('[REDACTED]', $row->metadata['Authorization']);
        $this->assertSame('[REDACTED]', $row->metadata['payment_token']);
    }

    public function test_public_errors_contain_no_sql_or_path_details(): void
    {
        $response = $this->getJson('/api/v1/this-route-does-not-exist-xyz');
        $response->assertNotFound();
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('stack', strtolower($body));
        $this->assertArrayHasKey('request_id', $response->json());
    }
}
