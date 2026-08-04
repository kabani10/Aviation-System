<?php

namespace App\Domain\Tenancy\Actions\TwoFactor;

use App\Models\User;

class DisableTwoFactorAuthentication
{
    public function __invoke(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->log('two_factor_disabled');
    }
}
