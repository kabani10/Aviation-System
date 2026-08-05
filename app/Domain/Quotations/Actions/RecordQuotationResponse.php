<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use InvalidArgumentException;

/**
 * Records what the customer said about a sent quotation — there's no
 * customer portal, so this is always an operator entering what came back
 * by phone, email reply, or however else the customer responded, same
 * "record what an external party told you" pattern as
 * RecordSupplierQuote in Phase 8. Accepting a quotation is what actually
 * confirms the flight (FlightStatus::Confirmed) — this is the one place
 * in the app that transition happens.
 */
class RecordQuotationResponse
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(Quotation $quotation, QuotationStatus $response, ?string $notes = null): Quotation
    {
        if (! in_array($response, [QuotationStatus::Accepted, QuotationStatus::Rejected], strict: true)) {
            throw new InvalidArgumentException('A quotation response must be Accepted or Rejected.');
        }

        $quotation->update([
            'status' => $response,
            'responded_at' => now(),
            'notes' => $notes ?? $quotation->notes,
        ]);

        ($this->logCommunication)(
            communicable: $quotation,
            type: CommunicationType::Note,
            body: $notes ?? "Customer {$response->label()} the quotation.",
            subject: "Quotation {$response->label()}",
        );

        if ($response === QuotationStatus::Accepted) {
            $quotation->flightRequest->update(['status' => FlightStatus::Confirmed]);
        }

        return $quotation->fresh();
    }
}
