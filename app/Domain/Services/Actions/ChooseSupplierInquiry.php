<?php

namespace App\Domain\Services\Actions;

use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\SupplierInquiry;

/**
 * The moment a comparison across several SupplierInquiry rows turns into an
 * actual decision — copies the winning inquiry's supplier/cost onto its
 * Service (the fields' "chosen supplier" meaning as of Phase 15, see
 * Service's docblock), and demotes any other inquiry on the same service
 * that was previously Chosen back to QuoteReceived, so re-picking a
 * different supplier after changing your mind never leaves two inquiries
 * both marked Chosen at once.
 *
 * Only advances Service.status forward (NotStarted/InformationRequired/
 * SupplierRequestSent -> QuotationReceived) — if the service is already
 * further along (WaitingCustomerApproval, Confirmed, ...), choosing a
 * different inquiry updates the price without silently regressing a status
 * that reflects real progress beyond just having a number to work with.
 */
class ChooseSupplierInquiry
{
    private const ADVANCEABLE_STATUSES = [
        ServiceStatus::NotStarted,
        ServiceStatus::InformationRequired,
        ServiceStatus::SupplierRequestSent,
    ];

    public function __invoke(SupplierInquiry $inquiry): SupplierInquiry
    {
        $service = $inquiry->service;

        $service->supplierInquiries()
            ->where('id', '!=', $inquiry->id)
            ->where('status', SupplierInquiryStatus::Chosen)
            ->update(['status' => SupplierInquiryStatus::QuoteReceived]);

        $inquiry->update(['status' => SupplierInquiryStatus::Chosen]);

        $service->update([
            'supplier_id' => $inquiry->supplier_id,
            'cost' => $inquiry->cost,
            'quote_requested_at' => $inquiry->requested_at,
            'quote_received_at' => $inquiry->responded_at,
            ...in_array($service->status, self::ADVANCEABLE_STATUSES, strict: true)
                ? ['status' => ServiceStatus::QuotationReceived]
                : [],
        ]);

        return $inquiry->fresh();
    }
}
