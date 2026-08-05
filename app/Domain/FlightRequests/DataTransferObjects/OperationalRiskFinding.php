<?php

namespace App\Domain\FlightRequests\DataTransferObjects;

/** One gap CheckOperationalRisks found on a FlightRequest — same shape as MissingInformationFinding, kept as its own type since the two are separate spec features that happen to render the same way. */
final readonly class OperationalRiskFinding
{
    public function __construct(
        public string $field,
        public string $message,
        public string $why,
        public ?string $affectedService = null,
    ) {}
}
