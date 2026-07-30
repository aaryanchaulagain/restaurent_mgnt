<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuisines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('restaurant_cuisines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('cuisine_id')->constrained('cuisines')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unique(['restaurant_id', 'cuisine_id']);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreign('primary_cuisine_id')->references('id')->on('cuisines')->nullOnDelete();
        });

        Schema::create('restaurant_service_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('postcode', 12)->nullable();
            $table->decimal('radius_km', 8, 2)->nullable();
            $table->unsignedInteger('minimum_order_cents')->default(0);
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedInteger('free_delivery_threshold_cents')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['restaurant_id', 'is_active']);
        });

        Schema::create('restaurant_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->string('service_type', 32)->default('all');
            $table->timestamps();
            $table->index(['restaurant_id', 'day_of_week'], 'rest_open_hours_day_idx');
        });

        Schema::create('restaurant_special_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->date('date');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'date']);
        });

        Schema::create('restaurant_media', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('storage_path');
            $table->string('thumbnail_path')->nullable();
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['restaurant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropForeign(['primary_cuisine_id']);
        });
        Schema::dropIfExists('restaurant_media');
        Schema::dropIfExists('restaurant_special_hours');
        Schema::dropIfExists('restaurant_opening_hours');
        Schema::dropIfExists('restaurant_service_areas');
        Schema::dropIfExists('restaurant_cuisines');
        Schema::dropIfExists('cuisines');
    }
};
