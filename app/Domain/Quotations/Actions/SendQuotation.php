<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Mail\QuotationMail;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Emails the quotation to the flight's customer and moves both the
 * Quotation (Draft → Sent) and its FlightRequest (→ QuotationSent) forward.
 * The email is logged on the Quotation's own timeline, not the flight's —
 * a flight can have several quotations over time, and each one's
 * correspondence belongs with the quotation it's about.
 */
class SendQuotation
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(Quotation $quotation): Quotation
    {
        $customer = $quotation->flightRequest->customer;

        if (! $customer->billing_email) {
            throw new RuntimeException('This customer has no billing email on file — add one before sending a quotation.');
        }

        Mail::to($customer->billing_email)->send(new QuotationMail($quotation));

        ($this->logCommunication)(
            communicable: $quotation,
            type: CommunicationType::EmailOut,
            body: 'Quotation sent — total $'.number_format($quotation->totalSellingPrice(), 2).'.',
            subject: "Quotation for {$quotation->flightRequest->displayLabel()}",
            toAddress: $customer->billing_email,
        );

        $quotation->update([
            'status' => QuotationStatus::Sent,
            'sent_at' => now(),
        ]);

        $quotation->flightRequest->update(['status' => FlightStatus::QuotationSent]);

        return $quotation->fresh();
    }
}
