<?php

namespace App\Filament\Resources\Communications;

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Filament\Resources\Communications\CommunicationResource\Pages;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The company-wide timeline — every email, note, and call summary,
 * regardless of what it's attached to. Same "browse everything now, scoped
 * RelationManager later" split as DocumentResource once Customer/Supplier/
 * FlightRequest exist to hang a per-record timeline off of.
 */
class CommunicationResource extends Resource
{
    protected static ?string $model = Communication::class;

    // Without this, Filament derives "communications/communications" from
    // the Communications/ folder + the pluralized model name colliding.
    protected static ?string $slug = 'communications';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Records';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('type')
                ->options(collect(CommunicationType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                ->required()
                ->native(false)
                ->live(),

            TextInput::make('subject')
                ->maxLength(255),

            Textarea::make('body')
                ->required()
                ->rows(4),

            TextInput::make('from_address')
                ->email()
                ->visible(fn (Get $get): bool => $get('type') === CommunicationType::EmailIn->value),

            TextInput::make('to_address')
                ->email()
                ->visible(fn (Get $get): bool => $get('type') === CommunicationType::EmailOut->value),

            DateTimePicker::make('occurred_at')
                ->required()
                ->default(now())
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (CommunicationType $state): string => $state->label())
                    ->icon(fn (CommunicationType $state): string => $state->icon()),
                TextColumn::make('subject')->searchable()->placeholder('—'),
                TextColumn::make('body')->limit(60)->tooltip(fn (Communication $record): string => $record->body),
                TextColumn::make('subject_label')
                    ->label('Attached to')
                    ->state(fn (Communication $record): string => $record->subjectLabel()),
                TextColumn::make('author')
                    ->label('From')
                    ->state(fn (Communication $record): string => $record->authorName()),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(CommunicationType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommunications::route('/'),
            'create' => Pages\CreateCommunication::route('/create'),
            'edit' => Pages\EditCommunication::route('/{record}/edit'),
        ];
    }
}
