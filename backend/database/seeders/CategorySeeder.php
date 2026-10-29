<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The service catalogue (SPEC section 5.7) — 10 categories, 40 subcategories.
 *
 * This is real master data, not demo fixtures: it is the table everything
 * else depends on, and the same seeder is intended to run on a fresh
 * production database.
 *
 * Idempotent. Keyed on slug, so re-running updates in place rather than
 * duplicating — and never touches subscription_items, which is what makes
 * these rows undeletable once vendors have bought them (SPEC section 10).
 *
 * Subcategories matter more than categories: SPEC section 4.4 matches vendors
 * at the subcategory level, because a vendor who does AC gas filling may not
 * do AC installation.
 */
class CategorySeeder extends Seeder
{
    /**
     * @var array<string, array<int, string>>
     */
    private const CATALOGUE = [
        'AC Service' => [
            'AC Installation',
            'AC Gas Filling',
            'AC Servicing',
            'AC Repair',
        ],
        'Plumbing' => [
            'Pipe Repair',
            'Tap Installation',
            'Drainage Cleaning',
            'Bathroom Fitting',
        ],
        'Electrical' => [
            'House Wiring',
            'Switchboard Repair',
            'Fan Installation',
            'Inverter Installation',
        ],
        'Carpentry' => [
            'Furniture Repair',
            'Door Fitting',
            'Modular Kitchen',
            'Wardrobe Work',
        ],
        'Painting' => [
            'Interior Painting',
            'Exterior Painting',
            'Waterproofing',
            'Texture Work',
        ],
        'Pest Control' => [
            'Cockroach Control',
            'Termite Treatment',
            'Bed Bug Treatment',
            'Mosquito Control',
        ],
        'Appliance Repair' => [
            'Refrigerator Repair',
            'Washing Machine Repair',
            'Microwave Repair',
            'Geyser Repair',
        ],
        'Cleaning' => [
            'Deep Home Cleaning',
            'Sofa Cleaning',
            'Bathroom Cleaning',
            'Water Tank Cleaning',
        ],
        'Home Renovation' => [
            'False Ceiling',
            'Floor Tiling',
            'POP Work',
            'Grill Fabrication',
        ],
        'Packers & Movers' => [
            'Home Shifting',
            'Office Shifting',
            'Vehicle Transport',
            'Storage Service',
        ],
    ];

    public function run(): void
    {
        $sortOrder = 0;

        foreach (self::CATALOGUE as $categoryName => $subcategoryNames) {
            $category = Category::updateOrCreate(
                ['slug' => Str::slug($categoryName)],
                [
                    'name' => $categoryName,
                    'sort_order' => $sortOrder++,
                    'is_active' => true,
                ],
            );

            $subSortOrder = 0;

            foreach ($subcategoryNames as $subcategoryName) {
                Subcategory::updateOrCreate(
                    [
                        // Slugs are unique per category, matching the composite
                        // index — two categories can both have "Servicing".
                        'category_id' => $category->id,
                        'slug' => Str::slug($subcategoryName),
                    ],
                    [
                        'name' => $subcategoryName,
                        'sort_order' => $subSortOrder++,
                        'is_active' => true,
                    ],
                );
            }
        }

        $this->command?->info(
            'Catalogue: '.Category::count().' categories, '.Subcategory::count().' subcategories.'
        );
    }
}
