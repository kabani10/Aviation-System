<?php

namespace App\Domain\Services\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
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
 */
class RecordSupplierInquiryResponse
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(SupplierInquiry $inquiry, float $cost, ?string $notes = null, ?User $recordedBy = null): SupplierInquiry
    {
        $inquiry->update([
            'cost' => $cost,
            'notes' => $notes,
            'responded_at' => now(),
            'status' => SupplierInquiryStatus::QuoteReceived,
        ]);

        ($this->logCommunication)(
            communicable: $inquiry,
            type: CommunicationType::EmailIn,
            body: $notes ?? "Quote received: \${$cost}.",
            subject: "Quote received: {$inquiry->service->type->label()}",
            author: $recordedBy,
        );

        return $inquiry->fresh();
    }
}
