<?php

namespace App\Domain\FlightRequests\DataTransferObjects;

/** One gap CheckMissingInformation found on a FlightRequest — what's missing, why it matters, and which service (if any) it blocks. */
final readonly class MissingInformationFinding
{
    public function __construct(
        public string $field,
        public string $message,
        public string $why,
        public ?string $affectedService = null,
    ) {}
}
