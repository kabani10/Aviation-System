<?php

namespace App\AI\RequestExtraction\Actions;

use App\AI\RequestExtraction\DataTransferObjects\ExtractedFlightRequest;
use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Communications\Models\Communication;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Enums\RequestSource;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\ReferenceData\Models\Airport;
use Exception;
use Illuminate\Support\Carbon;

/**
 * Turns an extraction into a real FlightRequest — but only when every
 * hard-required field (customer, aircraft belonging to that customer, both
 * airports, a valid departure/arrival pair) resolved with confidence. The
 * schema requires those columns NOT NULL, so a partial extraction can't
 * become a partial FlightRequest the way the spec's "AI draft" language
 * might suggest; instead the raw extraction is stashed on the
 * Communication's metadata for an operator to use when creating the
 * request by hand, and the Communication stays where ReceiveInboundEmail
 * put it (on the Company).
 *
 * When confident, the Communication is also moved onto the new
 * FlightRequest — this is the "matching an email to the right flight"
 * step that ARCHITECTURE.md's Documents & communications section noted
 * couldn't happen until this phase existed.
 */
class CreateFlightRequestFromExtraction
{
    public function __invoke(Communication $communication, ExtractedFlightRequest $extraction): ?FlightRequest
    {
        $customer = $extraction->customerId ? Customer::query()->find($extraction->customerId) : null;
        $aircraft = $extraction->aircraftId ? Aircraft::query()->find($extraction->aircraftId) : null;
        $originAirport = $this->resolveAirport($extraction->originAirportCode);
        $destinationAirport = $this->resolveAirport($extraction->destinationAirportCode);
        $departureAt = $this->parseDate($extraction->departureAt);
        $arrivalAt = $this->parseDate($extraction->arrivalAt);

        $confident = $customer !== null
            && $aircraft !== null
            && $aircraft->customer_id === $customer->id
            && $originAirport !== null
            && $destinationAirport !== null
            && $departureAt !== null
            && $arrivalAt !== null
            && $arrivalAt->isAfter($departureAt);

        if (! $confident) {
            $communication->update([
                'metadata' => array_merge($communication->metadata ?? [], ['ai_extraction' => $extraction->raw]),
            ]);

            return null;
        }

        $flightRequest = FlightRequest::create([
            'customer_id' => $customer->id,
            'aircraft_id' => $aircraft->id,
            'callsign' => $extraction->callsign,
            'origin_airport_id' => $originAirport->id,
            'destination_airport_id' => $destinationAirport->id,
            'departure_at' => $departureAt,
            'arrival_at' => $arrivalAt,
            'passenger_count' => $extraction->passengerCount,
            'crew_count' => $extraction->crewCount,
            'status' => FlightStatus::NewRequest,
            'requested_services_summary' => $extraction->requestedServicesSummary,
            'special_instructions' => $extraction->specialInstructions,
            'source' => RequestSource::Email,
            'extraction_metadata' => $extraction->raw,
        ]);

        // Not in Communication's #[Fillable] list — deliberately, since a
        // form should never be able to move a Communication to a different
        // subject. Direct property assignment bypasses mass-assignment
        // guarding without adding it there.
        $communication->communicable_type = FlightRequest::class;
        $communication->communicable_id = $flightRequest->id;
        $communication->save();

        return $flightRequest;
    }

    private function resolveAirport(?string $code): ?Airport
    {
        if (! $code) {
            return null;
        }

        $code = strtoupper(trim($code));

        return Airport::query()
            ->where('icao_code', $code)
            ->orWhere('iata_code', $code)
            ->first();
    }

    private function parseDate(?string $date): ?Carbon
    {
        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (Exception) {
            return null;
        }
    }
}
