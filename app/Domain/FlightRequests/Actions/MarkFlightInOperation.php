<?php

namespace App\Domain\FlightRequests\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;

/** Confirmed → InOperation. See CheckFlightReadiness for the advisory check shown before this fires — this action itself does no validation, same "the check is a UI concern, the action just does the thing" split as the rest of the app. */
class MarkFlightInOperation
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(FlightRequest $flightRequest): FlightRequest
    {
        $flightRequest->update([
            'status' => FlightStatus::InOperation,
            'operation_started_at' => now(),
        ]);

        ($this->logCommunication)(
            communicable: $flightRequest,
            type: CommunicationType::SystemEvent,
            body: 'Flight marked in operation.',
        );

        return $flightRequest->fresh();
    }
}
