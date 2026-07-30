<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('short_description', 500)->nullable()->after('trading_name');
            $table->string('price_level', 32)->nullable()->after('primary_cuisine_id');
            $table->unsignedInteger('minimum_order_cents')->default(0);
            $table->unsignedSmallInteger('average_preparation_minutes')->nullable();
            $table->boolean('pickup_enabled')->default(true);
            $table->boolean('restaurant_delivery_enabled')->default(false);
            $table->boolean('third_party_delivery_enabled')->default(false);
            $table->boolean('dine_in_enabled')->default(false);
            $table->boolean('accepting_orders')->default(true);
            $table->text('temporarily_closed_reason')->nullable();
            $table->timestamp('temporarily_closed_until')->nullable();
            $table->timestamp('published_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'short_description',
                'price_level',
                'minimum_order_cents',
                'average_preparation_minutes',
                'pickup_enabled',
                'restaurant_delivery_enabled',
                'third_party_delivery_enabled',
                'dine_in_enabled',
                'accepting_orders',
                'temporarily_closed_reason',
                'temporarily_closed_until',
                'published_at',
            ]);
        });
    }
};
