<?php

namespace App\Domain\Communications\Actions;

use App\AI\RequestExtraction\Jobs\ExtractFlightRequestFromEmail;
use App\AI\SupplierConfirmationExtraction\Jobs\ExtractSupplierConfirmationFromEmail;
use App\AI\SupplierReplyExtraction\Jobs\ExtractSupplierReplyFromEmail;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Domain\Documents\Actions\UploadDocument;
use App\Domain\Tenancy\Models\Company;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Turns a Postmark inbound-parse payload into a Communication (and a
 * Document per attachment), then hands off to three independent queued
 * extraction attempts — ExtractFlightRequestFromEmail (is this a new
 * request from a customer), ExtractSupplierReplyFromEmail (Phase 16 — is
 * this a supplier's reply to an open RFQ), and
 * ExtractSupplierConfirmationFromEmail (Phase 17 — is this a supplier
 * confirming a booking we asked them to confirm). Every inbound email
 * still lands on the Company first regardless of which, if any, it turns
 * out to be — each job moves the Communication onto whatever it
 * confidently matched (a FlightRequest or a SupplierInquiry) only once
 * it's sure; see CreateFlightRequestFromExtraction and
 * MatchSupplierReplyToInquiry/MatchSupplierConfirmationReplyToInquiry.
 */
class ReceiveInboundEmail
{
    public function __construct(
        private readonly LogCommunication $logCommunication,
        private readonly UploadDocument $uploadDocument,
    ) {}

    public function __invoke(Company $company, array $payload): Communication
    {
        $communication = ($this->logCommunication)(
            communicable: $company,
            type: CommunicationType::EmailIn,
            body: $payload['TextBody'] ?? $payload['StrippedTextReply'] ?? '',
            subject: $payload['Subject'] ?? null,
            fromAddress: $payload['From'] ?? null,
            toAddress: $payload['OriginalRecipient'] ?? null,
            occurredAt: $this->parseDate($payload['Date'] ?? null),
            authorLabel: $payload['FromName'] ?? ($payload['From'] ?? 'Unknown sender'),
            metadata: [
                'postmark_message_id' => $payload['MessageID'] ?? null,
            ],
        );

        foreach ($payload['Attachments'] ?? [] as $attachment) {
            $this->storeAttachment($communication, $attachment);
        }

        ExtractFlightRequestFromEmail::dispatch($communication);
        ExtractSupplierReplyFromEmail::dispatch($communication);
        ExtractSupplierConfirmationFromEmail::dispatch($communication);

        return $communication;
    }

    private function parseDate(?string $date): Carbon
    {
        if (! $date) {
            return now();
        }

        try {
            return Carbon::parse($date);
        } catch (Exception) {
            return now();
        }
    }

    private function storeAttachment(Communication $communication, array $attachment): void
    {
        $name = $attachment['Name'] ?? 'attachment';
        $content = base64_decode($attachment['Content'] ?? '', strict: true);

        if ($content === false) {
            Log::warning('Postmark inbound: could not decode attachment', ['name' => $name]);

            return;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'postmark-attachment-');
        file_put_contents($tmpPath, $content);

        $file = new UploadedFile(
            $tmpPath,
            $name,
            $attachment['ContentType'] ?? 'application/octet-stream',
            test: true,
        );

        ($this->uploadDocument)(
            documentable: $communication,
            file: $file,
            category: 'email_attachment',
            title: $name,
        );
    }
}
