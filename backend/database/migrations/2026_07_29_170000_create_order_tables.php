<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('order_number_sequences');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_adjustments');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('order_number', 30)->unique();
            $table->string('idempotency_key', 64)->nullable()->index();
            $table->unsignedBigInteger('restaurant_id')->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('cart_id')->nullable();
            $table->unsignedBigInteger('checkout_quote_id')->nullable();
            $table->string('guest_token_hash', 64)->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->string('payment_method', 30)->default('cash');
            $table->string('payment_status', 30)->default('unpaid');
            $table->string('fulfilment_type', 30);
            $table->string('currency', 3)->default('AUD');
            $table->string('customer_name_snapshot', 255)->nullable();
            $table->string('customer_email_snapshot', 255)->nullable();
            $table->string('customer_phone_snapshot', 50)->nullable();
            $table->json('delivery_address_snapshot')->nullable();
            $table->text('pickup_instructions')->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->text('customer_notes')->nullable();
            $table->boolean('contactless_delivery')->default(false);
            $table->integer('subtotal_cents')->default(0);
            $table->integer('discount_cents')->default(0);
            $table->integer('tax_cents')->default(0);
            $table->integer('service_fee_cents')->default(0);
            $table->integer('delivery_fee_cents')->default(0);
            $table->integer('total_cents')->default(0);
            $table->decimal('commission_rate_snapshot', 5, 4)->default(0);
            $table->integer('commission_amount_cents')->default(0);
            $table->integer('restaurant_net_estimate_cents')->default(0);
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('estimated_ready_at')->nullable();
            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->string('rejection_reason', 50)->nullable();
            $table->text('rejection_explanation')->nullable();
            $table->text('rejection_internal_note')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('cancellation_actor_type', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('menu_item_id')->nullable();
            $table->unsignedBigInteger('menu_item_variant_id')->nullable();
            $table->string('item_name_snapshot', 255);
            $table->text('item_description_snapshot')->nullable();
            $table->json('item_image_snapshot')->nullable();
            $table->string('variant_name_snapshot', 255)->nullable();
            $table->string('sku_snapshot', 100)->nullable();
            $table->integer('unit_price_cents');
            $table->integer('quantity');
            $table->integer('line_subtotal_cents');
            $table->integer('discount_cents')->default(0);
            $table->integer('line_total_cents');
            $table->integer('preparation_minutes_snapshot')->nullable();
            $table->json('dietary_snapshot')->nullable();
            $table->json('allergen_snapshot')->nullable();
            $table->text('customer_instructions')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_item_id')->index();
            $table->unsignedBigInteger('modifier_group_id')->nullable();
            $table->unsignedBigInteger('modifier_option_id')->nullable();
            $table->string('group_name_snapshot', 255);
            $table->string('option_name_snapshot', 255);
            $table->integer('price_adjustment_cents')->default(0);
            $table->integer('quantity')->default(1);
            $table->integer('total_adjustment_cents')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
        });

        Schema::create('order_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('type', 30);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('label', 255);
            $table->integer('amount_cents');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_type', 20);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
        });

        Schema::table('checkout_quotes', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('expires_at');
            $table->unsignedBigInteger('converted_order_id')->nullable()->after('status');
        });

        if (! collect(Schema::getIndexes('carts'))->contains(fn ($i) => $i['name'] === 'carts_customer_id_status_index')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->index(['customer_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'status']);
        });

        Schema::table('checkout_quotes', function (Blueprint $table) {
            $table->dropColumn(['status', 'converted_order_id']);
        });

        Schema::dropIfExists('order_number_sequences');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_adjustments');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
