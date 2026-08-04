<?php

namespace App\Domain\Aircraft\Policies;

use App\Domain\Aircraft\Models\Aircraft;
use App\Models\User;

/**
 * Fleet management rides on the same customers.* permissions as Customer
 * itself — the original spec treats "Customer and Aircraft Management" as
 * one area, not two separately-permissioned ones.
 */
class AircraftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Aircraft $aircraft): bool
    {
        return $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.manage');
    }

    public function update(User $user, Aircraft $aircraft): bool
    {
        return $user->can('customers.manage');
    }

    public function delete(User $user, Aircraft $aircraft): bool
    {
        return false;
    }
}
