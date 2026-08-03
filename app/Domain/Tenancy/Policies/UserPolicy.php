<?php

namespace App\Domain\Tenancy\Policies;

use App\Models\User;

/**
 * Employee management is Admin-only. Every method here returns false —
 * Admin never reaches these checks, it's granted everything up front by the
 * Gate::before hook in AppServiceProvider.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, User $model): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $model): bool
    {
        return false;
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }
}
