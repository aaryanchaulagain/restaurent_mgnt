<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone')->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('status', 32)->default('pending')->index()->after('password');
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('status');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->softDeletes();
        });

        // Preserve existing Phase 0 users that only have `name`.
        $users = DB::table('users')->select('id', 'name')->get();
        foreach ($users as $user) {
            $parts = preg_split('/\s+/', trim((string) $user->name), 2);
            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $parts[0] ?: 'User',
                'last_name' => $parts[1] ?? '',
                'status' => 'active',
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });

        $users = DB::table('users')->select('id', 'first_name', 'last_name')->get();
        foreach ($users as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'name' => trim($user->first_name.' '.$user->last_name),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'first_name',
                'last_name',
                'phone',
                'phone_verified_at',
                'status',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
