<?php

namespace App\Mail;

use App\Domain\Quotations\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the customer's billing_email with the quotation's line items and
 * total — the app's first customer-facing outbound Mailable (Phase 8's
 * SupplierQuoteRequestMail went to suppliers). Deliberately shows only
 * selling_price, never cost — the customer never sees what a service costs
 * the tenant to provide, same boundary Service's own field-level gating
 * enforces inside the panel.
 */
class QuotationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Quotation $quotation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Quotation for {$this->quotation->flightRequest->displayLabel()}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.quotation');
    }
}
