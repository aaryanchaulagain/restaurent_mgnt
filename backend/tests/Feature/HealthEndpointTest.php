<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_versioned_health_endpoint_returns_api_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'service',
                'version',
                'environment',
                'database_driver',
                'cache_store',
                'queue_connection',
                'checks' => [
                    'database' => ['ok', 'message'],
                    'cache' => ['ok', 'message'],
                ],
            ],
            'meta',
            'errors',
        ]);

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.service', 'suvakamana-api');
        $response->assertJsonPath('data.version', 'v1');
        $response->assertJsonPath('data.database_driver', 'mysql');
        $response->assertJsonPath('data.checks.database.ok', true);
        $response->assertJsonPath('data.checks.cache.ok', true);
    }

    public function test_unversioned_health_endpoint_is_available(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJsonPath('data.service', 'suvakamana-api');
    }
}
