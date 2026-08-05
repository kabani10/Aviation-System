<?php

namespace App\Domain\Quotations\Enums;

/**
 * A Quotation's own lifecycle — distinct from FlightStatus, though the two
 * are linked: SendQuotation moves the flight to QuotationSent, and
 * RecordQuotationResponse moves it to Confirmed on acceptance. Expired
 * isn't set automatically by a scheduled job — see isExpired() on the
 * model and the CheckOperationalRisks finding instead, same "compute on
 * read" reasoning as everywhere else in this codebase.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'warning',
            self::Accepted => 'success',
            self::Rejected, self::Expired => 'danger',
        };
    }
}
