<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Enums\FlightStatus;

/**
 * Records that payment came in — no partial payments, no payment
 * gateway integration, just an operator noting what happened, same
 * "record what an external party told you" pattern as
 * RecordSupplierQuote/RecordQuotationResponse. This is the one place
 * FlightStatus::Closed gets set — the final stop in the flight's
 * lifecycle.
 */
class RecordInvoicePayment
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(Invoice $invoice, ?string $notes = null): Invoice
    {
        $invoice->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
            'notes' => $notes ?? $invoice->notes,
        ]);

        ($this->logCommunication)(
            communicable: $invoice,
            type: CommunicationType::Note,
            body: $notes ?? 'Payment received.',
            subject: "Invoice {$invoice->invoice_number} paid",
        );

        $invoice->flightRequest->update(['status' => FlightStatus::Closed]);

        return $invoice->fresh();
    }
}
