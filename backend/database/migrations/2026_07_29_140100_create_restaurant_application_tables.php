<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('applicant_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->nullable()->constrained('restaurants')->nullOnDelete();
            $table->string('status', 32)->default('draft')->index();
            $table->string('legal_business_name')->nullable();
            $table->string('trading_name')->nullable();
            $table->string('business_type', 40)->nullable();
            $table->string('abn', 14)->nullable()->index();
            $table->string('business_registration_number')->nullable();
            $table->string('business_email')->nullable();
            $table->string('business_phone', 40)->nullable();
            $table->string('website_url')->nullable();
            $table->text('description')->nullable();
            $table->string('primary_contact_name')->nullable();
            $table->string('primary_contact_email')->nullable();
            $table->string('primary_contact_phone', 40)->nullable();
            $table->string('cuisine_summary')->nullable();
            $table->string('service_type', 40)->nullable();
            $table->string('expected_monthly_orders', 40)->nullable();
            $table->string('current_delivery_method')->nullable();
            $table->unsignedTinyInteger('location_count')->nullable();
            $table->string('referral_source')->nullable();
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_category', 40)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('changes_requested_reason')->nullable();
            $table->json('changes_requested_items')->nullable();
            $table->string('terms_version', 40)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_accepted_ip', 45)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'submitted_at']);
            $table->index(['applicant_user_id', 'status']);
        });

        Schema::create('restaurant_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->nullable()->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('restaurant_applications')->cascadeOnDelete();
            $table->string('address_type', 32)->default('physical');
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('suburb');
            $table->string('state', 10);
            $table->string('postcode', 12);
            $table->string('country', 2)->default('AU');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index(['application_id', 'address_type']);
            $table->index(['restaurant_id', 'address_type']);
        });

        Schema::create('restaurant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->nullable()->constrained('restaurants')->nullOnDelete();
            $table->foreignId('application_id')->constrained('restaurant_applications')->cascadeOnDelete();
            $table->string('document_type', 64)->index();
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 32)->default('pending')->index();
            $table->text('verification_notes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('restaurant_application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('restaurant_applications')->cascadeOnDelete();
            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['application_id', 'created_at'], 'app_status_history_app_created_idx');
        });

        Schema::create('restaurant_application_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('restaurant_applications')->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('note');
            $table->string('visibility', 32)->default('internal')->index();
            $table->timestamps();
        });

        Schema::create('restaurant_commission_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->nullable()->constrained('restaurants')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('restaurant_applications')->nullOnDelete();
            $table->string('commission_type', 40)->default('percentage');
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->unsignedInteger('fixed_fee_cents')->default(0);
            $table->string('processing_fee_responsibility', 40)->nullable();
            $table->string('delivery_fee_responsibility', 40)->nullable();
            $table->string('discount_calculation_method', 40)->nullable();
            $table->string('settlement_frequency', 32)->default('weekly');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->string('terms_version', 40)->nullable();
            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_commission_agreements');
        Schema::dropIfExists('restaurant_application_notes');
        Schema::dropIfExists('restaurant_application_status_history');
        Schema::dropIfExists('restaurant_documents');
        Schema::dropIfExists('restaurant_addresses');
        Schema::dropIfExists('restaurant_applications');
    }
};
