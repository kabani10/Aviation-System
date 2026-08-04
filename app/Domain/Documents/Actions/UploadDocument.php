<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * The only place a file actually gets written to disk — Filament resources
 * and any future AI attachment-ingestion path both go through this, so
 * storage layout and the private disk never drift between the two.
 */
class UploadDocument
{
    public function __invoke(
        Model $documentable,
        UploadedFile $file,
        string $category,
        ?string $title = null,
        ?string $notes = null,
        ?string $expiresAt = null,
        ?User $uploadedBy = null,
    ): Document {
        $path = $file->store("{$documentable->company_id}", 'documents');

        // Mass-assigned via create() only for the fields a Filament form can
        // legitimately set (category/title/expires_at/notes — see Document's
        // #[Fillable]). Storage metadata is system-derived, never user
        // input, so it's set directly rather than widening what's fillable.
        $document = $documentable->documents()->make([
            'category' => $category,
            'title' => $title ?? $file->getClientOriginalName(),
            'expires_at' => $expiresAt,
            'notes' => $notes,
        ]);

        $document->disk = 'documents';
        $document->path = $path;
        $document->original_filename = $file->getClientOriginalName();
        $document->mime_type = $file->getClientMimeType();
        $document->size = $file->getSize();
        $document->uploaded_by = $uploadedBy?->id;
        $document->save();

        return $document;
    }
}
