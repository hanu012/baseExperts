<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subcategory>
 */
class SubcategoryFactory extends Factory
{
    protected $model = Subcategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // See CategoryFactory: unique() belongs on the number, not the list.
        $name = fake()->randomElement([
            'Gas Filling', 'Installation', 'Servicing', 'Leak Repair',
            'Rewiring', 'Fitting', 'Deep Clean', 'Inspection',
        ]).' '.fake()->unique()->numberBetween(1, 999999);

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'icon' => null,
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function sortedAt(int $order): static
    {
        return $this->state(fn (array $attributes) => ['sort_order' => $order]);
    }
}
