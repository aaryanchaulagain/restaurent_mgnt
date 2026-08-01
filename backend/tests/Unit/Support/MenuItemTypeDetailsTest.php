<?php

namespace Tests\Unit\Support;

use App\Support\BusinessTypes;
use App\Support\MenuItemTypeDetails;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MenuItemTypeDetailsTest extends TestCase
{
    public function test_restaurant_details_are_ignored(): void
    {
        $this->assertNull(MenuItemTypeDetails::sanitize(BusinessTypes::RESTAURANT, [
            'flavour' => 'should-not-persist',
        ]));
    }

    public function test_bakery_details_are_sanitized(): void
    {
        $details = MenuItemTypeDetails::sanitize(BusinessTypes::BAKERY, [
            'flavour' => 'Chocolate',
            'eggless' => true,
            'minimum_notice_hours' => 24,
            'custom_message_allowed' => true,
            'serves_people' => 8,
            'ignored' => 'nope',
        ]);

        $this->assertSame(BusinessTypes::BAKERY, $details['schema']);
        $this->assertSame('Chocolate', $details['flavour']);
        $this->assertTrue($details['eggless']);
        $this->assertSame(24, $details['minimum_notice_hours']);
        $this->assertArrayNotHasKey('ignored', $details);
    }

    public function test_grocery_barcode_and_limits(): void
    {
        $details = MenuItemTypeDetails::sanitize('grocery_store', [
            'brand' => 'Khana Pantry',
            'barcode' => '8901234567890',
            'manufacturer' => 'Local Co',
            'package_size' => '500g',
            'max_purchase_quantity' => 5,
        ]);

        $this->assertSame(BusinessTypes::GROCERY, $details['schema']);
        $this->assertSame('8901234567890', $details['barcode']);
        $this->assertSame(5, $details['max_purchase_quantity']);
    }

    public function test_butcher_storage_validation(): void
    {
        $this->expectException(ValidationException::class);
        MenuItemTypeDetails::sanitize(BusinessTypes::BUTCHER, [
            'storage' => 'room-temp',
        ]);
    }

    public function test_butcher_fixed_weight_variants(): void
    {
        $details = MenuItemTypeDetails::sanitize('butchery', [
            'animal_type' => 'Chicken',
            'cut_type' => 'Thigh',
            'storage' => 'fresh',
            'bone_in' => false,
            'skin_on' => true,
            'fixed_weight_grams' => 500,
            'fixed_weight_variants' => [
                ['name' => '500g pack', 'weight_grams' => 500],
                ['name' => '1kg pack', 'weight_grams' => 1000],
            ],
        ]);

        $this->assertSame(BusinessTypes::BUTCHER, $details['schema']);
        $this->assertFalse($details['bone_in']);
        $this->assertCount(2, $details['fixed_weight_variants']);
    }
}
