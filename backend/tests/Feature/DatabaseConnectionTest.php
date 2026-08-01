<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_default_database_connection_is_available(): void
    {
        $driver = config('database.default');
        $this->assertContains($driver, ['mysql', 'sqlite']);

        if ($driver === 'mysql') {
            $row = DB::selectOne('select 1 as ok, database() as db_name, @@character_set_database as charset');
            $this->assertSame(1, (int) $row->ok);
            $this->assertNotEmpty($row->db_name);
            $this->assertSame('utf8mb4', $row->charset);

            return;
        }

        $row = DB::selectOne('select 1 as ok');
        $this->assertSame(1, (int) $row->ok);
    }

    public function test_file_cache_store_works(): void
    {
        $this->assertSame('file', config('cache.default'));

        Cache::put('suvakamana_test_key', 'alive', 30);
        $this->assertSame('alive', Cache::get('suvakamana_test_key'));
        Cache::forget('suvakamana_test_key');
    }
}
