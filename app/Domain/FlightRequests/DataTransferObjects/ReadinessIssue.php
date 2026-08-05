<?php

namespace App\Domain\FlightRequests\DataTransferObjects;

/** One gap CheckFlightReadiness found — same shape as MissingInformationFinding/OperationalRiskFinding, kept as its own type since it answers a different question ("is this flight ready to fly right now") at a different moment (an explicit status transition, not a passive check). */
final readonly class ReadinessIssue
{
    public function __construct(
        public string $field,
        public string $message,
        public string $why,
        public ?string $affectedService = null,
    ) {}
}
