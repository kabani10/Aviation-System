<?php

namespace App\Domain\Services\Policies;

use App\Domain\Services\Models\SupplierInquiry;
use App\Models\User;

/**
 * Reuses services.view/services.manage — same reasoning as ServicePolicy:
 * an inquiry only makes sense in the context of the service it's on, so
 * there's no separate permission pair to mint for it.
 */
class SupplierInquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('services.view');
    }

    public function view(User $user, SupplierInquiry $supplierInquiry): bool
    {
        return $user->can('services.view');
    }

    public function create(User $user): bool
    {
        return $user->can('services.manage');
    }

    public function update(User $user, SupplierInquiry $supplierInquiry): bool
    {
        return $user->can('services.manage');
    }

    /** No hard delete — same convention as every other core record; a mistaken inquiry stays as history. */
    public function delete(User $user, SupplierInquiry $supplierInquiry): bool
    {
        return false;
    }
}
