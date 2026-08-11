<?php

namespace App\Domain\Services\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Services\Models\SupplierInquiry;
use App\Mail\SupplierBookingConfirmationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * The Phase 17 step after ChooseSupplierInquiry: emails the chosen
 * supplier's contact to confirm the booking at their quoted price, and
 * starts the confirmation round-trip that ExtractSupplierConfirmationFromEmail
 * (or a manual "Mark confirmed") completes. Deliberately a separate
 * timestamp pair (confirmation_requested_at, not requested_at) from the
 * quote cycle above — reusing those would erase the response-time history
 * ComputeSupplierPerformance reads from the RFQ round.
 */
class SendSupplierConfirmation
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(SupplierInquiry $inquiry, ?string $message = null, ?User $sentBy = null): SupplierInquiry
    {
        Mail::to($inquiry->supplierContact->email)->send(new SupplierBookingConfirmationMail($inquiry, $message));

        ($this->logCommunication)(
            communicable: $inquiry,
            type: CommunicationType::EmailOut,
            body: $message ?? "Booking confirmation requested for {$inquiry->service->type->label()}.",
            subject: "Booking confirmation: {$inquiry->service->type->label()}",
            toAddress: $inquiry->supplierContact->email,
            author: $sentBy,
        );

        $inquiry->update(['confirmation_requested_at' => now()]);

        return $inquiry->fresh();
    }
}
