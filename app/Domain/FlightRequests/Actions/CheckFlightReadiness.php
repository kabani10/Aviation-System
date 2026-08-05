<?php

namespace App\Domain\FlightRequests\Actions;

use App\Domain\FlightRequests\DataTransferObjects\ReadinessIssue;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Services\Enums\ServiceStatus;
use Illuminate\Support\Collection;

/**
 * "Is this flight actually ready to fly right now" — the check shown when
 * an operator explicitly marks a flight in operation or completed
 * (HasFlightExecutionActions). Deterministic, like CheckMissingInformation
 * and CheckOperationalRisks, and deliberately advisory rather than a hard
 * block: MarkFlightInOperation/CompleteFlight still let the transition
 * through after the operator sees these issues and confirms anyway,
 * same "the system informs, the human decides" principle used everywhere
 * else a check exists in this app. A plane can still fly with an
 * imperfect paper trail; the point is making sure nobody does that by
 * accident.
 */
class CheckFlightReadiness
{
    private const UNRESOLVED_SERVICE_STATUSES = [ServiceStatus::Confirmed, ServiceStatus::Completed, ServiceStatus::Cancelled];

    /** @return Collection<int, ReadinessIssue> */
    public function __invoke(FlightRequest $flightRequest): Collection
    {
        $issues = collect();

        $services = $flightRequest->services;

        if ($services->isEmpty()) {
            $issues->push(new ReadinessIssue(
                field: 'services',
                message: 'No services have been added to this flight yet.',
                why: 'There is nothing on record for ground handling, fuel, permits, or anything else this flight needs.',
            ));
        }

        foreach ($services as $service) {
            if (! in_array($service->status, self::UNRESOLVED_SERVICE_STATUSES, strict: true)) {
                $issues->push(new ReadinessIssue(
                    field: "services.{$service->id}.status",
                    message: "{$service->type->label()} has not been confirmed yet (status: {$service->status->label()}).",
                    why: 'A service that never got confirmed with its supplier may not actually happen.',
                    affectedService: $service->type->label(),
                ));
            }
        }

        if (! $flightRequest->quotations()->where('status', QuotationStatus::Accepted)->exists()) {
            $issues->push(new ReadinessIssue(
                field: 'quotations',
                message: 'No quotation has been accepted for this flight yet.',
                why: 'Normally a flight only reaches this stage after the customer accepted a quotation — this one may have had its status changed manually.',
            ));
        }

        return $issues;
    }
}
