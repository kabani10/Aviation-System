<?php

namespace App\Domain\Suppliers\Actions;

use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\DataTransferObjects\SupplierPerformance;
use App\Domain\Suppliers\Models\Supplier;

/**
 * Computes the metrics Suppliers & reference data (Phase 4) explicitly
 * deferred — "average response time" and "previous prices" — now that
 * Phase 8's supplier quote workflow produces the underlying data
 * (quote_requested_at / quote_received_at / cost) they need. Deterministic
 * on purpose: these are plain averages and counts, nothing an LLM should be
 * computing — SupplierRecommender consumes the output of this, not the
 * other way around.
 */
class ComputeSupplierPerformance
{
    public function __invoke(Supplier $supplier, ?ServiceType $type = null): SupplierPerformance
    {
        $services = Service::query()
            ->where('supplier_id', $supplier->id)
            ->when($type, fn ($query, ServiceType $type) => $query->where('type', $type))
            ->get();

        $responseTimesInHours = $services
            ->filter(fn (Service $service): bool => $service->quote_requested_at !== null && $service->quote_received_at !== null)
            ->map(fn (Service $service): float => $service->quote_requested_at->diffInHours($service->quote_received_at, absolute: true));

        $costs = $services->pluck('cost')->filter(fn (?string $cost): bool => $cost !== null)->map(fn (string $cost): float => (float) $cost);

        return new SupplierPerformance(
            servicesCount: $services->count(),
            averageResponseTimeHours: $responseTimesInHours->isNotEmpty() ? round($responseTimesInHours->avg(), 1) : null,
            averageCost: $costs->isNotEmpty() ? round($costs->avg(), 2) : null,
            confirmedCount: $services->whereIn('status', [ServiceStatus::Confirmed, ServiceStatus::Completed])->count(),
            atRiskOrCancelledCount: $services->whereIn('status', [ServiceStatus::AtRisk, ServiceStatus::Cancelled])->count(),
        );
    }
}
