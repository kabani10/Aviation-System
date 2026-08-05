<?php

namespace App\Domain\Services\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Mail\SupplierQuoteRequestMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a supplier contact asking for a quote on one service, and logs the
 * outbound email as a Communication on the Service itself — the timeline
 * this produces is exactly the data ComputeSupplierPerformance needs for
 * response-time metrics, and what CheckOperationalRisks flags as stale if
 * nothing comes back in time.
 */
class SendSupplierRequest
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(Service $service, SupplierContact $contact, ?string $message = null, ?User $sentBy = null): Service
    {
        Mail::to($contact->email)->send(new SupplierQuoteRequestMail($service, $message));

        ($this->logCommunication)(
            communicable: $service,
            type: CommunicationType::EmailOut,
            body: $message ?? "Quote requested for {$service->type->label()}.",
            subject: "Quote request: {$service->type->label()}",
            toAddress: $contact->email,
            author: $sentBy,
        );

        $service->update([
            'quote_requested_at' => now(),
            'status' => ServiceStatus::SupplierRequestSent,
        ]);

        return $service->fresh();
    }
}
