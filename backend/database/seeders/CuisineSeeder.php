<?php

namespace Database\Seeders;

use App\Models\Cuisine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CuisineSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['name' => 'Nepalese', 'slug' => 'nepalese'],
            ['name' => 'Indian', 'slug' => 'indian'],
            ['name' => 'Thai', 'slug' => 'thai'],
            ['name' => 'Japanese', 'slug' => 'japanese'],
            ['name' => 'Modern Australian', 'slug' => 'modern-australian'],
        ];

        foreach ($items as $index => $item) {
            Cuisine::query()->firstOrCreate(
                ['slug' => $item['slug']],
                [
                    'name' => $item['name'],
                    'description' => 'Development cuisine: '.$item['name'],
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
