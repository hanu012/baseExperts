<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a dedicated test database.
     *
     * RefreshDatabase runs migrate:fresh, which drops every table in the
     * configured database. Since the suite moved off SQLite onto real
     * MariaDB, a misconfigured DB_DATABASE would silently destroy the
     * development data in `marketplace` instead of failing.
     *
     * The check runs BEFORE parent::setUp(). That ordering is the whole
     * point: RefreshDatabase is wired up during parent::setUp(), so a check
     * placed after it has already let migrate:fresh drop the tables it was
     * meant to protect. Verified by pointing a run at a throwaway database
     * and confirming it comes back untouched.
     */
    protected function setUp(): void
    {
        $this->guardAgainstNonTestDatabase();

        parent::setUp();

        // Belt and braces: confirm the booted connection resolved to the same
        // place the environment promised, in case config caching or a service
        // provider redirected it.
        $this->assertConnectionIsTestDatabase(DB::connection()->getDatabaseName());
    }

    /**
     * Pre-boot check, reading the environment directly because the
     * application - and therefore config() and DB() - does not exist yet.
     */
    private function guardAgainstNonTestDatabase(): void
    {
        $database = getenv('DB_DATABASE');

        if ($database === false) {
            $database = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? null;
        }

        // Fails closed: an unset value is treated as unsafe rather than
        // assumed harmless.
        $this->assertConnectionIsTestDatabase(
            is_string($database) ? $database : '(not set)'
        );
    }

    private function assertConnectionIsTestDatabase(string $database): void
    {
        if (str_ends_with($database, '_testing')) {
            return;
        }

        throw new RuntimeException(
            "Refusing to run tests against database [{$database}]. "
            .'The test database name must end with "_testing" — '
            .'RefreshDatabase drops every table in it. '
            .'Check the DB_DATABASE value in phpunit.xml.'
        );
    }
}
