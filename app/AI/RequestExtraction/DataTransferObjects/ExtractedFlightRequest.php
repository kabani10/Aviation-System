<?php

namespace App\AI\RequestExtraction\DataTransferObjects;

/**
 * The structured shape Claude returns from the extract_flight_request tool
 * (see RequestExtractionPrompt::tool()). customer_id/aircraft_id are already
 * resolved to real IDs at this point — Claude is given the tenant's own
 * customers/aircraft as context and asked to match against them directly,
 * rather than returning names for the app to fuzzy-match afterward.
 * legs stays as airport codes, not resolved Airport ids: resolving those is
 * a deterministic DB lookup (CreateFlightRequestFromExtraction), not
 * something worth asking the model to do. Almost always one entry — see
 * legs's own description in the tool schema for when it's more.
 */
final readonly class ExtractedFlightRequest
{
    /**
     * @param  ExtractedFlightLeg[]  $legs
     * @param  string[]  $unclearPoints
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?int $customerId,
        public ?int $aircraftId,
        public ?string $callsign,
        public array $legs,
        public ?int $passengerCount,
        public ?int $crewCount,
        public ?string $requestedServicesSummary,
        public ?string $specialInstructions,
        public array $unclearPoints,
        public array $raw,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromToolInput(array $input): self
    {
        return new self(
            customerId: $input['customer_id'] ?? null,
            aircraftId: $input['aircraft_id'] ?? null,
            callsign: $input['callsign'] ?? null,
            legs: array_map(
                fn (array $leg): ExtractedFlightLeg => ExtractedFlightLeg::fromToolInput($leg),
                $input['legs'] ?? [],
            ),
            passengerCount: $input['passenger_count'] ?? null,
            crewCount: $input['crew_count'] ?? null,
            requestedServicesSummary: $input['requested_services_summary'] ?? null,
            specialInstructions: $input['special_instructions'] ?? null,
            unclearPoints: $input['unclear_points'] ?? [],
            raw: $input,
        );
    }
}
