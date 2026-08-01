<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize legacy migrator statuses toward Phase 2 BranchStatuses.
        DB::table('branches')->where('status', 'pending')->update(['status' => 'draft']);
        DB::table('branches')->where('status', 'temporarily_closed')->update(['status' => 'paused']);

        // Prefer one role row per user per business/branch.
        $this->dedupe('business_users', ['business_id', 'user_id']);
        $this->dedupe('branch_users', ['branch_id', 'user_id']);

        // MySQL: FK business_users_business_id_foreign is backed only by the old
        // composite unique (business_id leftmost). Add the replacement unique first.
        $this->replaceUniqueIndex(
            'business_users',
            'business_users_business_id_user_id_role_unique',
            'business_users_business_id_user_id_unique',
            ['business_id', 'user_id'],
        );

        $this->replaceUniqueIndex(
            'branch_users',
            'branch_users_branch_id_user_id_role_unique',
            'branch_users_branch_id_user_id_unique',
            ['branch_id', 'user_id'],
        );

        if (! $this->hasIndex('restaurants', 'restaurants_branch_id_unique')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->unique('branch_id', 'restaurants_branch_id_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('restaurants', 'restaurants_branch_id_unique')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropUnique('restaurants_branch_id_unique');
            });
        }

        $this->replaceUniqueIndex(
            'branch_users',
            'branch_users_branch_id_user_id_unique',
            'branch_users_branch_id_user_id_role_unique',
            ['branch_id', 'user_id', 'role'],
        );

        $this->replaceUniqueIndex(
            'business_users',
            'business_users_business_id_user_id_unique',
            'business_users_business_id_user_id_role_unique',
            ['business_id', 'user_id', 'role'],
        );
    }

    /**
     * @param  list<string>  $keys
     */
    private function dedupe(string $table, array $keys): void
    {
        $rows = DB::table($table)->orderBy('id')->get();
        $seen = [];
        foreach ($rows as $row) {
            $key = implode(':', array_map(fn ($k) => $row->{$k}, $keys));
            if (isset($seen[$key])) {
                DB::table($table)->where('id', $row->id)->delete();
                continue;
            }
            $seen[$key] = true;
        }
    }

    /**
     * Add the new unique index first so MySQL FK constraints keep a usable index,
     * then drop the old unique index.
     *
     * @param  list<string>  $newColumns
     */
    private function replaceUniqueIndex(
        string $table,
        string $oldIndexName,
        string $newIndexName,
        array $newColumns,
    ): void {
        if (! $this->hasIndex($table, $newIndexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($newColumns, $newIndexName) {
                $blueprint->unique($newColumns, $newIndexName);
            });
        }

        if ($this->hasIndex($table, $oldIndexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($oldIndexName) {
                $blueprint->dropUnique($oldIndexName);
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 LIMIT 1',
                [$table, $indexName]
            );

            return $rows !== [];
        }

        if ($driver === 'sqlite') {
            $rows = DB::select('PRAGMA index_list(`'.$table.'`)');
            foreach ($rows as $row) {
                $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return Schema::hasIndex($table, $indexName);
    }
};
