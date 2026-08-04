<?php

namespace App\Domain\Communications\Models;

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Documents\Concerns\HasDocuments;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the timeline — an email, a note, a call summary. This is
 * what both humans and (later) the AI layer read for context, instead of
 * piecing history together from Outlook/WhatsApp/Teams. A Communication is
 * itself documentable (HasDocuments), since an inbound email's attachments
 * belong to the email, not to whatever it eventually gets matched to.
 */
#[Fillable(['type', 'subject', 'body', 'from_address', 'to_address', 'occurred_at', 'author_id', 'author_label', 'metadata'])]
class Communication extends Model
{
    use BelongsToCompany, HasDocuments, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CommunicationType::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function communicable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Who/what this is attributed to, for display — a real user, or
     * whatever label was set for system/AI/external-sender entries.
     */
    public function authorName(): string
    {
        return $this->author?->name ?? $this->author_label ?? 'Unknown';
    }

    /** See Document::subjectLabel() — same duck-typed convention. */
    public function subjectLabel(): string
    {
        $subject = $this->communicable;

        if ($subject && method_exists($subject, 'displayLabel')) {
            return $subject->displayLabel();
        }

        return class_basename($this->communicable_type).' #'.$this->communicable_id;
    }
}
