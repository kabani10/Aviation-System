<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/** Emails the invoice to the customer and moves both Invoice (Draft → Sent) and FlightRequest (→ Invoiced) forward — same shape as Quotation's SendQuotation. */
class SendInvoice
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(Invoice $invoice): Invoice
    {
        $customer = $invoice->flightRequest->customer;

        if (! $customer->billing_email) {
            throw new RuntimeException('This customer has no billing email on file — add one before sending an invoice.');
        }

        Mail::to($customer->billing_email)->send(new InvoiceMail($invoice));

        ($this->logCommunication)(
            communicable: $invoice,
            type: CommunicationType::EmailOut,
            body: 'Invoice sent — amount due $'.number_format($invoice->totalAmount(), 2).'.',
            subject: "Invoice {$invoice->invoice_number}",
            toAddress: $customer->billing_email,
        );

        $invoice->update([
            'status' => InvoiceStatus::Sent,
            'sent_at' => now(),
        ]);

        $invoice->flightRequest->update(['status' => FlightStatus::Invoiced]);

        return $invoice->fresh();
    }
}
