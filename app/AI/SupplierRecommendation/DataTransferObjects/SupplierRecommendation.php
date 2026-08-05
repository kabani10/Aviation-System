<?php

namespace App\AI\SupplierRecommendation\DataTransferObjects;

/** One ranked entry from SupplierRecommender — position in the returned collection is the rank. */
final readonly class SupplierRecommendation
{
    public function __construct(
        public int $supplierId,
        public string $rationale,
    ) {}
}
