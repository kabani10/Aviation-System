<?php

namespace App\Domain\Services\Models;

use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One candidate supplier's RFQ round-trip on a Service — the "select
 * multiple suppliers, send inquiries to each, compare replies" layer Phase
 * 15 adds on top of Service. A Service can have several of these in flight
 * at once (one per supplier asked), unlike the single supplier_id/cost it
 * carries — those two fields on Service now mean "the supplier we actually
 * chose", set by ChooseSupplierInquiry from whichever inquiry won, not "the
 * supplier we're in the middle of asking". HasCommunications so each
 * inquiry keeps its own send/receive timeline (a service with three
 * inquiries out has three separate conversations, not one blurred together
 * on the Service itself, the way the single-supplier flow this replaces
 * had it) — no dedicated RelationManager for it yet, same "Filament doesn't
 * nest a RelationManager inside another RelationManager, and this model has
 * no top-level resource to hang one off" reasoning as Service's own
 * HasDocuments. This is also the natural place Phase 16's AI-read supplier
 * replies will match an inbound email against.
 */
#[Fillable([
    'service_id', 'supplier_id', 'supplier_contact_id', 'requested_by', 'status', 'cost', 'notes',
    'requested_at', 'responded_at',
    // Phase 17's booking-confirmation round-trip — kept separate from
    // requested_at/responded_at (the quote cycle) so ComputeSupplierPerformance's
    // response-time history isn't overwritten by a second, later round-trip.
    'confirmation_requested_at', 'confirmed_at',
])]
class SupplierInquiry extends Model
{
    use BelongsToCompany, HasCommunications, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SupplierInquiryStatus::class,
            'cost' => 'decimal:2',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'confirmation_requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierContact(): BelongsTo
    {
        return $this->belongsTo(SupplierContact::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function displayLabel(): string
    {
        return "{$this->supplier->name} — {$this->service->type->label()}";
    }
}
