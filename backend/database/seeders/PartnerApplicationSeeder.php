<?php

namespace Database\Seeders;

use App\Enums\Partner\ApplicationStatus;
use App\Models\RestaurantApplication;
use App\Models\RestaurantApplicationStatusHistory;
use App\Models\RestaurantCommissionAgreement;
use App\Models\RestaurantDocument;
use App\Models\User;
use App\Support\Abn;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PartnerApplicationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $customer = User::query()->where('email', env('SEED_CUSTOMER_EMAIL', 'customer@example.com'))->first();
        $admin = User::query()->where('email', env('SEED_SUPER_ADMIN_EMAIL', 'admin@example.com'))->first();
        if (! $customer || ! $admin) {
            return;
        }

        $samples = [
            ['status' => ApplicationStatus::Draft, 'trading' => 'Harbour Spice Co', 'abn' => '51824753556'],
            ['status' => ApplicationStatus::Submitted, 'trading' => 'Valley Clay Oven', 'abn' => '53004085616'],
            ['status' => ApplicationStatus::UnderReview, 'trading' => 'Lotus Leaf Cafe', 'abn' => '33102417032'],
            ['status' => ApplicationStatus::ChangesRequested, 'trading' => 'Blue Wren Bistro', 'abn' => '51824753556'],
            ['status' => ApplicationStatus::Rejected, 'trading' => 'Red Dust Kitchen', 'abn' => '53004085616'],
        ];

        // Use unique valid-looking ABNs - need unique for each. Generate properly.
        $abns = [
            '51824753556', // valid checksum
            '53004085616',
            '33102417032',
            '29001089046', // may need check - use known valid ones
            '45004079030',
        ];

        foreach ($samples as $i => $sample) {
            $abn = Abn::isValid($abns[$i] ?? '') ? $abns[$i] : null;
            $app = RestaurantApplication::query()->updateOrCreate(
                [
                    'applicant_user_id' => $customer->id,
                    'trading_name' => $sample['trading'],
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'status' => $sample['status'],
                    'legal_business_name' => $sample['trading'].' Pty Ltd',
                    'business_type' => 'company',
                    'abn' => $abn,
                    'business_email' => Str::slug($sample['trading']).'@example.test',
                    'business_phone' => '+6140000000'.$i,
                    'description' => 'Fictional development restaurant serving modern Australian cuisine.',
                    'primary_contact_name' => $customer->name,
                    'primary_contact_email' => $customer->email,
                    'primary_contact_phone' => '+61411111111',
                    'cuisine_summary' => 'Modern Australian',
                    'service_type' => 'delivery_and_pickup',
                    'expected_monthly_orders' => '200-500',
                    'submitted_at' => $sample['status'] === ApplicationStatus::Draft ? null : now()->subDays(3 - $i),
                    'assigned_reviewer_id' => in_array($sample['status'], [ApplicationStatus::UnderReview, ApplicationStatus::ChangesRequested], true) ? $admin->id : null,
                    'changes_requested_reason' => $sample['status'] === ApplicationStatus::ChangesRequested
                        ? 'Please upload a clearer food business licence.'
                        : null,
                    'rejection_reason' => $sample['status'] === ApplicationStatus::Rejected
                        ? 'Business location is outside our current service area.'
                        : null,
                    'rejection_category' => $sample['status'] === ApplicationStatus::Rejected ? 'unsupported_location' : null,
                    'version' => 1,
                ],
            );

            $app->addresses()->updateOrCreate(
                ['address_type' => 'physical'],
                [
                    'address_line_1' => (100 + $i).' Example Street',
                    'suburb' => 'Sydney',
                    'state' => 'NSW',
                    'postcode' => '2000',
                    'country' => 'AU',
                    'is_primary' => true,
                ],
            );

            RestaurantApplicationStatusHistory::query()->firstOrCreate(
                [
                    'application_id' => $app->id,
                    'new_status' => $sample['status']->value,
                ],
                [
                    'old_status' => 'draft',
                    'changed_by' => $admin->id,
                    'reason' => 'Seeded status',
                    'created_at' => now(),
                ],
            );

            if ($sample['status'] !== ApplicationStatus::Draft) {
                foreach (['business_registration', 'food_business_licence', 'owner_identification'] as $type) {
                    RestaurantDocument::query()->firstOrCreate(
                        [
                            'application_id' => $app->id,
                            'document_type' => $type,
                        ],
                        [
                            'original_name' => $type.'.pdf',
                            'storage_path' => 'restaurant-documents/seed/'.$app->public_id.'/'.$type.'.pdf',
                            'mime_type' => 'application/pdf',
                            'size_bytes' => 1024,
                            'status' => $sample['status'] === ApplicationStatus::UnderReview ? 'pending' : 'pending',
                            'uploaded_by' => $customer->id,
                        ],
                    );
                }
            }

            if ($sample['status'] === ApplicationStatus::UnderReview) {
                RestaurantCommissionAgreement::query()->updateOrCreate(
                    [
                        'application_id' => $app->id,
                        'status' => 'offered',
                    ],
                    [
                        'commission_type' => 'percentage',
                        'commission_rate' => '12.50',
                        'fixed_fee_cents' => 0,
                        'settlement_frequency' => 'weekly',
                        'effective_from' => now()->toDateString(),
                        'created_by' => $admin->id,
                        'terms_version' => config('partner.terms_version'),
                    ],
                );
            }
        }
    }
}
