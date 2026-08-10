<?php

namespace App\AI\SupplierRecommendation\Prompts;

use App\Domain\Services\Models\Service;
use App\Domain\Suppliers\DataTransferObjects\SupplierPerformance;
use App\Domain\Suppliers\Models\Supplier;
use Illuminate\Support\Collection;

/**
 * Builds the system prompt, tool schema, and user content for one
 * recommendation call — kept out of SupplierRecommender for the same
 * "prompts are content, not just code" reason as RequestExtractionPrompt.
 */
class SupplierRecommendationPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
            You are helping an aviation ground-handling and flight-support
            company choose which supplier to use for one service on a
            flight. You will be given the service type, the flight's route
            and deadline, and a list of candidate suppliers who offer that
            service type — each with which airports they're recorded as
            covering, performance metrics computed from their actual
            history with this company (services handled, average response
            time in hours, average quoted cost, how many were confirmed or
            completed versus flagged at-risk or cancelled), and any
            freeform notes an operator has written about them. Every
            candidate already offers this service type and either covers
            this leg's airports or has no coverage recorded at all — never
            a supplier confirmed to operate somewhere else instead.

            Always call recommend_suppliers exactly once, ranking every
            candidate you were given best-to-worst — even if there is only
            one. One candidate is not missing information; it means this
            service type or airport only has one supplier on file, and
            ranking that single one (with a rationale explaining it's the
            only option) is the correct, complete answer. This is a single
            tool call with no follow-up possible — you cannot ask a
            clarifying question or request a longer candidate list, so never
            respond with plain text instead of calling the tool.

            A candidate whose recorded coverage explicitly includes this
            leg's airport is a stronger signal than one with no coverage
            recorded — prefer the former when other factors are close, when
            there's more than one candidate to compare. Weigh a fast,
            reliable history over a merely low average cost, and treat notes
            describing a real past problem (not just a neutral comment) as a
            strong signal against recommending that supplier — omit a
            supplier entirely rather than ranking them if the notes describe
            a serious ongoing issue, even if it leaves the list empty. A
            supplier with no history yet is not itself a red flag — rank on
            whatever information is actually available.
            PROMPT;
    }

    /** @return array<string, mixed> */
    public function tool(): array
    {
        return [
            'name' => 'recommend_suppliers',
            'description' => 'Return suppliers ranked best-to-worst for this service.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'recommendations' => [
                        'type' => 'array',
                        'description' => 'Ordered best first. Omit any supplier you would actively avoid recommending.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'supplier_id' => ['type' => 'integer'],
                                'rationale' => [
                                    'type' => 'string',
                                    'description' => 'One or two sentences on why this supplier is ranked here.',
                                ],
                            ],
                            'required' => ['supplier_id', 'rationale'],
                        ],
                    ],
                ],
                'required' => ['recommendations'],
            ],
        ];
    }

    /**
     * @param  Collection<int, Supplier>  $candidates
     * @param  Collection<int, SupplierPerformance>  $performanceById  keyed by supplier id
     */
    public function userContent(Service $service, Collection $candidates, Collection $performanceById): string
    {
        $flightRequest = $service->flightRequest;
        $leg = $service->flightLeg;

        $suppliers = $candidates->map(function (Supplier $supplier) use ($performanceById): string {
            $performance = $performanceById->get($supplier->id);

            $metrics = "services handled: {$performance->servicesCount}, "
                .'avg response time: '.($performance->averageResponseTimeHours !== null ? "{$performance->averageResponseTimeHours}h" : 'no data').', '
                .'avg cost: '.($performance->averageCost !== null ? "\${$performance->averageCost}" : 'no data').', '
                ."confirmed/completed: {$performance->confirmedCount}, at-risk/cancelled: {$performance->atRiskOrCancelledCount}";

            $coverage = $supplier->airports->isEmpty()
                ? 'no coverage recorded'
                : $supplier->airports->pluck('icao_code')->implode(', ');

            $notes = $supplier->notes ? "\n  Notes: {$supplier->notes}" : '';

            return "- Supplier #{$supplier->id}: {$supplier->name} (covers: {$coverage}; {$metrics}){$notes}";
        })->implode("\n");

        $deadline = $service->deadline ? $service->deadline->toDayDateTimeString() : 'not set';

        return <<<TEXT
            Service: {$service->type->label()}
            Flight: {$flightRequest->displayLabel()}
            Leg: {$leg->originAirport->icao_code} to {$leg->destinationAirport->icao_code}
            Deadline: {$deadline}

            Candidate suppliers:
            {$suppliers}
            TEXT;
    }
}
