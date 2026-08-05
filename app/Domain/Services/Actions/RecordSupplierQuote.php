<?php

namespace App\Domain\Services\Actions;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Models\User;

/**
 * Records a supplier's response to a quote request — sets cost and
 * quote_received_at, moves the service to QuotationReceived, and logs the
 * response as a Communication so the timeline (and
 * ComputeSupplierPerformance's response-time metric) reflects when the
 * quote actually came back, not just when someone got around to entering it.
 */
class RecordSupplierQuote
{
    public function __construct(private readonly LogCommunication $logCommunication) {}

    public function __invoke(Service $service, float $cost, ?string $notes = null, ?User $recordedBy = null): Service
    {
        $service->update([
            'cost' => $cost,
            'quote_received_at' => now(),
            'status' => ServiceStatus::QuotationReceived,
        ]);

        ($this->logCommunication)(
            communicable: $service,
            type: CommunicationType::EmailIn,
            body: $notes ?? "Quote received: \${$cost}.",
            subject: "Quote received: {$service->type->label()}",
            author: $recordedBy,
        );

        return $service->fresh();
    }
}
