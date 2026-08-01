<?php

namespace Tests\Unit\Support;

use App\Support\BusinessTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessTypesTest extends TestCase
{
    #[DataProvider('normalizeProvider')]
    public function test_normalize(?string $input, string $expected): void
    {
        $this->assertSame($expected, BusinessTypes::normalize($input));
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function normalizeProvider(): array
    {
        return [
            'null' => [null, BusinessTypes::OTHER],
            'blank' => ['  ', BusinessTypes::OTHER],
            'restaurant' => ['restaurant', BusinessTypes::RESTAURANT],
            'bakery' => ['Bakery', BusinessTypes::BAKERY],
            'grocery' => ['grocery', BusinessTypes::GROCERY],
            'grocery_store alias' => ['grocery_store', BusinessTypes::GROCERY],
            'butcher' => ['butcher', BusinessTypes::BUTCHER],
            'butchery alias' => ['butchery', BusinessTypes::BUTCHER],
            'meat_shop alias' => ['meat_shop', BusinessTypes::BUTCHER],
            'pharmacy' => ['pharmacy', BusinessTypes::PHARMACY],
            'unknown' => ['cafe', BusinessTypes::OTHER],
        ];
    }

    public function test_type_helpers(): void
    {
        $this->assertTrue(BusinessTypes::isButcher('butchery'));
        $this->assertTrue(BusinessTypes::isGrocery('grocery_store'));
        $this->assertTrue(BusinessTypes::isBakery('bakery'));
        $this->assertTrue(BusinessTypes::isRestaurant('restaurant'));
        $this->assertTrue(BusinessTypes::isPharmacy('pharmacy'));
        $this->assertFalse(BusinessTypes::isRestaurant('bakery'));
    }

    public function test_for_restaurant_prefers_business_type(): void
    {
        $this->assertSame(
            BusinessTypes::GROCERY,
            BusinessTypes::forRestaurant('grocery', 'restaurant'),
        );
        $this->assertSame(
            BusinessTypes::BUTCHER,
            BusinessTypes::forRestaurant(null, 'butchery'),
        );
        $this->assertSame(
            BusinessTypes::OTHER,
            BusinessTypes::forRestaurant(null, null),
        );
    }

    public function test_catalogue_config_keys(): void
    {
        $config = BusinessTypes::catalogueConfig('grocery');
        $this->assertSame('Products', $config['catalogue_label']);
        $this->assertFalse($config['supports_preparation_time']);
        $this->assertFalse($config['supports_modifiers']);
    }
}
