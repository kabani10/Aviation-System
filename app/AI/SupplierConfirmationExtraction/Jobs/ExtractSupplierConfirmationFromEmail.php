<?php

namespace App\AI\SupplierConfirmationExtraction\Jobs;

use App\AI\SupplierConfirmationExtraction\Extractors\SupplierConfirmationExtractor;
use App\AI\Support\ClaudeApiException;
use App\Domain\Communications\Models\Communication;
use App\Domain\Services\Actions\ApplySupplierConfirmation;
use App\Domain\Services\Actions\MatchSupplierConfirmationReplyToInquiry;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Third job dispatched by ReceiveInboundEmail on every inbound email,
 * alongside ExtractFlightRequestFromEmail and ExtractSupplierReplyFromEmail
 * — this one asks "is this a supplier confirming a booking we asked them to
 * confirm". Match first (free, MatchSupplierConfirmationReplyToInquiry),
 * only call Claude once a genuine single candidate is found.
 */
class ExtractSupplierConfirmationFromEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Communication $communication) {}

    public function handle(
        MatchSupplierConfirmationReplyToInquiry $matchConfirmation,
        SupplierConfirmationExtractor $extractor,
        ApplySupplierConfirmation $applyConfirmation,
    ): void {
        app(CurrentCompany::class)->set($this->communication->company_id);

        $inquiry = $matchConfirmation($this->communication);

        if ($inquiry === null) {
            return;
        }

        try {
            $extraction = $extractor($this->communication, $inquiry);
        } catch (ClaudeApiException $exception) {
            Log::warning('AI supplier-confirmation extraction failed', [
                'communication_id' => $this->communication->id,
                'supplier_inquiry_id' => $inquiry->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $extraction->confirmed) {
            return;
        }

        $applyConfirmation($inquiry, sourceEmail: $this->communication);
    }
}
