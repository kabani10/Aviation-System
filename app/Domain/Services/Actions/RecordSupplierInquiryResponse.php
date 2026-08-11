<?php

namespace App\Domain\Services\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\SupplierInquiry;
use App\Models\User;

/**
 * The Phase 15 replacement for RecordSupplierQuote — records one supplier's
 * reply against its own inquiry, not the Service directly, since a service
 * can have several inquiries out and each one's price needs to stay
 * attributed to the supplier that actually quoted it. This alone doesn't
 * touch the Service — see ChooseSupplierInquiry for the step that copies a
 * price onto it once the operator actually decides.
 *
 * `$sourceEmail`, as of Phase 16, is how an AI-detected reply gets recorded
 * (see ExtractSupplierReplyFromEmail): rather than fabricating a second
 * "quote received" Communication that duplicates what the real inbound
 * email already says, the real Communication is moved onto the inquiry
 * directly — same "not in Communication's #[Fillable] list, deliberately,
 * so a form can never move a Communication to a different subject, direct
 * property assignment instead" pattern CreateFlightRequestFromExtraction
 * uses. Manual entry (an operator typing in what came back by phone or a
 * plain-text summary) has no such email to move, so it keeps synthesizing
 * one via LogCommunication like before — "whether the quote actually
 * arrived by email or was recorded from a phone call, the timeline records
 * it as received either way."
 */
class RecordSupplierInquiryResponse
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(
        SupplierInquiry $inquiry,
        float $cost,
        ?string $notes = null,
        ?User $recordedBy = null,
        ?Communication $sourceEmail = null,
    ): SupplierInquiry {
        $inquiry->update([
            'cost' => $cost,
            'notes' => $notes,
            'responded_at' => now(),
            'status' => SupplierInquiryStatus::QuoteReceived,
        ]);

        if ($sourceEmail) {
            $sourceEmail->communicable_type = SupplierInquiry::class;
            $sourceEmail->communicable_id = $inquiry->id;
            $sourceEmail->save();
        } else {
            ($this->logCommunication)(
                communicable: $inquiry,
                type: CommunicationType::EmailIn,
                body: $notes ?? "Quote received: \${$cost}.",
                subject: "Quote received: {$inquiry->service->type->label()}",
                author: $recordedBy,
            );
        }

        return $inquiry->fresh();
    }
}
