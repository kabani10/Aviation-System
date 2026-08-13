<?php

namespace App\Domain\FlightRequests\Actions;

use App\Domain\FlightRequests\DataTransferObjects\MissingInformationFinding;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Shared\Enums\ServiceType;
use Illuminate\Support\Collection;

/**
 * The spec's "AI Missing-Information Detection" feature — but implemented
 * as plain domain validation, not an AI/* class. Every check here is a
 * deterministic lookup (a nullable column, an expired document, a service
 * with no supplier) with no ambiguity for an LLM to resolve, so there's no
 * API-call failure mode to isolate app/AI exists for — see ARCHITECTURE.md's
 * "why app/AI is separate" note. Re-run this any time (a "Missing
 * Information" action on FlightRequestResource), not just once after AI
 * extraction, since the data it checks changes as operators fill things in.
 *
 * Deliberately not checked here: "insufficient time to obtain a permit"
 * from the spec. That needs a per-country permit lead-time model that
 * doesn't exist yet — Suppliers & reference data already deferred
 * permit-specific rules for the same reason.
 */
class CheckMissingInformation
{
    /** @return Collection<int, MissingInformationFinding> */
    public function __invoke(FlightRequest $flightRequest): Collection
    {
        $findings = collect();

        if ($flightRequest->passenger_count === null) {
            $findings->push(new MissingInformationFinding(
                field: 'passenger_count',
                message: 'Passenger count is missing.',
                why: 'Ground handling, catering, and passenger transport quantities all depend on it.',
            ));
        }

        if ($flightRequest->crew_count === null) {
            $findings->push(new MissingInformationFinding(
                field: 'crew_count',
                message: 'Crew count is missing.',
                why: 'Crew transport and hotel arrangements depend on it.',
            ));
        }

        if ($flightRequest->customer_id === null) {
            $findings->push(new MissingInformationFinding(
                field: 'customer_id',
                message: 'No customer identified for this request.',
                why: 'A customer is needed before this request can be quoted or invoiced.',
            ));
        } elseif (! $flightRequest->customer?->billing_email) {
            $findings->push(new MissingInformationFinding(
                field: 'customer.billing_email',
                message: 'The customer has no billing email on file.',
                why: 'Needed to send the invoice once the flight is complete.',
            ));
        }

        if ($flightRequest->aircraft_id === null) {
            $findings->push(new MissingInformationFinding(
                field: 'aircraft_id',
                message: 'No aircraft identified for this request.',
                why: 'Needed to check document/permit requirements and match ground handling capacity.',
            ));
        }

        foreach ($flightRequest->legs as $leg) {
            if ($leg->departure_at === null) {
                $findings->push(new MissingInformationFinding(
                    field: "legs.{$leg->id}.departure_at",
                    message: "Leg {$leg->sequence}'s departure time is missing.",
                    why: 'Supplier scheduling and permit timing both depend on it.',
                ));
            }

            if ($leg->arrival_at === null) {
                $findings->push(new MissingInformationFinding(
                    field: "legs.{$leg->id}.arrival_at",
                    message: "Leg {$leg->sequence}'s arrival time is missing.",
                    why: 'Ground handling and crew arrangements at the destination depend on it.',
                ));
            }
        }

        $expiredDocument = $flightRequest->aircraft?->documents()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->first();

        if ($expiredDocument) {
            $findings->push(new MissingInformationFinding(
                field: 'aircraft.documents',
                message: "The aircraft's \"{$expiredDocument->title}\" document has expired.",
                why: 'Expired certificates or insurance can block permit approval and ground handling.',
            ));
        }

        foreach ($flightRequest->services as $service) {
            if ($this->requiresPermitDocuments($service->type) && $service->documents()->count() === 0) {
                $findings->push(new MissingInformationFinding(
                    field: "services.{$service->id}.documents",
                    message: "No supporting documents attached for the {$service->type->label()}.",
                    why: 'Permit applications need supporting paperwork before they can be submitted.',
                    affectedService: $service->type->label(),
                ));
            }

            if ($service->supplier_id === null && $service->status !== ServiceStatus::Cancelled) {
                $findings->push(new MissingInformationFinding(
                    field: "services.{$service->id}.supplier_id",
                    message: "No supplier assigned yet for {$service->type->label()}.",
                    why: 'Without a supplier the service cannot be priced or confirmed.',
                    affectedService: $service->type->label(),
                ));
            }
        }

        return $findings;
    }

    private function requiresPermitDocuments(ServiceType $type): bool
    {
        return in_array($type, [ServiceType::LandingPermit, ServiceType::OverflightPermit], strict: true);
    }
}
