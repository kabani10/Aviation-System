<?php

namespace App\Mail;

use App\Domain\Services\Models\SupplierInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the chosen supplier once an inquiry has been picked
 * (SupplierInquiry::status === Chosen) — confirming the booking at the
 * price they quoted (inquiry.cost, the supplier's own number — not
 * selling_price, same customer/supplier financial boundary
 * SupplierQuoteRequestMail already draws) rather than asking for one.
 */
class SupplierBookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SupplierInquiry $inquiry,
        // Deliberately not $message — see SupplierQuoteRequestMail's note
        // on why (Illuminate\Mail\Mailer::send() reserves that name).
        public readonly ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Booking confirmation: {$this->inquiry->service->type->label()} — {$this->inquiry->service->flightRequest->displayLabel()}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.supplier-booking-confirmation');
    }
}
