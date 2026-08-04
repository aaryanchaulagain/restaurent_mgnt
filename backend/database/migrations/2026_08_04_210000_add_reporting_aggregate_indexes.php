<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['restaurant_id', 'placed_at'], 'orders_restaurant_placed_at_index');
            $table->index(['restaurant_id', 'status', 'placed_at'], 'orders_restaurant_status_placed_at_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['restaurant_id', 'paid_at'], 'payments_restaurant_paid_at_index');
            $table->index(['restaurant_id', 'status'], 'payments_restaurant_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_restaurant_placed_at_index');
            $table->dropIndex('orders_restaurant_status_placed_at_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_restaurant_paid_at_index');
            $table->dropIndex('payments_restaurant_status_index');
        });
    }
};
