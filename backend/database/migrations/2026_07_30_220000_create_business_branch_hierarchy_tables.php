<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('business_type', 32)->default('restaurant')->index();
            $table->string('ownership_type', 20)->default('third_party')->index();
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            // Operational fulfilment unit in the legacy restaurants table (1:1 for Phase 1).
            $table->foreignId('restaurant_id')->nullable()->unique()->constrained('restaurants')->nullOnDelete();
            $table->string('name');
            $table->string('code', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address_line')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('country', 2)->default('AU');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('delivery_radius_km', 8, 2)->nullable();
            $table->unsignedInteger('minimum_order_amount_cents')->default(0);
            $table->boolean('accepting_orders')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('status', 32)->default('active')->index();
            $table->string('timezone')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('business_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 64)->index();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id', 'role']);
        });

        Schema::create('branch_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 64)->index();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'user_id', 'role']);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('business_id')->constrained('branches')->nullOnDelete();
            $table->index('business_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('business_id');
        });

        Schema::dropIfExists('branch_users');
        Schema::dropIfExists('business_users');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('businesses');
    }
};
