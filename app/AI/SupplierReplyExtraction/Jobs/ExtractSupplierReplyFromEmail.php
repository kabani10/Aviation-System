<?php

namespace App\AI\SupplierReplyExtraction\Jobs;

use App\AI\SupplierReplyExtraction\Extractors\SupplierReplyExtractor;
use App\AI\Support\ClaudeApiException;
use App\Domain\Communications\Models\Communication;
use App\Domain\Services\Actions\MatchSupplierReplyToInquiry;
use App\Domain\Services\Actions\RecordSupplierInquiryResponse;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched by ReceiveInboundEmail alongside ExtractFlightRequestFromEmail
 * — every inbound email gets tried against both "is this a new flight
 * request" and "is this a supplier's reply to an open RFQ", since there's
 * no way to know which (if either) it is ahead of time. Matching first
 * (MatchSupplierReplyToInquiry) is a free deterministic lookup, so a
 * non-supplier email costs nothing beyond that one query — Claude is only
 * called once a genuine open inquiry is actually found.
 */
class ExtractSupplierReplyFromEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Communication $communication) {}

    public function handle(
        MatchSupplierReplyToInquiry $matchReply,
        SupplierReplyExtractor $extractor,
        RecordSupplierInquiryResponse $recordResponse,
    ): void {
        app(CurrentCompany::class)->set($this->communication->company_id);

        $inquiry = $matchReply($this->communication);

        if ($inquiry === null) {
            return;
        }

        try {
            $extraction = $extractor($this->communication, $inquiry);
        } catch (ClaudeApiException $exception) {
            Log::warning('AI supplier-reply extraction failed', [
                'communication_id' => $this->communication->id,
                'supplier_inquiry_id' => $inquiry->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($extraction->cost === null) {
            return;
        }

        $recordResponse($inquiry, $extraction->cost, sourceEmail: $this->communication);
    }
}
