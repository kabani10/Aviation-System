<?php

namespace App\Domain\FlightRequests\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;

/** InOperation → Completed. Invoicing (→ Invoiced/Closed) is Finance's job, Phase 12 — this action stops at Completed and goes no further. */
class CompleteFlight
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(FlightRequest $flightRequest): FlightRequest
    {
        $flightRequest->update([
            'status' => FlightStatus::Completed,
            'completed_at' => now(),
        ]);

        ($this->logCommunication)(
            communicable: $flightRequest,
            type: CommunicationType::SystemEvent,
            body: 'Flight marked completed.',
        );

        return $flightRequest->fresh();
    }
}
