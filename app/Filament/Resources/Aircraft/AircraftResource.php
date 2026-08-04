<?php

namespace App\Filament\Resources\Aircraft;

use App\Domain\Aircraft\Models\Aircraft;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Aircraft\AircraftResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Fleet-wide browse across every customer — "find N650GS" without knowing
 * whose aircraft it is. CustomerResource's AircraftRelationManager covers
 * "this customer's fleet"; this resource is the other direction.
 */
class AircraftResource extends Resource
{
    protected static ?string $model = Aircraft::class;

    // Defensive, not currently colliding — see DocumentResource/
    // CommunicationResource for why this is set explicitly on every
    // resource whose folder name matches its pluralized model name.
    protected static ?string $slug = 'aircraft';

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Records';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('customer_id')
                ->label('Customer')
                ->relationship('customer', 'name')
                ->required()
                ->searchable()
                ->preload(),

            TextInput::make('registration')
                ->required()
                ->maxLength(255)
                ->helperText('Tail number, e.g. N650GS'),

            TextInput::make('aircraft_type')
                ->label('Aircraft type')
                ->required()
                ->maxLength(255)
                ->helperText('e.g. Gulfstream G650'),

            TextInput::make('mtow_kg')
                ->label('MTOW (kg)')
                ->numeric(),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->hidden(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration')->searchable(),
                TextColumn::make('aircraft_type')->label('Type')->searchable(),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('mtow_kg')->label('MTOW (kg)')->numeric()->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('registration');
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAircraft::route('/'),
            'create' => Pages\CreateAircraft::route('/create'),
            'edit' => Pages\EditAircraft::route('/{record}/edit'),
        ];
    }
}
