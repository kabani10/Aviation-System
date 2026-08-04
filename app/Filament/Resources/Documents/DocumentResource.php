<?php

namespace App\Filament\Resources\Documents;

use App\Domain\Documents\Models\Document;
use App\Filament\Resources\Documents\DocumentResource\Pages;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;

/**
 * Company-wide document library — every document attached to anything in
 * this tenant, regardless of what it's attached to. Once Customer/Supplier/
 * Aircraft/FlightRequest resources exist, each gets its own scoped
 * DocumentsRelationManager over the same Document model instead of
 * duplicating this table; this resource stays as the "browse everything" view.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    // Without this, Filament derives "documents/documents" from the
    // Documents/ folder + the pluralized model name colliding.
    protected static ?string $slug = 'documents';

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationGroup = 'Records';

    public static function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('file')
                ->required()
                ->storeFiles(false)
                ->hidden(fn (string $operation): bool => $operation === 'edit'),

            TextInput::make('category')
                ->required()
                ->maxLength(255)
                ->helperText('e.g. business_license, insurance_certificate — freeform, no fixed list yet.'),

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('category')->badge(),
                TextColumn::make('subject')
                    ->label('Attached to')
                    ->state(fn (Document $record): string => $record->subjectLabel()),
                TextColumn::make('size')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state)),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable()
                    ->color(fn (Document $record): ?string => $record->isExpired() ? 'danger' : null)
                    ->placeholder('Never'),
                TextColumn::make('uploadedBy.name')->label('Uploaded by')->placeholder('System'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Document $record): string => URL::temporarySignedRoute(
                        'documents.download',
                        now()->addMinutes(5),
                        ['document' => $record],
                    ))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
