<?php

namespace App\Domain\Suppliers\Policies;

use App\Domain\Suppliers\Models\SupplierContact;
use App\Models\User;

class SupplierContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function view(User $user, SupplierContact $contact): bool
    {
        return $user->can('suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('suppliers.manage');
    }

    public function update(User $user, SupplierContact $contact): bool
    {
        return $user->can('suppliers.manage');
    }

    public function delete(User $user, SupplierContact $contact): bool
    {
        return $user->can('suppliers.manage');
    }
}
