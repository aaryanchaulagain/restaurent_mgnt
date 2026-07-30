<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal restaurants table for Phase 2 auth relationships only.
 * Full onboarding ships in Phase 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamps();
        });

        Schema::create('restaurant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('status', 32)->default('invited')->index();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'user_id']);
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->foreign('restaurant_id')
                ->references('id')
                ->on('restaurants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropForeign(['restaurant_id']);
        });

        Schema::dropIfExists('restaurant_users');
        Schema::dropIfExists('restaurants');
    }
};
