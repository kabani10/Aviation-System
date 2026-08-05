<?php

namespace App\Domain\FlightRequests\DataTransferObjects;

use App\Domain\FlightRequests\Models\FlightRequest;

/** One flight's worth of outstanding findings for one recipient of BuildFlightRequestDigest. */
final readonly class FlightDigestEntry
{
    /** @param  string[]  $messages */
    public function __construct(
        public FlightRequest $flightRequest,
        public array $messages,
    ) {}
}
