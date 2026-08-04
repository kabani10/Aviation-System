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

    public function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('file')
                ->required()
                ->storeFiles(false)
                ->hidden(fn (string $operation): bool => $operation === 'edit'),

            TextInput::make('category')
                ->required()
                ->maxLength(255),

            TextInput::make('title')
                ->maxLength(255)
                ->helperText('Defaults to the uploaded filename if left blank.'),

            DateTimePicker::make('expires_at')
                ->native(false)
                ->helperText('Leave blank if this document does not expire.'),

            Textarea::make('notes')
                ->rows(2),
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
                        category: $data['category'],
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
