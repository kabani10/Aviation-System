<?php

namespace App\Domain\Communications\Concerns;

use App\Domain\Communications\Models\Communication;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Applied to any model that has a timeline (Company, User today; Customer,
 * Supplier, FlightRequest as those modules are built).
 */
trait HasCommunications
{
    public function communications(): MorphMany
    {
        return $this->morphMany(Communication::class, 'communicable')->latest('occurred_at');
    }
}
