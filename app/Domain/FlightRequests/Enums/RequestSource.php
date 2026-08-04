<?php

namespace App\Domain\FlightRequests\Enums;

/**
 * How a FlightRequest came to exist. Manual is every request created
 * through the normal form (Filament, or anything else that doesn't go
 * through the AI extraction pipeline); Email is one CreateFlightRequestFromExtraction
 * created automatically from an inbound message. The distinction matters
 * because Email-sourced requests need a human to confirm them before
 * they're fully trusted — see FlightRequest::needsReview().
 */
enum RequestSource: string
{
    case Manual = 'manual';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Email => 'Email (AI draft)',
        };
    }
}
