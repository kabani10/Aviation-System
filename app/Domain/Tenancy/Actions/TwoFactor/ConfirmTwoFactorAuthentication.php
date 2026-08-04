<?php

namespace App\Domain\Tenancy\Actions\TwoFactor;

use App\Domain\Tenancy\Services\TwoFactorAuthenticationService;
use App\Models\User;
use RuntimeException;

/**
 * @return list<string> the recovery codes — the only time they're available in plain text
 */
class ConfirmTwoFactorAuthentication
{
    public function __construct(private readonly TwoFactorAuthenticationService $service) {}

    public function __invoke(User $user, string $code): array
    {
        if (! $user->two_factor_secret) {
            throw new RuntimeException('Two-factor setup was not started for this user.');
        }

        if (! $this->service->verify($user->two_factor_secret, $code)) {
            throw new InvalidTwoFactorCodeException;
        }

        $recoveryCodes = $this->service->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->log('two_factor_enabled');

        return $recoveryCodes;
    }
}
