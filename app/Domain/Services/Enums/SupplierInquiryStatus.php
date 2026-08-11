<?php

namespace App\Domain\Services\Enums;

/**
 * One candidate supplier's own progress on a Service — see SupplierInquiry's
 * docblock. Deliberately smaller than ServiceStatus: Completed/Cancelled
 * stay on Service (they're about the whole service, not this one supplier
 * relationship), but Confirmed belongs here as of Phase 17 — "did *this*
 * supplier confirm the booking" is squarely about the chosen inquiry, and
 * ApplySupplierConfirmation mirrors it onto Service.status/
 * supplier_confirmed_at at the same time, the same "one inquiry decides,
 * the Service reflects it" shape ChooseSupplierInquiry already established
 * for the price.
 */
enum SupplierInquiryStatus: string
{
    case Sent = 'sent';
    case QuoteReceived = 'quote_received';
    case Chosen = 'chosen';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::QuoteReceived => 'Quote received',
            self::Chosen => 'Chosen',
            self::Confirmed => 'Confirmed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sent => 'gray',
            self::QuoteReceived => 'warning',
            self::Chosen => 'success',
            self::Confirmed => 'success',
        };
    }
}
