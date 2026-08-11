<?php

namespace App\Domain\Services\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Services\Models\SupplierInquiry;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Mail\SupplierQuoteRequestMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * The Phase 15 replacement for SendSupplierRequest — emails one candidate
 * supplier contact and creates the SupplierInquiry that tracks this
 * specific RFQ, rather than writing straight onto the Service. Calling this
 * more than once for the same Service (a different supplier, or the same
 * one again) is the normal case now, not an edge case — see SupplierInquiry's
 * docblock. The outbound email is logged on the *inquiry*, not the Service,
 * so a service with three inquiries out keeps three separate conversations
 * rather than one blurred timeline.
 */
class SendSupplierInquiry
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(Service $service, Supplier $supplier, SupplierContact $contact, ?string $message = null, ?User $sentBy = null): SupplierInquiry
    {
        $inquiry = $service->supplierInquiries()->create([
            'supplier_id' => $supplier->id,
            'supplier_contact_id' => $contact->id,
            'requested_by' => $sentBy?->id,
            'status' => SupplierInquiryStatus::Sent,
            'requested_at' => now(),
        ]);

        Mail::to($contact->email)->send(new SupplierQuoteRequestMail($service, $message));

        ($this->logCommunication)(
            communicable: $inquiry,
            type: CommunicationType::EmailOut,
            body: $message ?? "Quote requested for {$service->type->label()}.",
            subject: "Quote request: {$service->type->label()}",
            toAddress: $contact->email,
            author: $sentBy,
        );

        // The first inquiry sent is the signal "we've started reaching out"
        // for the flat services table's own status badge — later inquiries
        // for the same service (a second candidate supplier) don't move it
        // again, and don't regress a service that's already further along
        // (e.g. a manual status override, or a previously chosen supplier).
        if ($service->status === ServiceStatus::NotStarted || $service->status === ServiceStatus::InformationRequired) {
            $service->update(['status' => ServiceStatus::SupplierRequestSent]);
        }

        return $inquiry->fresh();
    }
}
