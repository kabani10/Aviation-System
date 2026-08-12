<?php

namespace App\Mail;

use App\Domain\FlightRequests\Models\FlightRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the customer at any point in a flight's lifecycle — "throughout
 * the process" per the spec, not gated to any particular FlightStatus —
 * showing each leg's services and their current confirmation status.
 * Deliberately price-free: this is an operational "is everything on track"
 * update, not a financial document (that's what QuotationMail/InvoiceMail
 * are for), so cost and selling_price both stay out of it entirely rather
 * than needing a field-level gate the way the panel's own Service columns
 * do.
 */
class FlightStatusUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly FlightRequest $flightRequest,
        // Deliberately not $message — see SupplierQuoteRequestMail's note
        // on why (Illuminate\Mail\Mailer::send() reserves that name).
        public readonly ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Status update: {$this->flightRequest->displayLabel()}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.flight-status-update');
    }
}
