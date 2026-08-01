<?php

namespace Database\Seeders;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SuvakamanaRestaurantSeeder extends Seeder
{
    public function run(): void
    {
        $slug = config('suvakamana.platform_restaurant_slug', 'suvakamana-restaurant');
        $name = config('suvakamana.platform_restaurant_name', 'Suvakamana Restaurant');

        $restaurant = Restaurant::query()->firstOrCreate(['slug' => $slug], [
            'public_id' => (string) Str::uuid(),
            'legal_business_name' => $name,
            'trading_name' => $name,
            'ownership_type' => 'third_party',
            'is_platform_restaurant' => true,
            'vendor_type' => 'restaurant',
            'status' => RestaurantStatus::Active,
            'verification_status' => 'verified',
            'accepting_orders' => true,
            'published_at' => now(),
            'currency' => env('SUVAKAMANA_RESTAURANT_CURRENCY', 'AUD'),
            'timezone' => env('SUVAKAMANA_RESTAURANT_TIMEZONE', 'Australia/Sydney'),
            'pickup_enabled' => true,
            'restaurant_delivery_enabled' => true,
            'third_party_delivery_enabled' => false,
            'minimum_order_cents' => 2000,
            'average_preparation_minutes' => 25,
        ]);

        $restaurant->update([
            'ownership_type' => 'third_party',
            'is_platform_restaurant' => true,
            'vendor_type' => 'restaurant',
            'cover_urls' => $this->imageSet(
                'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=1600&q=80'
            ),
            'logo_urls' => $this->imageSet(
                'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?auto=format&fit=crop&w=400&q=80'
            ),
            'cover_image_path' => 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=1600&q=80',
            'logo_path' => 'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?auto=format&fit=crop&w=400&q=80',
        ]);

        $this->seedHours($restaurant);
        $this->seedMenu($restaurant);
    }

    /** @return array{thumbnail: string, card: string, large: string, original: string} */
    private function imageSet(string $url): array
    {
        return [
            'thumbnail' => $url,
            'card' => $url,
            'large' => $url,
            'original' => $url,
        ];
    }

