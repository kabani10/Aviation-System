<?php

namespace App\Mail;

use App\Domain\Services\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a supplier contact asking for a quote on one service — the first
 * outbound Mailable in the app (everything before this was inbound-only via
 * Postmark). Kept intentionally plain: no attachments, no flight-financial
 * data (selling_price) — the supplier only needs to know what's being asked
 * of them, not what the customer is being charged.
 */
class SupplierQuoteRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Service $service,
        public readonly ?string $message = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Quote request: {$this->service->type->label()} — {$this->service->flightRequest->displayLabel()}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.supplier-quote-request');
    }
}
