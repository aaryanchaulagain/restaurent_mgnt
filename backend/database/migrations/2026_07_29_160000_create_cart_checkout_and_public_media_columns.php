<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->json('logo_urls')->nullable()->after('logo_path');
            $table->json('cover_urls')->nullable()->after('cover_image_path');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->json('image_urls')->nullable()->after('image_path');
        });

        Schema::table('menu_categories', function (Blueprint $table) {
            $table->json('image_urls')->nullable()->after('image_path');
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('recipient_name');
            $table->string('phone', 32)->nullable();
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('suburb');
            $table->string('state', 80);
            $table->string('postcode', 12);
            $table->string('country', 2)->default('AU');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_id', 'is_default']);
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('status', 32)->default('active')->index();
            $table->char('currency', 3)->default('AUD');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_validated_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('menu_item_variant_id')->nullable()->constrained('menu_item_variants')->nullOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('special_instructions', 500)->nullable();
            $table->unsignedInteger('unit_price_snapshot_cents')->default(0);
            $table->unsignedInteger('estimated_total_cents')->default(0);
            $table->timestamps();
            $table->index(['cart_id']);
        });

        Schema::create('cart_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_item_id')->constrained('cart_items')->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->foreignId('modifier_option_id')->constrained('modifier_options')->cascadeOnDelete();
            $table->integer('price_adjustment_snapshot_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('checkout_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('fulfilment_type', 40);
            $table->json('address_snapshot')->nullable();
            $table->json('pricing_snapshot');
            $table->json('warnings')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_quotes');
        Schema::dropIfExists('cart_item_modifiers');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('customer_addresses');
        Schema::table('menu_categories', fn (Blueprint $t) => $t->dropColumn('image_urls'));
        Schema::table('menu_items', fn (Blueprint $t) => $t->dropColumn('image_urls'));
        Schema::table('restaurants', function (Blueprint $t) {
            $t->dropColumn(['logo_urls', 'cover_urls']);
        });
    }
};
