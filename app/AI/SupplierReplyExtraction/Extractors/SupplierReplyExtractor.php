<?php

namespace App\AI\SupplierReplyExtraction\Extractors;

use App\AI\SupplierReplyExtraction\DataTransferObjects\ExtractedSupplierReply;
use App\AI\SupplierReplyExtraction\Prompts\SupplierReplyExtractionPrompt;
use App\AI\Support\ClaudeApiException;
use App\AI\Support\ClaudeClient;
use App\Domain\Communications\Models\Communication;
use App\Domain\Services\Models\SupplierInquiry;

/**
 * Reads one inbound Communication already matched to a specific
 * SupplierInquiry (see MatchSupplierReplyToInquiry) and asks Claude to
 * extract the quoted price, if any. The inquiry's own service type is
 * given as context so the model knows what was actually asked about.
 */
class SupplierReplyExtractor
{
    public function __construct(
        private readonly ClaudeClient $client,
        private readonly SupplierReplyExtractionPrompt $prompt,
    ) {}

    public function __invoke(Communication $communication, SupplierInquiry $inquiry): ExtractedSupplierReply
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

        $input = $this->client->toolInput($response, 'extract_supplier_reply');

        if ($input === null) {
            throw new ClaudeApiException('Claude did not call extract_supplier_reply for communication '.$communication->id);
        }

        return ExtractedSupplierReply::fromToolInput($input);
    }
}
