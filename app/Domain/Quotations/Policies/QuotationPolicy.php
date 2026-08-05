<?php

namespace App\Domain\Quotations\Policies;

use App\Domain\Quotations\Models\Quotation;
use App\Models\User;

/** quotations.view / quotations.manage existed unused in RolesAndPermissionsSeeder since Phase 1, waiting for this module to exist. */
class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotations.view');
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('quotations.manage');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.manage');
    }

    /** No hard delete — a rejected or superseded quotation stays as history, same convention as every other core record. */
    public function delete(User $user, Quotation $quotation): bool
    {
        return false;
    }
}
