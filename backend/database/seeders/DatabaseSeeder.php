<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything a fresh database needs to be usable:
 *
 *   php artisan migrate:fresh --seed
 *
 * leaves you able to log into the admin panel and browse a populated
 * catalogue immediately.
 *
 * All of this is real master data, not demo fixtures — the same seeders are
 * intended to run against a fresh production database. Every one is
 * idempotent (keyed on slug, or on email for the admin), so re-running
 * updates in place rather than duplicating.
 *
 * NOTE — WithoutModelEvents is deliberately NOT used here. Laravel's stub
 * includes it, but this project's models depend on model events for
 * correctness: TracksFileDisk stamps the `disk` column on creating/updating,
 * and suppressing that would seed rows whose recorded storage location is
 * null. Order matters below only in that the admin comes first, so a failure
 * later still leaves a usable login.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            CategorySeeder::class,
            ZoneSeeder::class,
            PlanSeeder::class,
            SettingSeeder::class,
            CmsPageSeeder::class,
            DocumentTypeSeeder::class,
        ]);
    }
}
