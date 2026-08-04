<?php

namespace App\Domain\Suppliers\Policies;

use App\Domain\Suppliers\Models\Supplier;
use App\Models\User;

/**
 * suppliers.view / suppliers.manage already existed in
 * RolesAndPermissionsSeeder since Phase 1 (Operations and Management get
 * view-only, Procurement gets both) — this is the first module to actually
 * consume them.
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('suppliers.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.manage');
    }

    /** Deactivate (is_active) instead — same convention as employees/customers. */
    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }
}
