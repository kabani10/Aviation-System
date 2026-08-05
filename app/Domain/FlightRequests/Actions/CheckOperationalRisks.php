<?php

namespace App\Domain\FlightRequests\Actions;

use App\Domain\FlightRequests\DataTransferObjects\OperationalRiskFinding;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Services\Enums\ServiceStatus;
use Illuminate\Support\Collection;

/**
 * The spec's "AI Risk Detection" feature — like CheckMissingInformation,
 * implemented as plain deterministic domain code rather than an AI/* class.
 * Everything checked here (a flagged status, a passed deadline, a stale
 * quote request, a tight deadline with no confirmed supplier, an expired
 * quotation, an overdue invoice) is a direct comparison against data
 * Phases 6/8/10/12 already produce. The genuinely judgment-based part of
 * "should we worry about this supplier" — reading their notes for red
 * flags — lives in SupplierRecommender instead, where it's actually
 * useful: before a supplier is assigned, not after.
 */
class CheckOperationalRisks
{
    /** How long a quote request can sit unanswered before it's flagged. */
    private const STALE_QUOTE_REQUEST_DAYS = 7;

    /** How close a deadline can get before an unconfirmed service is flagged. */
    private const TIGHT_DEADLINE_DAYS = 3;

    private const UNRESOLVED_STATUSES = [ServiceStatus::Confirmed, ServiceStatus::Completed, ServiceStatus::Cancelled];

    /** @return Collection<int, OperationalRiskFinding> */
    public function __invoke(FlightRequest $flightRequest): Collection
    {
        $findings = collect();

        foreach ($flightRequest->services as $service) {
            if ($service->status === ServiceStatus::AtRisk) {
                $findings->push(new OperationalRiskFinding(
                    field: "services.{$service->id}.status",
                    message: "{$service->type->label()} is flagged at risk.",
                    why: 'Marked at-risk by whoever last updated its status — needs attention before the deadline.',
                    affectedService: $service->type->label(),
                ));
            }

            if ($service->isOverdue()) {
                $findings->push(new OperationalRiskFinding(
                    field: "services.{$service->id}.deadline",
                    message: "{$service->type->label()} is past its deadline and still not resolved.",
                    why: 'The deadline has already passed without the service being confirmed, completed, or cancelled.',
                    affectedService: $service->type->label(),
                ));
            }

            if ($service->quote_requested_at !== null
                && $service->quote_received_at === null
                && $service->quote_requested_at->diffInDays(now()) >= self::STALE_QUOTE_REQUEST_DAYS
                && ! in_array($service->status, self::UNRESOLVED_STATUSES, strict: true)) {
                $findings->push(new OperationalRiskFinding(
                    field: "services.{$service->id}.quote_requested_at",
                    message: 'No quote received for '.$service->type->label().' in over '.self::STALE_QUOTE_REQUEST_DAYS.' days.',
                    why: 'The supplier may need a follow-up, or a different supplier may be worth trying.',
                    affectedService: $service->type->label(),
                ));
            }

            if ($service->deadline !== null
                && $service->deadline->isFuture()
                && now()->diffInDays($service->deadline) <= self::TIGHT_DEADLINE_DAYS
                && ! in_array($service->status, self::UNRESOLVED_STATUSES, strict: true)) {
                $findings->push(new OperationalRiskFinding(
                    field: "services.{$service->id}.deadline",
                    message: $service->type->label().' deadline is within '.self::TIGHT_DEADLINE_DAYS.' days and it is not confirmed yet.',
                    why: 'Little time left to resolve this before it becomes overdue.',
                    affectedService: $service->type->label(),
                ));
            }
        }

        foreach ($flightRequest->quotations as $quotation) {
            if ($quotation->isExpired()) {
                $findings->push(new OperationalRiskFinding(
                    field: "quotations.{$quotation->id}.valid_until",
                    message: 'A sent quotation has passed its valid-until date with no response recorded.',
                    why: 'The customer may need a follow-up, or the price may need revisiting before resending.',
                ));
            }
        }

        foreach ($flightRequest->invoices as $invoice) {
            if ($invoice->isOverdue()) {
                $findings->push(new OperationalRiskFinding(
                    field: "invoices.{$invoice->id}.due_date",
                    message: "Invoice {$invoice->invoice_number} is past its due date with no payment recorded.",
                    why: 'The customer may need a payment reminder.',
                ));
            }
        }

        return $findings;
    }
}
