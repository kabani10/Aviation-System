<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers;

use App\Domain\FlightRequests\Models\FlightLeg;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The flight's itinerary — one row per leg, in order. The Create page
 * already sets up leg 1 inline (see CreateFlightRequest), so this is
 * mainly for adding a second/third leg to a multi-stop trip, or fixing a
 * mistake in an existing one. Deleting a leg is allowed (legs are
 * structural, correctable data, not a business record with its own
 * history — see FlightLegPolicy), but never the last one, and never one
 * that already has services on it — those would be silently orphaned.
 */
class LegsRelationManager extends RelationManager
{
    protected static string $relationship = 'legs';

    protected static ?string $recordTitleAttribute = 'sequence';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('sequence')
                ->numeric()
                ->minValue(1)
                ->required()
                ->default(fn (): int => $this->getOwnerRecord()->legs()->max('sequence') + 1),

            Select::make('origin_airport_id')
                ->label('Origin')
                ->relationship('originAirport', 'icao_code')
                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->displayLabel())
                ->required()
                ->searchable()
                ->preload(),

            Select::make('destination_airport_id')
                ->label('Destination')
                ->relationship('destinationAirport', 'icao_code')
                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->displayLabel())
                ->required()
                ->searchable()
                ->preload(),

            DateTimePicker::make('departure_at')
                ->required()
                ->native(false),

            DateTimePicker::make('arrival_at')
                ->required()
                ->native(false)
                ->after('departure_at'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')->label('#')->sortable(),
                TextColumn::make('originAirport.icao_code')->label('From'),
                TextColumn::make('destinationAirport.icao_code')->label('To'),
                TextColumn::make('departure_at')->dateTime()->sortable(),
                TextColumn::make('arrival_at')->dateTime()->sortable(),
                TextColumn::make('services_count')->label('Services')->counts('services'),
            ])
            ->defaultSort('sequence')
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (FlightLeg $record): bool => $this->getOwnerRecord()->legs()->count() > 1
                        && $record->services()->doesntExist()),
            ]);
    }
}
