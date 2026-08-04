<?php

namespace App\Domain\Documents\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file attached to any other record (documentable) — a customer's
 * contract, an aircraft's certificate, an inbound email's attachment.
 * Always private: never served from a public disk, always through
 * DocumentDownloadController's signed route.
 */
#[Fillable(['category', 'title', 'expires_at', 'notes'])]
class Document extends Model
{
    use BelongsToCompany, HasFactory;

    protected static function booted(): void
    {
        static::deleting(fn (Document $document) => Storage::disk($document->disk)->delete($document->path));
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * "Attached to" for display. Deliberately duck-typed rather than an
     * instanceof chain — Document is a leaf module and shouldn't know about
     * every model that can hold one. Any documentable that wants a nicer
     * label than "Company #3" implements displayLabel(): string itself.
     */
    public function subjectLabel(): string
    {
        $subject = $this->documentable;

        if ($subject && method_exists($subject, 'displayLabel')) {
            return $subject->displayLabel();
        }

        return class_basename($this->documentable_type).' #'.$this->documentable_id;
    }

    public function download(): StreamedResponse
    {
        return Storage::disk($this->disk)->download($this->path, $this->original_filename);
    }
}
