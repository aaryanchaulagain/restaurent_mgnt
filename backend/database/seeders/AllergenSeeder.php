<?php

namespace Database\Seeders;

use App\Models\Allergen;
use Illuminate\Database\Seeder;

class AllergenSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Peanuts', 'slug' => 'peanuts'],
            ['name' => 'Tree nuts', 'slug' => 'tree-nuts'],
            ['name' => 'Milk', 'slug' => 'milk'],
            ['name' => 'Eggs', 'slug' => 'eggs'],
            ['name' => 'Wheat', 'slug' => 'wheat'],
            ['name' => 'Soy', 'slug' => 'soy'],
            ['name' => 'Fish', 'slug' => 'fish'],
            ['name' => 'Shellfish', 'slug' => 'shellfish'],
            ['name' => 'Sesame', 'slug' => 'sesame'],
        ] as $row) {
            Allergen::query()->firstOrCreate(
                ['slug' => $row['slug']],
                ['name' => $row['name'], 'is_active' => true],
            );
        }
    }
}
