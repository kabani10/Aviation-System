<?php

namespace App\Domain\Finance\Enums;

/**
 * An Invoice's own lifecycle. No `Overdue` case — same "compute on read,
 * don't maintain a stored status that can go stale" reasoning as
 * QuotationStatus not auto-setting `Expired`; see Invoice::isOverdue().
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'warning',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }
}
