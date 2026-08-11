<?php

namespace App\AI\SupplierConfirmationExtraction\Extractors;

use App\AI\SupplierConfirmationExtraction\DataTransferObjects\ExtractedSupplierConfirmation;
use App\AI\SupplierConfirmationExtraction\Prompts\SupplierConfirmationExtractionPrompt;
use App\AI\Support\ClaudeApiException;
use App\AI\Support\ClaudeClient;
use App\Domain\Communications\Models\Communication;
use App\Domain\Services\Models\SupplierInquiry;

/**
 * Reads one inbound Communication already matched to a specific
 * SupplierInquiry awaiting confirmation (see
 * MatchSupplierConfirmationReplyToInquiry) and asks Claude whether it's a
 * clear confirmation.
 */
class SupplierConfirmationExtractor
{
    public function __construct(
        private readonly ClaudeClient $client,
        private readonly SupplierConfirmationExtractionPrompt $prompt,
    ) {}

    public function __invoke(Communication $communication, SupplierInquiry $inquiry): ExtractedSupplierConfirmation
    {
        $response = $this->client->messages(
            messages: [[
                'role' => 'user',
                'content' => $this->prompt->userContent(
                    serviceLabel: $inquiry->service->type->label(),
                    subject: (string) $communication->subject,
                    body: (string) $communication->body,
                ),
            ]],
            tools: [$this->prompt->tool()],
            system: $this->prompt->system(),
        );

        $input = $this->client->toolInput($response, 'extract_supplier_confirmation');

        if ($input === null) {
            throw new ClaudeApiException('Claude did not call extract_supplier_confirmation for communication '.$communication->id);
        }

        return ExtractedSupplierConfirmation::fromToolInput($input);
    }
}
