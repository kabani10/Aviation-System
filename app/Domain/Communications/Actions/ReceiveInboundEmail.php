<?php

namespace App\Domain\Communications\Actions;

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
 * Document per attachment). Not matched to a flight/customer here — that
 * matching needs modules that don't exist yet (Flight Request, AI
 * extraction). Until then every inbound email lands on the Company itself,
 * visible in the company-wide Communications list.
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
