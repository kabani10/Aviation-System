<?php

namespace App\Domain\Customers\Policies;

use App\Domain\Customers\Models\CustomerContact;
use App\Models\User;

class CustomerContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, CustomerContact $contact): bool
    {
        return $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.manage');
    }

    public function update(User $user, CustomerContact $contact): bool
    {
        return $user->can('customers.manage');
    }

    public function delete(User $user, CustomerContact $contact): bool
    {
        return $user->can('customers.manage');
    }
}
