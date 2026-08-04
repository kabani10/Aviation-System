<?php

namespace App\Domain\Customers\Models;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\Documents\Concerns\HasDocuments;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A client of the tenant flight support company — the operator or broker
 * a flight request comes from. Not to be confused with App\Domain\Tenancy\
 * Models\Company, which is the tenant itself.
 *
 * "Preferred suppliers" from the original spec isn't modeled here yet — it
 * needs the Supplier module (Phase 4) to be a real relationship rather than
 * a placeholder field that gets thrown away.
 */
#[Fillable(['name', 'billing_email', 'payment_terms', 'special_instructions', 'is_active'])]
class Customer extends Model
{
    use BelongsToCompany, HasCommunications, HasDocuments, HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function aircraft(): HasMany
    {
        return $this->hasMany(Aircraft::class);
    }

    public function displayLabel(): string
    {
        return $this->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'billing_email', 'payment_terms', 'special_instructions', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
