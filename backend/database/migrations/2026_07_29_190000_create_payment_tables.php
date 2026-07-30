<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurant_id')->unique();
            $table->string('provider', 30)->default('stripe');
            $table->string('external_account_id')->nullable()->index();
            $table->string('account_type', 30)->default('express');
            $table->string('onboarding_status', 30)->default('not_started')->index();
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->boolean('online_payments_enabled')->default(true);
            $table->json('requirements_currently_due')->nullable();
            $table->json('requirements_eventually_due')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->string('country', 2)->default('AU');
            $table->string('default_currency', 3)->default('AUD');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('restaurant_id')->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('provider', 30)->default('stripe');
            $table->string('payment_method_type', 30)->default('card');
            $table->string('status', 30)->default('pending')->index();
            $table->string('currency', 3)->default('AUD');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('amount_received_cents')->default(0);
            $table->unsignedInteger('amount_refunded_cents')->default(0);
            $table->unsignedInteger('platform_fee_cents')->default(0);
            $table->unsignedInteger('restaurant_share_cents')->default(0);
            $table->unsignedInteger('processing_fee_cents')->nullable();
            $table->string('external_payment_intent_id')->nullable()->unique();
            $table->string('external_charge_id')->nullable()->unique();
            $table->string('connected_account_id')->nullable()->index();
            $table->string('transfer_group')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('last_error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedInteger('attempt_number');
            $table->string('idempotency_key', 128);
            $table->string('request_payload_hash', 64);
            $table->string('status', 30)->default('pending');
            $table->string('external_payment_intent_id')->nullable()->index();
            $table->text('client_secret_encrypted')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('AUD');
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->boolean('requires_action')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'attempt_number']);
            $table->unique(['idempotency_key']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('provider', 30)->default('stripe');
            $table->string('external_event_id')->index();
            $table->string('event_type')->index();
            $table->string('payload_hash', 64);
            $table->boolean('livemode')->default(false);
            $table->string('api_version')->nullable();
            $table->string('processing_status', 20)->default('received')->index();
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('related_payment_id')->nullable()->index();
            $table->unsignedBigInteger('related_order_id')->nullable()->index();
            $table->json('sanitized_payload')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_event_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('restaurant_id')->index();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->string('provider', 30)->default('stripe');
            $table->string('external_refund_id')->nullable()->unique();
            $table->string('status', 30)->default('requested')->index();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('AUD');
            $table->string('reason_category', 40);
            $table->text('customer_reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->boolean('refund_application_fee')->default(true);
            $table->boolean('reverse_transfer')->default(true);
            $table->string('idempotency_key', 128)->unique();
            $table->string('provider_failure_code')->nullable();
            $table->string('provider_failure_message')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_disputes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('external_dispute_id')->unique();
            $table->string('status', 40)->index();
            $table->string('reason')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('AUD');
            $table->timestamp('evidence_due_at')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_disputes');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('restaurant_payment_accounts');
    }
};
