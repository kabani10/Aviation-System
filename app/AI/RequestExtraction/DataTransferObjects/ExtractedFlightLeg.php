<?php

namespace App\AI\RequestExtraction\DataTransferObjects;

/**
 * One entry of ExtractedFlightRequest::$legs — see that class for context.
 * serviceTypes stays as raw strings here, same reasoning as the DTO not
 * resolving airport codes itself — CreateFlightRequestFromExtraction is
 * where an unrecognized value gets filtered rather than trusted.
 */
final readonly class ExtractedFlightLeg
{
    /** @param  string[]  $serviceTypes */
    public function __construct(
        public ?string $originAirportCode,
        public ?string $destinationAirportCode,
        public ?string $departureAt,
        public ?string $arrivalAt,
        public array $serviceTypes,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromToolInput(array $input): self
    {
        return new self(
            originAirportCode: $input['origin_airport_code'] ?? null,
            destinationAirportCode: $input['destination_airport_code'] ?? null,
            departureAt: $input['departure_at'] ?? null,
            arrivalAt: $input['arrival_at'] ?? null,
            serviceTypes: $input['service_types'] ?? [],
        );
    }
}
