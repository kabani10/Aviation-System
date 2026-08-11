<?php

namespace App\AI\SupplierReplyExtraction\Prompts;

/**
 * Builds the system prompt, tool schema, and user content for one
 * supplier-reply extraction call — kept out of SupplierReplyExtractor so
 * the prompt itself is reviewable on its own, same split as
 * RequestExtractionPrompt/RequestExtractor.
 */
class SupplierReplyExtractionPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
            You are reading an email reply from a supplier — a ground handler,
            fuel provider, permit agency, or similar vendor — who was asked for
            a price quote on one service. Extract the quoted price, if the
            email states one, by calling the extract_supplier_reply tool
            exactly once — always call it, even if no price is mentioned.

            Only set cost when the email clearly states a specific price for
            the service that was asked about, in US dollars. Leave it null for
            everything else: a clarifying question, a request for more
            information, a decline, an out-of-office auto-reply, a price given
            only as a vague range or "it depends" with no single number, or an
            amount in a different currency you can't confidently convert.
            Guessing a number that isn't actually stated would be worse than
            leaving it null — an operator would rather see nothing extracted
            than a wrong price recorded automatically.
            PROMPT;
    }

    /** @return array<string, mixed> */
    public function tool(): array
    {
        return [
            'name' => 'extract_supplier_reply',
            'description' => "Record the price quoted in the supplier's reply, if any.",
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'cost' => [
                        'type' => ['number', 'null'],
                        'description' => 'The quoted price in US dollars, or null if the email does not clearly state one.',
                    ],
                ],
                'required' => ['cost'],
            ],
        ];
    }

    public function userContent(string $serviceLabel, string $subject, string $body): string
    {
        return <<<TEXT
            This email is a reply to a quote request for: {$serviceLabel}.

            Email subject: {$subject}

            Email body:
            {$body}
            TEXT;
    }
}
