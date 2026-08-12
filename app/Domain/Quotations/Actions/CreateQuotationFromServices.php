<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\FlightRequests\Models\FlightLeg;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Snapshots the flight's currently priced, non-cancelled services into a
 * new Draft Quotation with its own line items — see Quotation's docblock
 * for why this is a snapshot rather than a live view. A service with no
 * selling_price yet is simply left out; there's no partial-price line to
 * show, and the operator can regenerate once it's priced. Calling this
 * again (e.g. after a rejected quote gets re-priced) creates a brand new
 * Quotation rather than mutating an old one — multiple quotations per
 * flight are expected, not an error case.
 *
 * `$leg`, as of Phase 18, narrows the snapshot to one leg's services
 * instead of the whole flight — the workflow gap this closes is "generate
 * a quotation for the full leg or the full request", not just the latter.
 * Passing null (the default) keeps the original whole-flight behavior.
 */
class CreateQuotationFromServices
{
    public function __invoke(
        FlightRequest $flightRequest,
        ?User $createdBy = null,
        ?string $notes = null,
        ?Carbon $validUntil = null,
        ?FlightLeg $leg = null,
    ): Quotation {
        $services = $flightRequest->services()
            ->where('status', '!=', ServiceStatus::Cancelled)
            ->whereNotNull('selling_price')
            ->when($leg, fn ($query) => $query->where('flight_leg_id', $leg->id))
            ->get();

        $quotation = Quotation::create([
            'flight_request_id' => $flightRequest->id,
            'flight_leg_id' => $leg?->id,
            'status' => QuotationStatus::Draft,
            'created_by' => $createdBy?->id,
            'notes' => $notes,
            'valid_until' => $validUntil,
        ]);

        foreach ($services as $service) {
            $quotation->lineItems()->create([
                'service_id' => $service->id,
                'description' => $service->type->label(),
                'cost' => $service->cost,
                'selling_price' => $service->selling_price,
            ]);
        }

        return $quotation->fresh('lineItems');
    }
}
