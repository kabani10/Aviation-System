<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Bypasses RequireTwoFactorForAdmins for tests that aren't about 2FA
     * itself — an Admin with unconfirmed 2FA gets redirected to setup
     * before reaching anything else in the panel.
     */
    public function withTwoFactorConfirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
