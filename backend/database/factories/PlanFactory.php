<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Starter', 'Standard', 'Growth', 'Premium'])
            .' '.fake()->unique()->numberBetween(1, 999999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            // Paise. 99900 = Rs 999.00
            'price_paise' => fake()->randomElement([49900, 99900, 199900, 499900]),
            'duration_days' => fake()->randomElement([30, 90, 180, 365]),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
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

    public function priceInRupees(float|string $rupees): static
    {
        return $this->state(fn (array $attributes) => [
            'price_paise' => Plan::rupeesToPaise($rupees),
        ]);
    }

    /**
     * Plans are meaningless without their quota row, so make one by default
     * unless a test deliberately wants a plan without it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Plan $plan) {
            if (! $plan->quota()->exists()) {
                PlanQuotaFactory::new()->create(['plan_id' => $plan->id]);
            }
        });
    }
}
