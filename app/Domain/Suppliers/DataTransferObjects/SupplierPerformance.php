<?php

namespace App\Domain\Suppliers\DataTransferObjects;

/** Computed metrics for one supplier — see ComputeSupplierPerformance. Averages are null, not zero, when there's no data to average yet. */
final readonly class SupplierPerformance
{
    public function __construct(
        public int $servicesCount,
        public ?float $averageResponseTimeHours,
        public ?float $averageCost,
        public int $confirmedCount,
        public int $atRiskOrCancelledCount,
    ) {}
}
