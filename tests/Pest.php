<?php

use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Roles/permissions and reference data (countries/airports) are the same
    // for every tenant, so every Feature test gets them for free instead of
    // re-seeding per test.
    ->beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * An Admin belonging to $company, with 2FA already confirmed — most tests
 * aren't about 2FA and would otherwise get redirected to the setup page on
 * every panel request. Pair with ->withSession(['2fa_passed' => true]) to
 * also clear the post-login challenge.
 */
function adminFor(Company $company): User
{
    $admin = User::factory()->for($company)->withTwoFactorConfirmed()->create();
    $admin->assignRole('Admin');

    return $admin;
}

/** A Sales user for $company — doesn't need the 2FA dance, only Admin is required to have it. */
function salesUserFor(Company $company): User
{
    return userWithRoleFor($company, 'Sales');
}

/** Any non-Admin role for $company — none of the other five need 2FA confirmed. */
function userWithRoleFor(Company $company, string $role): User
{
    $user = User::factory()->for($company)->create();
    $user->assignRole($role);

    return $user;
}
