<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Roles/permissions are the same across every tenant, so they seed here.
     * Companies and their users are created through registration, not seeding
     * — see RegisterCompany and the factories used in tests.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
