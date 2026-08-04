<?php

namespace App\Domain\Tenancy\Actions\TwoFactor;

use App\Domain\Tenancy\Services\TwoFactorAuthenticationService;
use App\Models\User;

/**
 * Starts setup: generates and stores a secret, but two_factor_confirmed_at
 * stays null until ConfirmTwoFactorAuthentication verifies the user can
 * actually produce a valid code — an unconfirmed secret does not count as
 * "2FA enabled" anywhere else in the app.
 */
class EnableTwoFactorAuthentication
{
    public function __construct(private readonly TwoFactorAuthenticationService $service) {}

    public function __invoke(User $user): string
    {
        $secret = $this->service->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }
}
