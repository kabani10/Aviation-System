<?php

namespace App\Domain\Finance\DataTransferObjects;

/** Company-wide totals from ComputeFinancialSummary. totalProfitMargin is null (not zero) when no invoice has been paid yet — nothing to sum. */
final readonly class FinancialSummary
{
    public function __construct(
        public float $totalInvoiced,
        public float $totalCollected,
        public float $totalOutstanding,
        public int $overdueCount,
        public float $overdueAmount,
        public ?float $totalProfitMargin,
    ) {}
}
