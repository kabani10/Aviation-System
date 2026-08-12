<?php

namespace App\Domain\FlightRequests\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Mail\FlightStatusUpdateMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Emails the customer a per-service status snapshot — "throughout the
 * process, the user can send the client a flight status" from the spec.
 * Deliberately not gated to any particular FlightStatus: unlike
 * SendQuotation/SendInvoice, sending this doesn't represent a state
 * transition, just a point-in-time operational update, so it's callable
 * any time a flight exists. Logged on the FlightRequest itself, not a leg
 * or service, since it's a whole-flight summary.
 */
class SendFlightStatusUpdate
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(FlightRequest $flightRequest, ?string $message = null, ?User $sentBy = null): FlightRequest
    {
        $customer = $flightRequest->customer;

        if (! $customer->billing_email) {
            throw new RuntimeException('This customer has no billing email on file — add one before sending a status update.');
        }

        Mail::to($customer->billing_email)->send(new FlightStatusUpdateMail($flightRequest, $message));

        ($this->logCommunication)(
            communicable: $flightRequest,
            type: CommunicationType::EmailOut,
            body: $message ?? 'Flight status update sent.',
            subject: "Status update: {$flightRequest->displayLabel()}",
            toAddress: $customer->billing_email,
            author: $sentBy,
        );

        return $flightRequest->fresh();
    }
}
