<?php

namespace App\Filament\RelationManagers;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Attach to any resource whose model uses HasCommunications — see
 * DocumentsRelationManager's docblock, same reasoning.
 */
class CommunicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'communications';

    protected static ?string $recordTitleAttribute = 'subject';

    public function form(Form $form): Form
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

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (CommunicationType $state): string => $state->label())
                    ->icon(fn (CommunicationType $state): string => $state->icon()),
                TextColumn::make('subject')->searchable()->placeholder('—'),
                TextColumn::make('body')->limit(60)->tooltip(fn (Communication $record): string => $record->body),
                TextColumn::make('author')
                    ->label('From')
                    ->state(fn (Communication $record): string => $record->authorName()),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(CommunicationType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): Communication => app(LogCommunication::class)(
                        communicable: $this->getOwnerRecord(),
                        type: CommunicationType::from($data['type']),
                        body: $data['body'],
                        subject: $data['subject'] ?? null,
                        fromAddress: $data['from_address'] ?? null,
                        toAddress: $data['to_address'] ?? null,
                        occurredAt: Carbon::parse($data['occurred_at']),
                        author: Auth::user(),
                    )),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
