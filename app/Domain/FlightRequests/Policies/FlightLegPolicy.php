<?php

namespace App\Domain\FlightRequests\Policies;

use App\Domain\FlightRequests\Models\FlightLeg;
use App\Models\User;

/**
 * A leg is part of the flight's own route, not a separate module — same
 * permission that governs the FlightRequest itself, not a dedicated
 * flight_legs.* pair. Delete is allowed (unlike Service/Customer's "no hard
 * delete" convention) since a leg is structural, correctable data, not a
 * business record with its own history; LegsRelationManager still refuses
 * to delete the last remaining leg or one with services attached.
 */
class FlightLegPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('flights.view');
    }

    public function view(User $user, FlightLeg $leg): bool
    {
        return $user->can('flights.view');
    }

    public function create(User $user): bool
    {
        return $user->can('flights.manage');
    }

    public function update(User $user, FlightLeg $leg): bool
    {
        return $user->can('flights.manage');
    }

    public function delete(User $user, FlightLeg $leg): bool
    {
        return $user->can('flights.manage');
    }
}
