<?php

namespace App\Domain\ReferenceData\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared across every tenant, not owned by one — deliberately does NOT use
 * BelongsToCompany. Seeder-managed only (see ReferenceDataSeeder); there's
 * no Filament resource for this in the tenant panel, because any tenant's
 * Admin editing it would corrupt data every other tenant reads. Exposed
 * read-only wherever a country/airport picker is needed.
 */
#[Fillable(['code', 'name'])]
class Country extends Model
{
    public function airports(): HasMany
    {
        return $this->hasMany(Airport::class);
    }
}
