<?php

namespace App\Domain\Customers\Policies;

use App\Domain\Customers\Models\Customer;
use App\Models\User;

/**
 * First policy in the app built on granular spatie permissions
 * (customers.view / customers.manage) rather than a hardcoded role name —
 * see RolesAndPermissionsSeeder for who currently has them (Sales only;
 * Admin bypasses via Gate::before regardless).
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage');
    }

    /** Deactivate (is_active) instead — same convention as employees. */
    public function delete(User $user, Customer $customer): bool
    {
        return false;
    }
}
