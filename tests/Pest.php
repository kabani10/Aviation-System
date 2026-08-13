<?php

use App\Domain\Tenancy\Models\Company;
use App\Models\User;
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

// Roles/permissions and reference data (countries/airports) are seeded once
// for the whole run by TestCase's $seed/$seeder properties, not per test —
// see the docblock there.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
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
 * An Admin belonging to $company, with 2FA already confirmed — Admin
 * accounts aren't required to have 2FA enabled, but plenty of tests want a
 * realistic one that does. Pair with ->withSession(['2fa_passed' => true])
 * so EnsureTwoFactorChallengeCompleted doesn't stop it at the challenge.
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
