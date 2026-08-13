<?php

namespace App\Filament\RelationManagers;

use App\Domain\Documents\Actions\UploadDocument;
use App\Domain\Documents\Models\Document;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;

/**
 * Attach to any resource whose model uses HasDocuments — the relationship
 * is always named 'documents' (see App\Domain\Documents\Concerns\
 * HasDocuments), so this one class works for Customer, Aircraft, and
 * whatever else picks up the trait later. Mirrors DocumentResource's form
 * and table; kept as a separate class because a RelationManager and a
 * Resource page aren't interchangeable in Filament, not because the two
 * needed different behavior.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * Filament defaults every relation manager to read-only on a resource's
     * View page (Panel::hasReadOnlyRelationManagersOnResourceViewPagesByDefault(),
     * true out of the box, never overridden here) — meaning Create/Edit/Delete
     * silently disappear whenever a flight/customer/aircraft/supplier is
     * opened via View instead of Edit, for every role including Admin, since
     * this check runs before any permission check. Attaching paperwork isn't
     * the kind of "edit mode" action that default is meant to guard, and
     * several roles (Procurement, Finance, Management — see
     * RolesAndPermissionsSeeder) only ever reach a resource's View page for
     * flights, never Edit, so leaving this at its default would make
     * document upload permanently unreachable for them, not just less
     * convenient.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /** UploadDocument's $category is a required, non-nullable column — this stands in whenever the upload form doesn't ask for one. */
    public const DEFAULT_CATEGORY = 'general';

    public function form(Form $form): Form
    {
        return $form->schema([
            // Uploading only ever asks for the file and an optional
            // description — category/title/expiry are real columns
            // (organizational metadata, not needed to get a file attached)
            // still reachable via Edit afterward for whoever wants to set
            // them, same "don't block the common case on the rare one" as
            // the AI-draft review page's inline customer/aircraft creation.
            FileUpload::make('file')
                ->required()
                ->storeFiles(false)
                ->hidden(fn (string $operation): bool => $operation === 'edit'),

            Textarea::make('notes')
                ->label('Description')
                ->rows(2),

            TextInput::make('category')
                ->maxLength(255)
                ->hidden(fn (string $operation): bool => $operation === 'create'),

            TextInput::make('title')
                ->maxLength(255)
                ->helperText('Defaults to the uploaded filename if left blank.')
                ->hidden(fn (string $operation): bool => $operation === 'create'),

            DateTimePicker::make('expires_at')
                ->native(false)
                ->helperText('Leave blank if this document does not expire.')
                ->hidden(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('category')->badge(),
                TextColumn::make('size')->formatStateUsing(fn (int $state): string => Number::fileSize($state)),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable()
                    ->color(fn (Document $record): ?string => $record->isExpired() ? 'danger' : null)
                    ->placeholder('Never'),
                TextColumn::make('uploadedBy.name')->label('Uploaded by')->placeholder('System'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): Document => app(UploadDocument::class)(
                        documentable: $this->getOwnerRecord(),
                        file: $data['file'],
                        category: $data['category'] ?? self::DEFAULT_CATEGORY,
                        title: $data['title'] ?? null,
                        notes: $data['notes'] ?? null,
                        expiresAt: $data['expires_at'] ?? null,
                        uploadedBy: Auth::user(),
                    )),
            ])
            ->actions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Document $record): string => URL::temporarySignedRoute(
                        'documents.download',
                        now()->addMinutes(5),
                        ['document' => $record],
                    ))
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
