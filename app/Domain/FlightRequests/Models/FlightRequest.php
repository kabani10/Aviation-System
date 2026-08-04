<?php

namespace App\Domain\FlightRequests\Models;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\Customers\Models\Customer;
use App\Domain\Documents\Concerns\HasDocuments;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The central record — everyone working an operation opens this one page.
 * Costs/selling prices at the flight level aren't here yet: aggregate
 * flight profitability needs Quotation (Phase 10) and Finance (Phase 12).
 * Per-service cost/selling price exist as of Phase 6 (see Service) —
 * `requested_services_summary` stays as the freeform original ask
 * ("handling, fuel, permits...") even after it's broken into real Service
 * records, since it's the customer's own words, not something to overwrite.
 */
#[Fillable([
    'customer_id', 'aircraft_id', 'callsign', 'origin_airport_id', 'destination_airport_id',
    'departure_at', 'arrival_at', 'passenger_count', 'crew_count', 'status',
    'special_instructions', 'requested_services_summary',
])]
class FlightRequest extends Model
{
    use BelongsToCompany, HasCommunications, HasDocuments, HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'status' => FlightStatus::class,
            'departure_at' => 'datetime',
            'arrival_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function displayLabel(): string
    {
        $route = "{$this->originAirport->icao_code}-{$this->destinationAirport->icao_code}";

        return $this->callsign ? "{$this->callsign} ({$route})" : $route;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'customer_id', 'aircraft_id', 'callsign', 'origin_airport_id', 'destination_airport_id',
                'departure_at', 'arrival_at', 'passenger_count', 'crew_count', 'status',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
