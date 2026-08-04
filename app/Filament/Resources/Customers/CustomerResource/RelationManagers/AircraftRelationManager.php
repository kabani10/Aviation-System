<?php

namespace App\Filament\Resources\Customers\CustomerResource\RelationManagers;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AircraftRelationManager extends RelationManager
{
    protected static string $relationship = 'aircraft';

    protected static ?string $recordTitleAttribute = 'registration';

    public function form(Form $form): Form
    {
        return $form->schema([
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
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration')->searchable(),
                TextColumn::make('aircraft_type')->label('Type'),
                TextColumn::make('mtow_kg')->label('MTOW (kg)')->numeric()->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
