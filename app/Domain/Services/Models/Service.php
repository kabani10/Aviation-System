<?php

namespace App\Domain\Services\Models;

use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\Documents\Concerns\HasDocuments;
use App\Domain\FlightRequests\Models\FlightLeg;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line item on a flight — ground handling, fuel, a landing permit.
 * Belongs to a specific FlightLeg (which leg needs this), not just the
 * FlightRequest as a whole — ground handling at a layover airport is a
 * different supplier/cost/confirmation than at the destination. Still
 * keeps flight_request_id too (denormalized from the leg, set once at
 * creation and never reassigned): every check and quotation-generation
 * query that cares about "this flight's services" reads through that
 * column directly rather than joining through legs, which is the whole
 * reason it's worth the duplication — see FlightLeg's docblock.
 * "Operational risks" from the original spec isn't a field here: that's
 * CheckOperationalRisks reading deadlines/confirmations/etc across
 * services (Phase 8), not something an operator types in manually.
 * HasDocuments is on the model (a service's own certificates/permits
 * belong here, not on the flight), but there's no nested RelationManager
 * UI for it yet — Filament doesn't nest a RelationManager inside another
 * RelationManager, and Service doesn't have its own top-level resource to
 * hang one off of. HasCommunications is used as of Phase 8, for a
 * quote-request/quote-received log — as of Phase 15 that log lives per
 * SupplierInquiry instead (see that model's docblock), not here directly.
 *
 * `supplier_id`/`cost` mean "the supplier we chose", as of Phase 15 — not
 * "the supplier we're asking". Comparing several candidates before
 * deciding happens through `supplierInquiries()` (`ChooseSupplierInquiry`
 * copies the winning one's supplier/cost here); these two fields stay
 * directly editable too, for a manual override that skips the RFQ
 * comparison entirely.
 */
#[Fillable([
    // flight_request_id is here even though FlightRequest::services()->create()
    // (the usual path — ServicesRelationManager, most tests) auto-fills it via
    // the relation and never needs it listed. CreateFlightRequestFromExtraction
    // creates through the leg's relation instead (auto-fills flight_leg_id, not
    // flight_request_id), so the denormalized column has to be mass-assignable
    // to be set explicitly there.
    'flight_request_id', 'flight_leg_id', 'type', 'status', 'responsible_user_id', 'supplier_id', 'cost', 'selling_price',
    'quote_requested_at', 'quote_received_at', 'supplier_confirmed_at', 'deadline', 'notes',
])]
class Service extends Model
{
    use BelongsToCompany, HasCommunications, HasDocuments, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'status' => ServiceStatus::class,
            'cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'quote_requested_at' => 'datetime',
            'quote_received_at' => 'datetime',
            'supplier_confirmed_at' => 'datetime',
            'deadline' => 'datetime',
        ];
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(FlightRequest::class);
    }

    public function flightLeg(): BelongsTo
    {
        return $this->belongsTo(FlightLeg::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierInquiries(): HasMany
    {
        return $this->hasMany(SupplierInquiry::class);
    }

    /**
     * Computed, not stored — cost and selling_price already are the source
     * of truth, a third column would just be a copy that can drift.
     */
    public function profitMargin(): ?float
    {
        if ($this->cost === null || $this->selling_price === null) {
            return null;
        }

        return (float) $this->selling_price - (float) $this->cost;
    }

    public function isOverdue(): bool
    {
        return $this->deadline !== null
            && $this->deadline->isPast()
            && ! in_array($this->status, [ServiceStatus::Confirmed, ServiceStatus::Completed, ServiceStatus::Cancelled], strict: true);
    }

    public function displayLabel(): string
    {
        return "{$this->type->label()} — {$this->flightRequest->displayLabel()}";
    }
}
