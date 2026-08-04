<?php

namespace App\Domain\Customers\Models;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\Documents\Concerns\HasDocuments;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Domain\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A client of the tenant flight support company — the operator or broker
 * a flight request comes from. Not to be confused with App\Domain\Tenancy\
 * Models\Company, which is the tenant itself.
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

    public function flightRequests(): HasMany
    {
        return $this->hasMany(FlightRequest::class);
    }

    /** Closes the gap flagged in Phase 3 — needed Supplier (Phase 4) to be a real relationship. */
    public function preferredSuppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'customer_preferred_supplier');
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
