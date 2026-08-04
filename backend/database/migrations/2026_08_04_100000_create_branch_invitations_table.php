<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('full_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('role', 64);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 32)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->timestamp('last_resent_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['email', 'status']);
            $table->index(['expires_at', 'status']);
            $table->index(['branch_id', 'email', 'role', 'status'], 'branch_invitations_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_invitations');
    }
};
