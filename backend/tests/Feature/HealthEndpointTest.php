<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_versioned_health_endpoint_returns_minimal_payload(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonStructure([
            'status',
            'checks' => ['database', 'cache', 'storage'],
            'version',
        ]);
        $response->assertJsonMissingPath('environment');
        $response->assertJsonMissingPath('database_driver');
    }

    public function test_unversioned_health_endpoint_is_available(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
    }
}
