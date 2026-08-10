<?php

namespace App\AI\RequestExtraction\Prompts;

use App\Domain\Customers\Models\Customer;
use App\Domain\Shared\Enums\ServiceType;
use Illuminate\Support\Carbon;
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

            The trip may have more than one leg — a stopover described as "X to
            Y, then Y to Z" is two legs, not one. Extract every leg described,
            in travel order, into the legs array. The common case is a single
            leg; only produce more when the email genuinely describes a
            multi-stop itinerary. For each leg's origin_airport_code and
            destination_airport_code, extract the ICAO or IATA code as written
            in the email — do not invent a code from a city name you are not
            certain about.

            For each leg, also guess which services it will need
            (service_types) — this is a genuine guess, not something that
            needs to be stated outright in the email. Ground handling is
            needed at almost every stop an aircraft actually lands at, so
            include it by default; add fuel, catering, permits, or others
            only when the email or the nature of the trip actually implies
            them (an international border crossing usually needs permits; a
            long trip often needs fuel; a VIP or head-of-state trip needs
            vip_handling and security). This produces draft, unconfirmed
            services for an operator to price and assign a supplier to — not
            a commitment, so guess generously rather than leaving it empty,
            but don't invent services nothing in the email or route suggests.

            You are told the date the email was sent below — use it to resolve
            relative dates ("tomorrow", "next Tuesday", "the 15th") into exact
            ISO 8601 datetimes. Never guess a date using any other year or
            reference point; if the email gives no usable time reference at
            all for a leg (no relative phrase, no explicit date, nothing),
            leave departure_at/arrival_at null rather than inventing one —
            leaving it null is always safer than a wrong flight date. A leg
            missing only its time is still worth extracting: return every
            other field you're confident about and leave the date field(s)
            null, don't drop the whole leg over a missing time.

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
                    'legs' => [
                        'type' => 'array',
                        'description' => 'Every leg of the trip, in travel order. Almost always a single entry; more only for a genuine multi-stop itinerary (e.g. "DXB to IST, then IST to CDG").',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
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
                                    'description' => 'ISO 8601 datetime, best interpretation of the stated departure time for this leg.',
                                ],
                                'arrival_at' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'ISO 8601 datetime, best interpretation of the stated arrival time for this leg.',
                                ],
                                'service_types' => [
                                    'type' => 'array',
                                    'description' => 'Guessed service types this leg will need — see the system prompt. May be empty if genuinely nothing is implied.',
                                    'items' => [
                                        'type' => 'string',
                                        'enum' => collect(ServiceType::cases())->map->value->all(),
                                    ],
                                ],
                            ],
                            'required' => ['origin_airport_code', 'destination_airport_code', 'departure_at', 'arrival_at', 'service_types'],
                        ],
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
                    'customer_id', 'aircraft_id', 'callsign', 'legs', 'passenger_count', 'crew_count',
                    'requested_services_summary', 'special_instructions', 'unclear_points',
                ],
            ],
        ];
    }

    /** @param  Collection<int, Customer>  $customers */
    public function userContent(string $subject, string $body, Collection $customers, Carbon $referenceDate): string
    {
        $context = $customers->take(self::MAX_CUSTOMERS)->map(function (Customer $customer): string {
            $aircraft = $customer->aircraft
                ->map(fn ($aircraft): string => "    - Aircraft #{$aircraft->id}: {$aircraft->registration} ({$aircraft->aircraft_type})")
                ->implode("\n");

            return "- Customer #{$customer->id}: {$customer->name} (billing: {$customer->billing_email})".($aircraft ? "\n{$aircraft}" : '');
        })->implode("\n");

        return <<<TEXT
            This email was sent on {$referenceDate->toDayDateTimeString()} — resolve any relative dates in it against that.

            Known customers and their fleets:
            {$context}

            ---

            Email subject: {$subject}

            Email body:
            {$body}
            TEXT;
    }
}
