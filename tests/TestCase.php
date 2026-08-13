<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase only runs `migrate:fresh --seeder=...` once per test
     * run (before any test's transaction begins) — everything seeded here is
     * still visible after each test's transaction rolls back, since it was
     * committed before that transaction started. Roles/permissions and
     * reference data (countries, ~10k airports) are read-only fixtures no
     * test mutates, so seeding them once here instead of per test in
     * Pest.php's beforeEach avoids re-inserting all of it on every one of
     * ~260 tests.
     */
    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;
}
