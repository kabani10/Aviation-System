<?php

namespace App\Domain\Tenancy\Actions\TwoFactor;

use App\Domain\Tenancy\Services\TwoFactorAuthenticationService;
use App\Models\User;

class RegenerateRecoveryCodes
{
    public function __construct(private readonly TwoFactorAuthenticationService $service) {}

    /**
     * @return list<string>
     */
    public function __invoke(User $user): array
    {
        $codes = $this->service->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->log('two_factor_recovery_codes_regenerated');

        return $codes;
    }
}
