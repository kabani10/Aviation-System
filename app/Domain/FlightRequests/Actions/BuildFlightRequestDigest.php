<?php

namespace App\Domain\FlightRequests\Actions;

use App\Domain\FlightRequests\DataTransferObjects\FlightDigestEntry;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Gathers, for one company, every active flight request's outstanding
 * findings — an unreviewed AI draft, missing information, an operational
 * risk — and groups them by who should hear about them: the flight's
 * assigned employees, or every flights.manage holder in the company when
 * nobody's assigned yet (the common case for a fresh AI draft, which by
 * definition hasn't been picked up by anyone). Kept separate from
 * SendFlightRequestDigests so "what's currently outstanding" is testable
 * without touching the notifications table at all.
 *
 * Deliberately no de-duplication against what was already sent — this is a
 * daily snapshot of what's currently outstanding, not an event log. Simpler
 * to reason about than tracking per-finding "already notified" state, and
 * an issue that's still open after yesterday's digest is still worth
 * seeing today.
 */
class BuildFlightRequestDigest
{
    private const EXCLUDED_STATUSES = [
        FlightStatus::Completed, FlightStatus::Invoiced, FlightStatus::Closed, FlightStatus::Cancelled,
    ];

    public function __construct(
        private readonly CheckMissingInformation $checkMissingInformation,
        private readonly CheckOperationalRisks $checkOperationalRisks,
    ) {}

    /** @return Collection<int, Collection<int, FlightDigestEntry>> keyed by user id */
    public function __invoke(Company $company): Collection
    {
        $flightManagers = null;
        $digest = collect();

        $flightRequests = $company->flightRequests()
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->with('assignedUsers')
            ->get();

        foreach ($flightRequests as $flightRequest) {
            $messages = $this->messagesFor($flightRequest);

            if ($messages === []) {
                continue;
            }

            $recipients = $flightRequest->assignedUsers->isNotEmpty()
                ? $flightRequest->assignedUsers
                : ($flightManagers ??= $this->flightManagers($company));

            $entry = new FlightDigestEntry($flightRequest, $messages);

            foreach ($recipients as $recipient) {
                $digest[$recipient->id] ??= collect();
                $digest[$recipient->id]->push($entry);
            }
        }

        return $digest;
    }

    /** @return string[] */
    private function messagesFor(FlightRequest $flightRequest): array
    {
        $messages = [];

        if ($flightRequest->needsReview()) {
            $messages[] = 'This flight was drafted by AI from an inbound email and has not been reviewed yet.';
        }

        foreach (($this->checkMissingInformation)($flightRequest) as $finding) {
            $messages[] = $finding->message;
        }

        foreach (($this->checkOperationalRisks)($flightRequest) as $finding) {
            $messages[] = $finding->message;
        }

        return $messages;
    }

    /** @return Collection<int, User> */
    private function flightManagers(Company $company): Collection
    {
        return $company->users()->get()->filter(fn (User $user): bool => $user->can('flights.manage'))->values();
    }
}
