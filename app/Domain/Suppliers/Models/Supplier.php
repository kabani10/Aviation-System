<?php

namespace App\Domain\Suppliers\Models;

use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\Documents\Concerns\HasDocuments;
use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Domain\Shared\Enums\ServiceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A vendor the tenant works with — ground handling, fuel, permits, etc.
 * "Average response time" / "service quality" / "previous prices" from the
 * original spec aren't modeled here: they're computed from real supplier
 * interactions (quotes, confirmations) that don't exist until Service
 * Management (Phase 6) — a static rating field now would just be a number
 * nobody updates.
 */
#[Fillable(['name', 'currency', 'payment_terms', 'services_offered', 'notes', 'is_active'])]
class Supplier extends Model
{
    use BelongsToCompany, HasCommunications, HasDocuments, HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'services_offered' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function airports(): BelongsToMany
    {
        return $this->belongsToMany(Airport::class);
    }

    /** @return array<ServiceType> */
    public function serviceTypes(): array
    {
        return array_map(
            fn (string $value) => ServiceType::from($value),
            $this->services_offered ?? [],
        );
    }

    public function displayLabel(): string
    {
        return $this->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'currency', 'payment_terms', 'services_offered', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
