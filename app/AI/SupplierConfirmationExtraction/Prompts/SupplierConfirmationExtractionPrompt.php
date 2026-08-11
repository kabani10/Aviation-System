<?php

namespace App\AI\SupplierConfirmationExtraction\Prompts;

/**
 * Builds the system prompt, tool schema, and user content for one
 * confirmation-extraction call — same split as
 * SupplierReplyExtractionPrompt/SupplierReplyExtractor.
 */
class SupplierConfirmationExtractionPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
            You are reading an email reply from a supplier who was asked to
            confirm a booking for a service at an already-agreed price. Determine
            whether this email is a clear, affirmative confirmation that they
            will provide the service as booked, by calling the
            extract_supplier_confirmation tool exactly once — always call it.

            Only set confirmed to true when the reply unambiguously affirms the
            booking — "Confirmed", "We'll be there", "Booking confirmed",
            "Yes, all set" and similar. Set it to false for everything else:
            a decline or cancellation, a question, a request to change the
            price or terms, an out-of-office auto-reply, or anything that
            doesn't clearly address confirmation at all. A wrongly-applied
            confirmation is worse than one a human has to apply manually, so
            only say true when you are genuinely confident.
            PROMPT;
    }

    /** @return array<string, mixed> */
    public function tool(): array
    {
        return [
            'name' => 'extract_supplier_confirmation',
            'description' => 'Record whether the supplier clearly confirmed the booking.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'confirmed' => [
                        'type' => 'boolean',
                        'description' => 'True only if the email is an unambiguous confirmation of the booking.',
                    ],
                ],
                'required' => ['confirmed'],
            ],
        ];
    }

    public function userContent(string $serviceLabel, string $subject, string $body): string
    {
        return <<<TEXT
            This email is a reply to a booking confirmation request for: {$serviceLabel}.

            Email subject: {$subject}

            Email body:
            {$body}
            TEXT;
    }
}
