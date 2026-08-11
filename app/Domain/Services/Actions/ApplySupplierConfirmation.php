<?php

namespace App\Domain\Services\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\SupplierInquiry;
use App\Models\User;

/**
 * The moment a chosen supplier's booking is actually confirmed — reached
 * either manually ("Mark confirmed") or automatically
 * (ExtractSupplierConfirmationFromEmail). Same dual manual/AI shape as
 * RecordSupplierInquiryResponse: `$sourceEmail`, when given, is moved onto
 * the inquiry instead of a synthetic Communication being logged, since the
 * real reply already says what it needs to.
 *
 * Mirrors the winning inquiry's confirmation onto Service — supplier_confirmed_at
 * always gets the timestamp (a plain fact, safe to record regardless), but
 * Service.status only advances to Confirmed when it isn't already there or
 * further along (Completed/Cancelled), same "only ever move forward, never
 * silently rewind real progress" guard ChooseSupplierInquiry already uses
 * for the price.
 */
class ApplySupplierConfirmation
{
    private const ALREADY_PAST_CONFIRMED = [
        ServiceStatus::Confirmed,
        ServiceStatus::Completed,
        ServiceStatus::Cancelled,
    ];

    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(
        SupplierInquiry $inquiry,
        ?string $notes = null,
        ?User $confirmedBy = null,
        ?Communication $sourceEmail = null,
    ): SupplierInquiry {
        $inquiry->update([
            'status' => SupplierInquiryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        if ($sourceEmail) {
            $sourceEmail->communicable_type = SupplierInquiry::class;
            $sourceEmail->communicable_id = $inquiry->id;
            $sourceEmail->save();
        } else {
            ($this->logCommunication)(
                communicable: $inquiry,
                type: CommunicationType::EmailIn,
                body: $notes ?? 'Supplier confirmed the booking.',
                subject: "Booking confirmed: {$inquiry->service->type->label()}",
                author: $confirmedBy,
            );
        }

        $service = $inquiry->service;

        $service->update([
            'supplier_confirmed_at' => now(),
            ...in_array($service->status, self::ALREADY_PAST_CONFIRMED, strict: true)
                ? []
                : ['status' => ServiceStatus::Confirmed],
        ]);

        return $inquiry->fresh();
    }
}
