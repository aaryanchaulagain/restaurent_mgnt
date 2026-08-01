<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_inventories', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            // Null = item-level stock. variant_scope mirrors id or 0 for unique indexing.
            $table->foreignId('menu_item_variant_id')->nullable()->constrained('menu_item_variants')->nullOnDelete();
            $table->unsignedBigInteger('variant_scope')->default(0);
            $table->boolean('track_stock')->default(true);
            $table->integer('quantity_on_hand')->default(0);
            $table->unsignedInteger('low_stock_threshold')->nullable()->default(5);
            $table->boolean('force_unavailable')->default(false);
            $table->timestamps();

            $table->unique(['restaurant_id', 'menu_item_id', 'variant_scope'], 'menu_item_inventories_scope_unique');
            $table->index(['restaurant_id', 'track_stock', 'quantity_on_hand'], 'menu_item_inventories_stock_idx');
        });

        Schema::create('inventory_stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('menu_item_inventory_id')->constrained('menu_item_inventories')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('delta');
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'created_at'], 'inventory_adjustments_restaurant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_adjustments');
        Schema::dropIfExists('menu_item_inventories');
    }
};
