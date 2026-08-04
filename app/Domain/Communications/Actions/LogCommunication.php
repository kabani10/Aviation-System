<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The single write path for timeline entries — used directly for manual
 * notes/call summaries, and by ReceiveInboundEmail for inbound mail. Keeping
 * one entry point means every Communication is created the same way whether
 * a human typed it or the inbound webhook did.
 */
class LogCommunication
{
    public function __invoke(
        Model $communicable,
        CommunicationType $type,
        string $body,
        ?string $subject = null,
        ?string $fromAddress = null,
        ?string $toAddress = null,
        ?Carbon $occurredAt = null,
        ?User $author = null,
        ?string $authorLabel = null,
        array $metadata = [],
    ): Communication {
        return $communicable->communications()->create([
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'from_address' => $fromAddress,
            'to_address' => $toAddress,
            'occurred_at' => $occurredAt ?? now(),
            'author_id' => $author?->id,
            'author_label' => $authorLabel,
            'metadata' => $metadata,
        ]);
    }
}
