<?php

namespace App\Filament\Resources\Documents\DocumentResource\Pages;

use App\Domain\Documents\Actions\UploadDocument;
use App\Domain\Documents\Models\Document;
use App\Filament\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Routed through UploadDocument rather than a plain model create — the
 * upload has to actually land on the private disk with the right metadata
 * (mime, size, original filename), which a bare Eloquent create() from form
 * data doesn't do. See UploadDocument for why those fields aren't fillable.
 */
class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function handleRecordCreation(array $data): Document
    {
        $user = Auth::user();

        return app(UploadDocument::class)(
            documentable: $user->company,
            file: $data['file'],
            category: $data['category'],
            title: $data['title'] ?? null,
            notes: $data['notes'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            uploadedBy: $user,
        );
    }
}
