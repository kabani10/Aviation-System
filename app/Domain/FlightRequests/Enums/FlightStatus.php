<?php

namespace App\Domain\FlightRequests\Enums;

/**
 * The lifecycle from the original spec's Step 11, plus Cancelled — the spec
 * lists services as cancellable but never says the same for the flight
 * itself, which is clearly an oversight rather than a deliberate omission;
 * every real operational workflow needs it.
 */
enum FlightStatus: string
{
    case NewRequest = 'new_request';
    case UnderReview = 'under_review';
    case QuotationInProgress = 'quotation_in_progress';
    case QuotationSent = 'quotation_sent';
    case Confirmed = 'confirmed';
    case InOperation = 'in_operation';
    case Completed = 'completed';
    case Invoiced = 'invoiced';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NewRequest => 'New request',
            self::UnderReview => 'Under review',
            self::QuotationInProgress => 'Quotation in progress',
            self::QuotationSent => 'Quotation sent',
            self::Confirmed => 'Confirmed',
            self::InOperation => 'In operation',
            self::Completed => 'Completed',
            self::Invoiced => 'Invoiced',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NewRequest, self::UnderReview => 'gray',
            self::QuotationInProgress, self::QuotationSent => 'warning',
            self::Confirmed, self::InOperation => 'info',
            self::Completed, self::Invoiced, self::Closed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
