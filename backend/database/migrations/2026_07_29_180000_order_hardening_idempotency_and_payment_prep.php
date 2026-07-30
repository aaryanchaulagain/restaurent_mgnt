<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_scope', 128)->nullable()->after('idempotency_key');
            $table->string('idempotency_payload_hash', 64)->nullable()->after('idempotency_scope');
            $table->string('payment_provider', 50)->nullable()->after('payment_status');
            $table->string('payment_reference', 255)->nullable()->after('payment_provider');
            $table->unique(['idempotency_scope', 'idempotency_key'], 'orders_idempotency_scope_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_idempotency_scope_key_unique');
            $table->dropColumn([
                'idempotency_scope',
                'idempotency_payload_hash',
                'payment_provider',
                'payment_reference',
            ]);
        });
    }
};
