<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->after('id');
            $table->string('legal_business_name')->nullable()->after('name');
            $table->string('trading_name')->nullable()->after('legal_business_name');
            $table->text('description')->nullable();
            $table->string('business_email')->nullable();
            $table->string('business_phone', 40)->nullable();
            $table->string('website_url')->nullable();
            $table->string('abn', 14)->nullable();
            $table->string('business_registration_number')->nullable();
            $table->string('verification_status', 32)->default('unverified');
            $table->unsignedBigInteger('primary_cuisine_id')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->string('timezone', 64)->default('Australia/Sydney');
            $table->string('currency', 3)->default('AUD');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->softDeletes();
        });

        foreach (DB::table('restaurants')->get() as $row) {
            DB::table('restaurants')->where('id', $row->id)->update([
                'public_id' => (string) Str::uuid(),
                'legal_business_name' => $row->name,
                'trading_name' => $row->name,
                'status' => $row->status === 'active' ? 'active' : 'pending_setup',
                'verification_status' => $row->status === 'active' ? 'verified' : 'unverified',
            ]);
        }

        Schema::table('restaurants', function (Blueprint $table) {
            $table->unique('public_id');
            $table->unique('abn');
            $table->index('verification_status');
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });

        foreach (DB::table('restaurants')->get() as $row) {
            DB::table('restaurants')->where('id', $row->id)->update([
                'name' => $row->trading_name ?? 'Restaurant',
            ]);
        }

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropUnique(['abn']);
            $table->dropIndex(['verification_status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'public_id',
                'legal_business_name',
                'trading_name',
                'description',
                'business_email',
                'business_phone',
                'website_url',
                'abn',
                'business_registration_number',
                'verification_status',
                'primary_cuisine_id',
                'logo_path',
                'cover_image_path',
                'timezone',
                'currency',
                'approved_at',
                'suspended_at',
                'suspension_reason',
                'deleted_at',
            ]);
        });
    }
};
