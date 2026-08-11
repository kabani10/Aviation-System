<?php

namespace App\Domain\FlightRequests\Models;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\Customers\Models\Customer;
use App\Domain\Documents\Concerns\HasDocuments;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Enums\RequestSource;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Services\Models\Service;
use App\Domain\Services\Models\SupplierInquiry;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The central record — everyone working an operation opens this one page.
 * Route and timing live on FlightLeg, not here (see FlightLeg's docblock):
 * every FlightRequest has at least one leg, created together with it, so
 * there's no "flight with no route yet" state to account for. Aggregate
 * profitability across services lives on `Quotation` (Phase 10) and
 * `Invoice` (Phase 12), not here — a flight can have zero, one, or several
 * of each over time (a rejected quote superseded by a revised one), so
 * there's no single "the" total to put on this record. Cross-flight
 * financial reporting is `ComputeFinancialSummary` (Phase 12), which
 * aggregates across every flight's invoices rather than living here
 * either. `requested_services_summary` stays as the freeform original ask
 * ("handling, fuel, permits...") even after it's broken into real Service
 * records, since it's the customer's own words, not something to overwrite.
 */
#[Fillable([
    'customer_id', 'aircraft_id', 'callsign', 'passenger_count', 'crew_count', 'status',
    'special_instructions', 'requested_services_summary',
    // source/extraction_metadata are set only by CreateFlightRequestFromExtraction,
    // never a form — see the "mass-assignment protection is about forms,
    // not about hiding a field from your own Actions" note in ARCHITECTURE.md.
    'source', 'reviewed_at', 'extraction_metadata',
    // Set only by MarkFlightInOperation/CompleteFlight, same reasoning.
    'operation_started_at', 'completed_at',
])]
class FlightRequest extends Model
{
    use BelongsToCompany, HasCommunications, HasDocuments, HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'status' => FlightStatus::class,
            'source' => RequestSource::class,
            'reviewed_at' => 'datetime',
            'extraction_metadata' => 'array',
            'operation_started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(FlightLeg::class)->orderBy('sequence');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Every SupplierInquiry across every service on this flight — the
     * SupplierInquiriesRelationManager tab reads this, the same "through
     * the denormalized flight_request_id column" shortcut services()
     * itself is built on (see the class docblock and FlightLeg's), not a
     * join through legs.
     */
    public function supplierInquiries(): HasManyThrough
    {
        return $this->hasManyThrough(SupplierInquiry::class, Service::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** The earliest leg's departure — what "when does this flight go" means for a multi-leg trip. */
    public function earliestDepartureAt(): ?Carbon
    {
        return $this->legs->min('departure_at');
    }

    /**
     * Chains each leg's origin/destination into one route string —
     * "DXB-IST-CDG" for a two-leg trip, "KJFK-EGLL" for a one-way, same
     * format as before legs existed. Doesn't assume legs are contiguous
     * (a leg's destination not matching the next leg's origin still
     * produces a readable, if longer, chain).
     */
    public function routeLabel(): string
    {
        $legs = $this->legs;

        if ($legs->isEmpty()) {
            return '';
        }

        $codes = [];

        foreach ($legs as $leg) {
            if ($codes === [] || end($codes) !== $leg->originAirport->icao_code) {
                $codes[] = $leg->originAirport->icao_code;
            }

            $codes[] = $leg->destinationAirport->icao_code;
        }

        return implode('-', $codes);
    }

    public function displayLabel(): string
    {
        $route = $this->routeLabel();

        return $this->callsign ? "{$this->callsign} ({$route})" : $route;
    }

    /** True only for an AI-drafted request an operator hasn't confirmed or corrected yet. */
    public function needsReview(): bool
    {
        return $this->source === RequestSource::Email && $this->reviewed_at === null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_id', 'aircraft_id', 'callsign', 'passenger_count', 'crew_count', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
