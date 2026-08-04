<?php

namespace App\Domain\Documents\Concerns;

use App\Domain\Documents\Models\Document;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Applied to any model that files can be attached to (Company, User today;
 * Customer, Supplier, Aircraft, FlightRequest, Communication as those
 * modules are built). Pair with a Filament DocumentsRelationManager on the
 * host's resource once one exists — see DocumentResource for the pattern.
 */
trait HasDocuments
{
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
