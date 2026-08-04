<?php

namespace App\Domain\Tenancy\Actions\TwoFactor;

use App\Domain\Tenancy\Services\TwoFactorAuthenticationService;
use App\Models\User;

/**
 * The post-login checkpoint: accepts either a 6-digit TOTP code or a
 * recovery code. A recovery code is consumed on use — it's removed from
 * the stored list so it can't be replayed.
 */
class VerifyTwoFactorChallenge
{
    public function __construct(private readonly TwoFactorAuthenticationService $service) {}

    public function __invoke(User $user, string $code): bool
    {
        if ($this->service->verify($user->two_factor_secret, $code)) {
            return true;
        }

        return $this->tryRecoveryCode($user, $code);
    }

    private function tryRecoveryCode(User $user, string $code): bool
    {
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];

        if (! in_array($code, $recoveryCodes, strict: true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($recoveryCodes, [$code])),
        ])->save();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->log('two_factor_recovery_code_used');

        return true;
    }
}
