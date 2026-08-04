<?php

namespace App\Domain\FlightRequests\Policies;

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Models\User;

/**
 * flights.view / flights.manage already existed in RolesAndPermissionsSeeder
 * since Phase 1. Sales gained flights.manage in this phase (see the seeder
 * comment) — everyone else with flights.manage was already Operations.
 */
class FlightRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('flights.view');
    }

    public function view(User $user, FlightRequest $flightRequest): bool
    {
        return $user->can('flights.view');
    }

    public function create(User $user): bool
    {
        return $user->can('flights.manage');
    }

    public function update(User $user, FlightRequest $flightRequest): bool
    {
        return $user->can('flights.manage');
    }

    /** Cancel (status) instead — same "no hard delete" convention as every other core record. */
    public function delete(User $user, FlightRequest $flightRequest): bool
    {
        return false;
    }
}
