<?php

namespace App\AI\SupplierConfirmationExtraction\DataTransferObjects;

/**
 * The structured shape Claude returns from the extract_supplier_confirmation
 * tool. `confirmed !== true` (null, or absent) is the model's own "not a
 * clear confirmation" signal — same two-state shape as
 * ExtractedSupplierReply::$cost, deliberately not a true/false/null
 * tri-state: only a clear "yes" is safe to auto-apply, and everything else
 * (a decline, a question, silence on the topic) is treated identically —
 * leave it for a human, don't try to distinguish "declined" from "unclear"
 * automatically.
 */
final readonly class ExtractedSupplierConfirmation
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public bool $confirmed,
        public array $raw,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromToolInput(array $input): self
    {
        return new self(
            confirmed: $input['confirmed'] === true,
            raw: $input,
        );
    }
}
