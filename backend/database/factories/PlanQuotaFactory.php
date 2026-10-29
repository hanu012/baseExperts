<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanQuota;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanQuota>
 */
class PlanQuotaFactory extends Factory
{
    protected $model = PlanQuota::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'max_categories' => fake()->numberBetween(1, 10),
            'max_subcategories' => fake()->numberBetween(1, 30),
            'max_zones' => fake()->numberBetween(1, 10),
            'max_photos' => fake()->numberBetween(5, 50),
            'max_videos' => fake()->numberBetween(0, 10),
            'priority_rank' => fake()->numberBetween(0, 5),
        ];
    }
}
