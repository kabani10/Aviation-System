<?php

namespace App\AI\RequestExtraction\Prompts;

use App\Domain\Customers\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Builds the system prompt, tool schema, and user content for one
 * extraction call. Kept out of RequestExtractor so the prompt itself is
 * reviewable on its own — see ARCHITECTURE.md's app/AI/Prompts note.
 */
class RequestExtractionPrompt
{
    /** Hard cap on how many customers get serialized into the prompt — revisit if a tenant's customer list grows past this. */
    private const MAX_CUSTOMERS = 200;

    public function system(): string
    {
        return <<<'PROMPT'
            You are reading an inbound email to an aviation ground-handling and
            flight-support company, sent by one of its existing customers. Extract
            the flight details it describes by calling the extract_flight_request
            tool exactly once — always call it, even if most fields are unclear
            or missing.

            You will be given a list of the company's existing customers and each
            customer's aircraft fleet, with their database ids. Match the sender
            and any aircraft registration mentioned in the email against that
            list. Only set customer_id or aircraft_id when you are genuinely
            confident of the match (a matching billing email domain, an exact
            company name, an exact registration) — leave them null rather than
            guessing. aircraft_id must belong to the matched customer_id.

            For origin_airport_code and destination_airport_code, extract the
            ICAO or IATA code as written in the email — do not invent a code
            from a city name you are not certain about.

            List anything ambiguous, contradictory, or missing that a human
            should double-check in unclear_points. This is read by an operator
            reviewing the draft, not shown to the customer.
            PROMPT;
    }

    /** @return array<string, mixed> */
    public function tool(): array
    {
        return [
            'name' => 'extract_flight_request',
            'description' => 'Record the flight details extracted from the customer email.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => [
                        'type' => ['integer', 'null'],
                        'description' => 'Matched customer id from the provided list, or null if not confident.',
                    ],
                    'aircraft_id' => [
                        'type' => ['integer', 'null'],
                        'description' => "Matched aircraft id from the matched customer's fleet, or null.",
                    ],
                    'callsign' => ['type' => ['string', 'null']],
                    'origin_airport_code' => [
                        'type' => ['string', 'null'],
                        'description' => 'ICAO or IATA code as written in the email.',
                    ],
                    'destination_airport_code' => [
                        'type' => ['string', 'null'],
                        'description' => 'ICAO or IATA code as written in the email.',
                    ],
                    'departure_at' => [
                        'type' => ['string', 'null'],
                        'description' => 'ISO 8601 datetime, best interpretation of the stated departure time.',
                    ],
                    'arrival_at' => [
                        'type' => ['string', 'null'],
                        'description' => 'ISO 8601 datetime, best interpretation of the stated arrival time.',
                    ],
                    'passenger_count' => ['type' => ['integer', 'null']],
                    'crew_count' => ['type' => ['integer', 'null']],
                    'requested_services_summary' => [
                        'type' => ['string', 'null'],
                        'description' => 'The services the customer asked for, in their own words.',
                    ],
                    'special_instructions' => ['type' => ['string', 'null']],
                    'unclear_points' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Anything ambiguous, contradictory, or missing that a human should verify.',
                    ],
                ],
                'required' => [
                    'customer_id', 'aircraft_id', 'callsign', 'origin_airport_code', 'destination_airport_code',
                    'departure_at', 'arrival_at', 'passenger_count', 'crew_count',
                    'requested_services_summary', 'special_instructions', 'unclear_points',
                ],
            ],
        ];
    }

    /** @param  Collection<int, Customer>  $customers */
    public function userContent(string $subject, string $body, Collection $customers): string
    {
        $context = $customers->take(self::MAX_CUSTOMERS)->map(function (Customer $customer): string {
            $aircraft = $customer->aircraft
                ->map(fn ($aircraft): string => "    - Aircraft #{$aircraft->id}: {$aircraft->registration} ({$aircraft->aircraft_type})")
                ->implode("\n");

            return "- Customer #{$customer->id}: {$customer->name} (billing: {$customer->billing_email})".($aircraft ? "\n{$aircraft}" : '');
        })->implode("\n");

        return <<<TEXT
            Known customers and their fleets:
            {$context}

            ---

            Email subject: {$subject}

            Email body:
            {$body}
            TEXT;
    }
}