    private function seedHours(Restaurant $restaurant): void
    {
        for ($d = 0; $d < 7; $d++) {
            RestaurantOpeningHour::query()->firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'day_of_week' => $d, 'service_type' => 'all', 'is_closed' => false],
                ['opens_at' => '10:00', 'closes_at' => '22:00']
            );
        }
    }

    private function seedMenu(Restaurant $restaurant): void
    {
        $menu = Menu::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'is_default' => true],
            ['public_id' => (string) Str::uuid(), 'name' => 'Main Menu', 'status' => 'active']
        );

        $categories = [
            'Popular' => [
                ['name' => 'Momo Platter', 'slug' => 'momo-platter', 'price' => 1850, 'desc' => 'Assorted steamed dumplings with sesame-tomato chutney', 'featured' => true, 'vegetarian' => false, 'image' => 'https://images.unsplash.com/photo-1496116218417-1a781b1c416c?auto=format&fit=crop&w=800&q=80', 'variants' => [['Steamed', 1850], ['Fried', 1990], ['Jhol (soup)', 2050]]],
                ['name' => 'Chicken Tikka', 'slug' => 'chicken-tikka', 'price' => 2190, 'desc' => 'Tandoori-spiced chicken thigh pieces with mint raita', 'featured' => true, 'image' => 'https://images.unsplash.com/photo-1603894584372-c003cd5307ee?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Butter Chicken', 'slug' => 'butter-chicken', 'price' => 2490, 'desc' => 'Rich tomato-cream curry with tender chicken', 'featured' => true, 'image' => 'https://images.unsplash.com/photo-1588168333986-5078d3ae3976?auto=format&fit=crop&w=800&q=80'],
            ],
            'Entrées' => [
                ['name' => 'Samosa', 'slug' => 'samosa', 'price' => 890, 'desc' => 'Crisp pastry filled with spiced potato and peas', 'vegetarian' => true, 'image' => 'https://images.unsplash.com/photo-1601050690597-df0568f70950?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Pakora', 'slug' => 'pakora', 'price' => 950, 'desc' => 'Chickpea-battered seasonal vegetables', 'vegetarian' => true, 'gluten_free' => true, 'image' => 'https://images.unsplash.com/photo-1565557623262-b51c2513a641?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Aloo Tikki', 'slug' => 'aloo-tikki', 'price' => 790, 'desc' => 'Spiced potato patties with tamarind', 'vegetarian' => true, 'image' => 'https://images.unsplash.com/photo-1606491956689-2ea866880c84?auto=format&fit=crop&w=800&q=80'],
            ],
            'Main Courses' => [
                ['name' => 'Lamb Rogan Josh', 'slug' => 'lamb-rogan-josh', 'price' => 2690, 'desc' => 'Slow-cooked lamb in aromatic Kashmiri spices', 'image' => 'https://images.unsplash.com/photo-1546833999-b9f581a1996d?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Chicken Biryani', 'slug' => 'chicken-biryani', 'price' => 2290, 'desc' => 'Fragrant basmati rice layered with spiced chicken', 'featured' => true, 'image' => 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Prawn Masala', 'slug' => 'prawn-masala', 'price' => 2890, 'desc' => 'Tiger prawns in coconut-tomato sauce', 'image' => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=800&q=80'],
            ],
            'Momo' => [
                ['name' => 'Chicken Momo', 'slug' => 'chicken-momo', 'price' => 1590, 'desc' => 'Hand-folded chicken dumplings', 'modifiers' => true, 'image' => 'https://images.unsplash.com/photo-1534422298391-e4f8c172dddb?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Vegetable Momo', 'slug' => 'veg-momo', 'price' => 1390, 'desc' => 'Seasonal vegetable dumplings', 'vegetarian' => true, 'modifiers' => true, 'image' => 'https://images.unsplash.com/photo-1496116218417-1a781b1c416c?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Pork Momo', 'slug' => 'pork-momo', 'price' => 1690, 'desc' => 'Spiced pork dumplings with chilli oil', 'modifiers' => true, 'image' => 'https://images.unsplash.com/photo-1496116218417-1a781b1c416c?auto=format&fit=crop&w=800&q=80'],
            ],
            'Rice and Noodles' => [
                ['name' => 'Jeera Rice', 'slug' => 'jeera-rice', 'price' => 590, 'desc' => 'Cumin-tempered basmati rice', 'vegetarian' => true, 'vegan' => true, 'gluten_free' => true, 'image' => 'https://images.unsplash.com/photo-1516684669134-de6f7c473a2a?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Chow Mein', 'slug' => 'chow-mein', 'price' => 1490, 'desc' => 'Stir-fried noodles with vegetables and soy', 'image' => 'https://images.unsplash.com/photo-1552611052-33e04de081de?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Garlic Naan', 'slug' => 'garlic-naan', 'price' => 490, 'desc' => 'Tandoori bread with roasted garlic', 'vegetarian' => true, 'image' => 'https://images.unsplash.com/photo-1565557623262-b51c2513a641?auto=format&fit=crop&w=800&q=80'],
            ],
            'Vegetarian' => [
                ['name' => 'Dal Bhat', 'slug' => 'dal-bhat', 'price' => 1690, 'desc' => 'Traditional lentil curry with steamed rice, achar and greens', 'vegetarian' => true, 'vegan' => true, 'gluten_free' => true, 'featured' => true, 'image' => 'https://images.unsplash.com/photo-1546833999-b9f581a1996d?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Paneer Tikka Masala', 'slug' => 'paneer-tikka-masala', 'price' => 2190, 'desc' => 'Grilled paneer in spiced tomato gravy', 'vegetarian' => true, 'image' => 'https://images.unsplash.com/photo-1631452180519-c014fe946bc7?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Chana Masala', 'slug' => 'chana-masala', 'price' => 1590, 'desc' => 'Chickpea curry with roasted spices', 'vegetarian' => true, 'vegan' => true, 'image' => 'https://images.unsplash.com/photo-1585937421612-70a008356fbe?auto=format&fit=crop&w=800&q=80'],
            ],
            'Drinks' => [
                ['name' => 'Mango Lassi', 'slug' => 'mango-lassi', 'price' => 690, 'desc' => 'Creamy yoghurt blended with Alphonso mango', 'vegetarian' => true, 'image' => 'https://images.unsplash.com/photo-1527661591475-527312dd65f5?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Masala Chai', 'slug' => 'masala-chai', 'price' => 490, 'desc' => 'Spiced milk tea with cardamom and ginger', 'vegetarian' => true, 'image' => 'https://images.unsplash.com/photo-1571934811356-5cc061b6821f?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Fresh Lime Soda', 'slug' => 'lime-soda', 'price' => 450, 'desc' => 'Sparkling lime with mint', 'vegetarian' => true, 'vegan' => true, 'image' => 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?auto=format&fit=crop&w=800&q=80'],
            ],
            'Desserts' => [
                ['name' => 'Gulab Jamun', 'slug' => 'gulab-jamun', 'price' => 790, 'desc' => 'Milk-solid dumplings in rose-cardamom syrup', 'vegetarian' => true, 'image' => 'https://images.unsplash.com/photo-1571115177098-24ec42ed204d?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Kheer', 'slug' => 'kheer', 'price' => 690, 'desc' => 'Cardamom rice pudding with pistachios', 'vegetarian' => true, 'gluten_free' => true, 'image' => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?auto=format&fit=crop&w=800&q=80'],
                ['name' => 'Jalebi', 'slug' => 'jalebi', 'price' => 590, 'desc' => 'Crispy saffron-soaked spirals', 'vegetarian' => true, 'image' => 'https://images.unsplash.com/photo-1606471191009-639494b524a0?auto=format&fit=crop&w=800&q=80'],
            ],
        ];

        $spiceGroup = ModifierGroup::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Spice Level'],
            [
                'public_id' => (string) Str::uuid(),
                'selection_type' => 'single',
                'minimum_selections' => 1,
                'maximum_selections' => 1,
                'is_required' => true,
            ]
        );
        foreach ([['Mild', 0], ['Medium', 0], ['Hot', 0], ['Extra Hot', 100]] as $i => $opt) {
            ModifierOption::query()->firstOrCreate(
                ['modifier_group_id' => $spiceGroup->id, 'name' => $opt[0]],
                [
                    'public_id' => (string) Str::uuid(),
                    'restaurant_id' => $restaurant->id,
                    'price_adjustment_cents' => $opt[1],
                    'is_default' => $i === 1,
                    'sort_order' => $i,
                ]
            );
        }

        $styleGroup = ModifierGroup::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Cooking Style'],
            [
                'public_id' => (string) Str::uuid(),
                'selection_type' => 'single',
                'minimum_selections' => 1,
                'maximum_selections' => 1,
                'is_required' => true,
            ]
        );
        foreach ([['Steamed', 0], ['Fried', 200], ['Jhol (soup)', 200]] as $i => $opt) {
            ModifierOption::query()->firstOrCreate(
                ['modifier_group_id' => $styleGroup->id, 'name' => $opt[0]],
                [
                    'public_id' => (string) Str::uuid(),
                    'restaurant_id' => $restaurant->id,
                    'price_adjustment_cents' => $opt[1],
                    'is_default' => $i === 0,
                    'sort_order' => $i,
                ]
            );
        }

        $sortOrder = 0;
        foreach ($categories as $catName => $items) {
            $category = MenuCategory::query()->firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'name' => $catName],
                [
                    'public_id' => (string) Str::uuid(),
                    'menu_id' => $menu->id,
                    'is_active' => true,
                    'sort_order' => $sortOrder++,
                ]
            );

            foreach ($items as $idx => $data) {
                $item = MenuItem::query()->firstOrCreate(
                    ['restaurant_id' => $restaurant->id, 'slug' => $data['slug']],
                    [
                        'public_id' => (string) Str::uuid(),
                        'menu_id' => $menu->id,
                        'menu_category_id' => $category->id,
                        'name' => $data['name'],
                        'short_description' => $data['desc'],
                        'base_price_cents' => $data['price'],
                        'cost_price_cents' => (int) ($data['price'] * 0.35),
                        'is_active' => true,
                        'is_available' => true,
                        'is_featured' => $data['featured'] ?? false,
                        'is_vegetarian' => $data['vegetarian'] ?? false,
                        'is_vegan' => $data['vegan'] ?? false,
                        'is_gluten_free' => $data['gluten_free'] ?? false,
                        'sort_order' => $idx,
                        'image_path' => $data['image'] ?? null,
                        'image_urls' => isset($data['image']) ? $this->imageSet($data['image']) : null,
                    ]
                );

                if (! empty($data['image'])) {
                    $item->update([
                        'image_path' => $data['image'],
                        'image_urls' => $this->imageSet($data['image']),
                    ]);
                }

                if (! empty($data['variants'])) {
                    foreach ($data['variants'] as $vi => $v) {
                        MenuItemVariant::query()->firstOrCreate(
                            ['menu_item_id' => $item->id, 'name' => $v[0]],
                            [
                                'public_id' => (string) Str::uuid(),
                                'restaurant_id' => $restaurant->id,
                                'price_cents' => $v[1],
                                'is_default' => $vi === 0,
                                'sort_order' => $vi,
                            ]
                        );
                    }
                }

                if (! empty($data['modifiers'])) {
                    $item->modifierGroups()->syncWithoutDetaching([$styleGroup->id, $spiceGroup->id]);
                }
            }
        }
    }
}
