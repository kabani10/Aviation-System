<?php

namespace App\AI\SupplierRecommendation\Recommenders;

use App\AI\SupplierRecommendation\DataTransferObjects\SupplierRecommendation;
use App\AI\SupplierRecommendation\Prompts\SupplierRecommendationPrompt;
use App\AI\Support\ClaudeApiException;
use App\AI\Support\ClaudeClient;
use App\Domain\Services\Models\Service;
use App\Domain\Suppliers\Actions\ComputeSupplierPerformance;
use App\Domain\Suppliers\Models\Supplier;
use Illuminate\Support\Collection;

/**
 * Ranks the suppliers who offer a service's type by asking Claude to weigh
 * their computed performance history (ComputeSupplierPerformance — plain
 * averages, no AI needed there) against freeform notes an operator wrote —
 * the "did this supplier cause problems last time" judgment call is the
 * genuinely unstructured part an LLM is well-suited for, unlike the metrics
 * themselves. The operator still picks the supplier by hand afterward, same
 * "AI drafts, human decides" principle as Phase 7's request extraction.
 *
 * Candidates are also filtered by the service's leg's airports —
 * `Supplier::airports()` exists specifically to record where a supplier
 * actually operates, and recommending one with zero presence at the
 * relevant airport isn't a judgment call, it's operationally impossible
 * (see `filterByAirportCoverage()`). Not every supplier has this recorded
 * yet, though, so the same "missing data isn't a red flag" reasoning that
 * already applies to a supplier's cost/response-time history applies here
 * too — a supplier with no airports recorded at all isn't excluded, only
 * one recorded at *other* airports and not this one.
 */
class SupplierRecommender
{
    public function __construct(
        private readonly ClaudeClient $client,
        private readonly SupplierRecommendationPrompt $prompt,
        private readonly ComputeSupplierPerformance $computePerformance,
    ) {}

    /** @return Collection<int, SupplierRecommendation> */
    public function __invoke(Service $service): Collection
    {
        $candidates = Supplier::query()
            ->where('is_active', true)
            ->whereJsonContains('services_offered', $service->type->value)
            ->with('airports')
            ->get();

        $candidates = $this->filterByAirportCoverage($candidates, $service);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $performanceById = $candidates->mapWithKeys(fn (Supplier $supplier): array => [
            $supplier->id => ($this->computePerformance)($supplier, $service->type),
        ]);

        $response = $this->client->messages(
            messages: [[
                'role' => 'user',
                'content' => $this->prompt->userContent($service, $candidates, $performanceById),
            ]],
            tools: [$this->prompt->tool()],
            system: $this->prompt->system(),
        );

        $input = $this->client->toolInput($response, 'recommend_suppliers');

        if ($input === null) {
            throw new ClaudeApiException('Claude did not call recommend_suppliers for service '.$service->id);
        }

        // Verified against the actual candidate list rather than trusted
        // outright — same defensive pattern as CreateFlightRequestFromExtraction
        // re-checking Claude's chosen ids against real rows.
        $candidateIds = $candidates->pluck('id')->all();

        return collect($input['recommendations'] ?? [])
            ->filter(fn (array $entry): bool => in_array($entry['supplier_id'] ?? null, $candidateIds, strict: true))
            ->map(fn (array $entry): SupplierRecommendation => new SupplierRecommendation(
                supplierId: $entry['supplier_id'],
                rationale: $entry['rationale'] ?? '',
            ))
            ->values();
    }

    /**
     * Drops any candidate whose recorded airports don't include this
     * service's leg's origin or destination — but only when that supplier
     * has airports recorded at all. A supplier nobody has ever entered
     * coverage for is a data gap, not evidence they don't operate here.
     *
     * @param  Collection<int, Supplier>  $candidates
     * @return Collection<int, Supplier>
     */
    private function filterByAirportCoverage(Collection $candidates, Service $service): Collection
    {
        $leg = $service->flightLeg;

        if ($leg === null) {
            return $candidates;
        }

        $legAirportIds = [$leg->origin_airport_id, $leg->destination_airport_id];

        return $candidates->filter(function (Supplier $supplier) use ($legAirportIds): bool {
            if ($supplier->airports->isEmpty()) {
                return true;
            }

            return $supplier->airports->pluck('id')->intersect($legAirportIds)->isNotEmpty();
        })->values();
    }
}
