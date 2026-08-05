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
            ->get();

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
}
