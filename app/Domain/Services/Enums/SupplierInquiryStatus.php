<?php

namespace App\Domain\Services\Enums;

/**
 * One candidate supplier's own progress within a Service's RFQ round —
 * see SupplierInquiry's docblock. Deliberately smaller than ServiceStatus:
 * this only tracks "did we hear back with a price", not the service's whole
 * lifecycle (confirmation, completion, cancellation all stay on Service,
 * set once an inquiry is chosen — see ChooseSupplierInquiry).
 */
enum SupplierInquiryStatus: string
{
    case Sent = 'sent';
    case QuoteReceived = 'quote_received';
    case Chosen = 'chosen';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::QuoteReceived => 'Quote received',
            self::Chosen => 'Chosen',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sent => 'gray',
            self::QuoteReceived => 'warning',
            self::Chosen => 'success',
        };
    }
}
