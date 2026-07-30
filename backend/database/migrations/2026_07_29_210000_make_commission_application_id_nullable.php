<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_commission_agreements', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE restaurant_commission_agreements MODIFY application_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE restaurant_commission_agreements ALTER COLUMN application_id DROP NOT NULL');
        } else {
            // sqlite / others: recreate foreign without not-null via schema builder if possible
            Schema::table('restaurant_commission_agreements', function (Blueprint $table) {
                $table->unsignedBigInteger('application_id')->nullable()->change();
            });
        }

        Schema::table('restaurant_commission_agreements', function (Blueprint $table) {
            $table->foreign('application_id')
                ->references('id')
                ->on('restaurant_applications')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_commission_agreements', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE restaurant_commission_agreements MODIFY application_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE restaurant_commission_agreements ALTER COLUMN application_id SET NOT NULL');
        }

        Schema::table('restaurant_commission_agreements', function (Blueprint $table) {
            $table->foreign('application_id')
                ->references('id')
                ->on('restaurant_applications')
                ->cascadeOnDelete();
        });
    }
};
