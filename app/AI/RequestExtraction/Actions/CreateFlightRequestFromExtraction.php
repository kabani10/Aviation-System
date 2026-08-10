<?php

namespace App\AI\RequestExtraction\Actions;

use App\AI\RequestExtraction\DataTransferObjects\ExtractedFlightLeg;
use App\AI\RequestExtraction\DataTransferObjects\ExtractedFlightRequest;
use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Communications\Models\Communication;
use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Enums\RequestSource;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Shared\Enums\ServiceType;
use Exception;
use Illuminate\Support\Carbon;

/**
 * Turns an extraction into a real FlightRequest — but only when every
 * hard-required field (customer, aircraft belonging to that customer, and
 * every extracted leg's airports and a valid departure/arrival pair)
 * resolved with confidence. The schema requires those columns NOT NULL, so
 * a partial extraction can't become a partial FlightRequest the way the
 * spec's "AI draft" language might suggest; instead the raw extraction is
 * stashed on the Communication's metadata for an operator to use when
 * creating the request by hand, and the Communication stays where
 * ReceiveInboundEmail put it (on the Company). One unresolved leg fails
 * the whole extraction, same all-or-nothing reasoning as an unresolved
 * customer or aircraft — a flight missing its second leg isn't a draft
 * worth auto-creating.
 *
 * Each leg's guessed service_types (see RequestExtractionPrompt) become
 * real, draft Service rows — status NotStarted, no supplier or price, the
 * same blank state an operator creating one by hand would leave it in.
 * These are a best-effort guess, not a hard-required field: an
 * unrecognized or empty service_types list doesn't affect confidence at
 * all, it just means fewer (or no) services get pre-created.
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
        $legs = $this->resolveLegs($extraction->legs);

        $confident = $customer !== null
            && $aircraft !== null
            && $aircraft->customer_id === $customer->id
            && $legs !== null;

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
            'passenger_count' => $extraction->passengerCount,
            'crew_count' => $extraction->crewCount,
            'status' => FlightStatus::NewRequest,
            'requested_services_summary' => $extraction->requestedServicesSummary,
            'special_instructions' => $extraction->specialInstructions,
            'source' => RequestSource::Email,
            'extraction_metadata' => $extraction->raw,
        ]);

        foreach ($legs as $index => $leg) {
            $flightLeg = $flightRequest->legs()->create([
                'sequence' => $index + 1,
                'origin_airport_id' => $leg['origin']->id,
                'destination_airport_id' => $leg['destination']->id,
                'departure_at' => $leg['departure'],
                'arrival_at' => $leg['arrival'],
            ]);

            foreach ($this->resolveServiceTypes($leg['serviceTypes']) as $serviceType) {
                $flightLeg->services()->create([
                    'flight_request_id' => $flightRequest->id,
                    'type' => $serviceType,
                    'status' => ServiceStatus::NotStarted,
                ]);
            }
        }

        // Not in Communication's #[Fillable] list — deliberately, since a
        // form should never be able to move a Communication to a different
        // subject. Direct property assignment bypasses mass-assignment
        // guarding without adding it there.
        $communication->communicable_type = FlightRequest::class;
        $communication->communicable_id = $flightRequest->id;
        $communication->save();

        return $flightRequest;
    }

    /**
     * Resolves every extracted leg to a real Airport pair and a valid
     * departure/arrival, in order. Returns null — not a partial list — if
     * there are no legs at all, or any single one fails to resolve.
     * service_types passes through unvalidated (see resolveServiceTypes).
     *
     * @param  ExtractedFlightLeg[]  $legs
     * @return array<int, array{origin: Airport, destination: Airport, departure: Carbon, arrival: Carbon, serviceTypes: string[]}>|null
     */
    private function resolveLegs(array $legs): ?array
    {
        if ($legs === []) {
            return null;
        }

        $resolved = [];

        foreach ($legs as $leg) {
            $origin = $this->resolveAirport($leg->originAirportCode);
            $destination = $this->resolveAirport($leg->destinationAirportCode);
            $departure = $this->parseDate($leg->departureAt);
            $arrival = $this->parseDate($leg->arrivalAt);

            if ($origin === null || $destination === null || $departure === null || $arrival === null || ! $arrival->isAfter($departure)) {
                return null;
            }

            $resolved[] = [
                'origin' => $origin,
                'destination' => $destination,
                'departure' => $departure,
                'arrival' => $arrival,
                'serviceTypes' => $leg->serviceTypes,
            ];
        }

        return $resolved;
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

    /**
     * @param  string[]  $rawTypes
     * @return ServiceType[]
     */
    private function resolveServiceTypes(array $rawTypes): array
    {
        return collect($rawTypes)
            ->map(fn (string $type): ?ServiceType => ServiceType::tryFrom($type))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
