<?php

namespace Tests\Unit\Support;

use App\Support\BusinessTypes;
use App\Support\InventoryModes;
use Tests\TestCase;

class InventoryModesTest extends TestCase
{
    public function test_modes_by_business_type(): void
    {
        $this->assertSame(InventoryModes::COUNTED, InventoryModes::forBusinessType(BusinessTypes::GROCERY));
        $this->assertSame(InventoryModes::COUNTED, InventoryModes::forBusinessType(BusinessTypes::BUTCHER));
        $this->assertSame(InventoryModes::COUNTED, InventoryModes::forBusinessType(BusinessTypes::BAKERY));
        $this->assertSame(InventoryModes::BOOLEAN, InventoryModes::forBusinessType(BusinessTypes::RESTAURANT));
        $this->assertTrue(InventoryModes::tracksQuantity('grocery_store'));
        $this->assertFalse(InventoryModes::tracksQuantity(BusinessTypes::RESTAURANT));
    }

    public function test_catalogue_config_includes_inventory_mode(): void
    {
        $grocery = BusinessTypes::catalogueConfig(BusinessTypes::GROCERY);
        $this->assertSame(InventoryModes::COUNTED, $grocery['inventory_mode']);
        $this->assertTrue($grocery['supports_inventory']);

        $restaurant = BusinessTypes::catalogueConfig(BusinessTypes::RESTAURANT);
        $this->assertSame(InventoryModes::BOOLEAN, $restaurant['inventory_mode']);
    }
}
