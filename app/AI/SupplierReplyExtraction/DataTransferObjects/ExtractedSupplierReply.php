<?php

namespace App\AI\SupplierReplyExtraction\DataTransferObjects;

/**
 * The structured shape Claude returns from the extract_supplier_reply tool
 * (see SupplierReplyExtractionPrompt::tool()). `cost === null` is the
 * model's own "I'm not confident there's a price here" signal — the caller
 * (ExtractSupplierReplyFromEmail) treats it exactly like
 * CreateFlightRequestFromExtraction treats an unresolved leg: leave the
 * email alone rather than recording a guess.
 */
final readonly class ExtractedSupplierReply
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public ?float $cost,
        public array $raw,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromToolInput(array $input): self
    {
        return new self(
            cost: isset($input['cost']) ? (float) $input['cost'] : null,
            raw: $input,
        );
    }
}
