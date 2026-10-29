<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanQuota;
use Illuminate\Database\Seeder;

/**
 * Subscription plans and their quotas (SPEC section 5.4).
 *
 * The ladder is calibrated against the seeded catalogue rather than picked at
 * random: Platinum's ceilings (40 subcategories, 15 zones) are exactly the
 * whole catalogue and all of Ahmedabad, so the top tier means "everything"
 * against real data instead of an arbitrary number.
 *
 * Prices are integer paise (CLAUDE.md) — 99900 is Rs 999.00. Never a float.
 *
 * priority_rank drives the first tier of customer search ordering
 * (SPEC section 4.5): plan priority first, then rating with a minimum review
 * count, then recency.
 */
class PlanSeeder extends Seeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const PLANS = [
        [
            'name' => 'Silver',
            'slug' => 'silver',
            'description' => 'Entry tier for a single trade in a couple of areas.',
            'price_paise' => 99_900,      // Rs 999.00
            'duration_days' => 365,
            'sort_order' => 1,
            'quota' => [
                'max_categories' => 2,
                'max_subcategories' => 5,
                'max_zones' => 2,
                'max_photos' => 10,
                'max_videos' => 1,
                'priority_rank' => 1,
            ],
        ],
        [
            'name' => 'Gold',
            'slug' => 'gold',
            'description' => 'For established vendors covering several services across the city.',
            'price_paise' => 249_900,     // Rs 2,499.00
            'duration_days' => 365,
            'sort_order' => 2,
            'quota' => [
                'max_categories' => 5,
                'max_subcategories' => 15,
                'max_zones' => 5,
                'max_photos' => 30,
                'max_videos' => 5,
                'priority_rank' => 2,
            ],
        ],
        [
            'name' => 'Platinum',
            'slug' => 'platinum',
            'description' => 'Full catalogue, every zone, top placement in search results.',
            'price_paise' => 499_900,     // Rs 4,999.00
            'duration_days' => 365,
            'sort_order' => 3,
            'quota' => [
                // Matches the seeded catalogue exactly: 40 subcategories and
                // all 15 Ahmedabad sub-zones.
                'max_categories' => 10,
                'max_subcategories' => 40,
                'max_zones' => 15,
                'max_photos' => 100,
                'max_videos' => 20,
                'priority_rank' => 3,
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $definition) {
            $quota = $definition['quota'];
            unset($definition['quota']);

            $plan = Plan::updateOrCreate(
                ['slug' => $definition['slug']],
                $definition + ['is_active' => true],
            );

            // plan_quotas.plan_id is unique, so this is 1:1 by construction.
            PlanQuota::updateOrCreate(['plan_id' => $plan->id], $quota);
        }

        $this->command?->info('Plans: '.Plan::count().' with quotas.');
    }
}
