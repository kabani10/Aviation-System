<?php

namespace App\Filament\Resources\Suppliers;

use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Filament\RelationManagers\CommunicationsRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Suppliers\SupplierResource\Pages;
use App\Filament\Resources\Suppliers\SupplierResource\RelationManagers\ContactsRelationManager;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    // Without this, Filament derives "suppliers/suppliers" from the
    // Suppliers/ folder + the pluralized model name colliding — see
    // DocumentResource/CommunicationResource/CustomerResource.
    protected static ?string $slug = 'suppliers';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Records';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('currency')
                ->maxLength(3)
                ->helperText('ISO currency code, e.g. USD')
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),

            TextInput::make('payment_terms')
                ->maxLength(255)
                ->placeholder('e.g. Net 30'),

            Select::make('services_offered')
                ->label('Services offered')
                ->options(collect(ServiceType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                ->multiple()
                ->searchable(),

            // No ->preload() — with ~10k airports (see database/data/README.md)
            // that would ship every row into the page up front. ->relationship()
            // + ->searchable() alone already gives async, server-side search.
            Select::make('airports')
                ->label('Airports covered')
                ->relationship('airports', 'icao_code')
                ->getOptionLabelFromRecordUsing(fn (Airport $airport): string => $airport->displayLabel())
                ->multiple()
                ->searchable(),

            Textarea::make('notes')
                ->rows(3)
                ->helperText('Previous operational problems, anything procurement should know before using this supplier again.'),

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
                TextColumn::make('name')->searchable(),
                TextColumn::make('currency')->placeholder('—'),
                TextColumn::make('payment_terms')->placeholder('—'),
                TextColumn::make('airports_count')->label('Airports')->counts('airports'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name')
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            DocumentsRelationManager::class,
            CommunicationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'view' => Pages\ViewSupplier::route('/{record}'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
