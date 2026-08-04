<?php

namespace App\Domain\Services\Policies;

use App\Domain\Services\Models\Service;
use App\Models\User;

/**
 * services.view / services.manage already existed since Phase 1. Cost/price
 * field-level visibility within an authorized view is a separate, finer
 * check — see ServicesRelationManager, gated on finance.view_costs /
 * finance.view_prices rather than duplicated here.
 */
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('services.view');
    }

    public function view(User $user, Service $service): bool
    {
        return $user->can('services.view');
    }

    public function create(User $user): bool
    {
        return $user->can('services.manage');
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can('services.manage');
    }

    /** Cancel (status) instead — same "no hard delete" convention as every other core record. */
    public function delete(User $user, Service $service): bool
    {
        return false;
    }
}
