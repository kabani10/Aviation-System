<?php

namespace App\Domain\FlightRequests\Actions;

use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;

/**
 * The passive counterpart to CheckFlightReadiness — that action answers
 * "is this flight ready right now" whenever an operator explicitly asks
 * (the mark-in-operation/complete confirmation modals); this one answers
 * "should a warning be showing without anyone asking", for the list,
 * kanban board, and view page (Phase 19). Kept as its own class rather than
 * a second method on CheckFlightReadiness — every other Check* action in
 * this app does exactly one job, and this one adds a genuinely different
 * concern (a departure-date window) on top rather than just reformatting
 * the same answer.
 *
 * A flight only warrants the warning when its departure is close (within
 * WARNING_WINDOW_DAYS — deliberately a plain class constant, not a per-tenant
 * setting, same "revisit if real usage asks for it" scope discipline as
 * CheckOperationalRisks' own thresholds) and its status hasn't already moved
 * past the point where "not fully confirmed yet" stops being useful
 * information (once in operation, completed, or cancelled, this is either
 * too late to act on or moot).
 */
class CheckFlightReadinessWarning
{
    /** How close departure has to be before an unresolved flight starts warning. */
    private const WARNING_WINDOW_DAYS = 1;

    private const NO_LONGER_RELEVANT_STATUSES = [
        FlightStatus::InOperation,
        FlightStatus::Completed,
        FlightStatus::Invoiced,
        FlightStatus::Closed,
        FlightStatus::Cancelled,
    ];

    public function __construct(private readonly CheckFlightReadiness $checkReadiness) {}

    public function __invoke(FlightRequest $flightRequest): bool
    {
        $departure = $flightRequest->earliestDepartureAt();

        if ($departure === null || in_array($flightRequest->status, self::NO_LONGER_RELEVANT_STATUSES, strict: true)) {
            return false;
        }

        if ($departure->isAfter(now()->addDays(self::WARNING_WINDOW_DAYS))) {
            return false;
        }

        return ($this->checkReadiness)($flightRequest)->isNotEmpty();
    }
}
