<?php

namespace App\AI\RequestExtraction\Jobs;

use App\AI\RequestExtraction\Actions\CreateFlightRequestFromExtraction;
use App\AI\RequestExtraction\Extractors\RequestExtractor;
use App\AI\Support\ClaudeApiException;
use App\Domain\Communications\Models\Communication;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched by ReceiveInboundEmail after logging the Communication.
 * Doesn't touch the Claude API itself — that's RequestExtractor's job, kept
 * separate so the "call the API" concern and the "run it on the queue"
 * concern don't get tangled. A failed extraction (no API key configured,
 * Claude declined, network error) is logged and left alone — the
 * Communication still exists on the Company either way, so nothing is lost,
 * it just doesn't turn into a draft automatically.
 */
class ExtractFlightRequestFromEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Communication $communication) {}

    public function handle(RequestExtractor $extractor, CreateFlightRequestFromExtraction $createFromExtraction): void
    {
        app(CurrentCompany::class)->set($this->communication->company_id);

        try {
            $extraction = $extractor($this->communication);
        } catch (ClaudeApiException $exception) {
            Log::warning('AI request extraction failed', [
                'communication_id' => $this->communication->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $createFromExtraction($this->communication, $extraction);
    }
}
