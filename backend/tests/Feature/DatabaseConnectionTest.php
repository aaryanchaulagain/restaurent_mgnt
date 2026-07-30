<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_mysql_connection_is_available(): void
    {
        $this->assertSame('mysql', config('database.default'));

        $row = DB::selectOne('select 1 as ok, database() as db_name, @@character_set_database as charset');

        $this->assertSame(1, (int) $row->ok);
        $this->assertSame('suvakamana_restaurant', $row->db_name);
        $this->assertSame('utf8mb4', $row->charset);
    }

    public function test_file_cache_store_works(): void
    {
        $this->assertSame('file', config('cache.default'));

        Cache::put('suvakamana_test_key', 'alive', 30);
        $this->assertSame('alive', Cache::get('suvakamana_test_key'));
        Cache::forget('suvakamana_test_key');
    }
}
