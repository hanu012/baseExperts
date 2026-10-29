<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // unique() goes on the number, not the word list: unique()->
        // randomElement over eight options exhausts after eight rows and then
        // throws OverflowException.
        $name = fake()->randomElement([
            'AC Repair', 'Plumbing', 'Electrical', 'Carpentry', 'Painting',
            'Pest Control', 'Appliance Repair', 'Cleaning',
        ]).' '.fake()->unique()->numberBetween(1, 999999);

        return [
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
