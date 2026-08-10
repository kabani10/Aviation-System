<?php

namespace App\Domain\FlightRequests\Models;

use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One origin-to-destination hop of a FlightRequest — DXB-IST, then IST-CDG,
 * for a two-stop trip. Every FlightRequest has at least one leg; a plain
 * one-way flight is simply a FlightRequest with a single leg, not a
 * separate code path (see FlightRequest's own docblock). Services belong
 * here, not to the FlightRequest directly — ground handling at IST and
 * ground handling at CDG are different suppliers, costs, and confirmations,
 * not one flat list.
 */
#[Fillable(['sequence', 'origin_airport_id', 'destination_airport_id', 'departure_at', 'arrival_at'])]
class FlightLeg extends Model
{
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'arrival_at' => 'datetime',
        ];
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(FlightRequest::class);
    }

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function displayLabel(): string
    {
        return "Leg {$this->sequence}: {$this->originAirport->icao_code} \u{2192} {$this->destinationAirport->icao_code}";
    }
}
