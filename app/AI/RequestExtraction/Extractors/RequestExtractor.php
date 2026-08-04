<?php

namespace App\AI\RequestExtraction\Extractors;

use App\AI\RequestExtraction\DataTransferObjects\ExtractedFlightRequest;
use App\AI\RequestExtraction\Prompts\RequestExtractionPrompt;
use App\AI\Support\ClaudeApiException;
use App\AI\Support\ClaudeClient;
use App\Domain\Communications\Models\Communication;
use App\Domain\Customers\Models\Customer;

/**
 * Reads one inbound Communication and asks Claude to extract structured
 * flight-request fields from it, matching customer/aircraft against the
 * tenant's own records. Caller is responsible for CurrentCompany already
 * being set (Customer/Aircraft queries below are tenant-scoped) — see
 * ExtractFlightRequestFromEmail.
 */
class RequestExtractor
{
    public function __construct(
        private readonly ClaudeClient $client,
        private readonly RequestExtractionPrompt $prompt,
    ) {}

    public function __invoke(Communication $communication): ExtractedFlightRequest
    {
        $customers = Customer::query()->where('is_active', true)->with('aircraft')->get();

        $response = $this->client->messages(
            messages: [[
                'role' => 'user',
                'content' => $this->prompt->userContent(
                    subject: (string) $communication->subject,
                    body: (string) $communication->body,
                    customers: $customers,
                ),
            ]],
            tools: [$this->prompt->tool()],
            system: $this->prompt->system(),
        );

        $input = $this->client->toolInput($response, 'extract_flight_request');

        if ($input === null) {
            throw new ClaudeApiException('Claude did not call extract_flight_request for communication '.$communication->id);
        }

        return ExtractedFlightRequest::fromToolInput($input);
    }
}
