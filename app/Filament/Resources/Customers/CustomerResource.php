<?php

namespace App\Filament\Resources\Customers;

use App\Domain\Customers\Models\Customer;
use App\Filament\RelationManagers\CommunicationsRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Customers\CustomerResource\Pages;
use App\Filament\Resources\Customers\CustomerResource\RelationManagers\AircraftRelationManager;
use App\Filament\Resources\Customers\CustomerResource\RelationManagers\ContactsRelationManager;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    // Without this, Filament derives "customers/customers" from the
    // Customers/ folder + the pluralized model name colliding — see
    // DocumentResource/CommunicationResource for the same issue.
    protected static ?string $slug = 'customers';

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Records';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('billing_email')
                ->email()
                ->maxLength(255),

            TextInput::make('payment_terms')
                ->maxLength(255)
                ->placeholder('e.g. Net 30'),

            Textarea::make('special_instructions')
                ->rows(3)
                ->helperText('Standing instructions operators should see every time this customer\'s flights come up.'),

            Select::make('preferredSuppliers')
                ->label('Preferred suppliers')
                ->relationship('preferredSuppliers', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Suppliers this customer has asked for by name, or that procurement should default to.'),

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
                TextColumn::make('billing_email')->label('Billing email')->placeholder('—'),
                TextColumn::make('payment_terms')->placeholder('—'),
                TextColumn::make('aircraft_count')->label('Aircraft')->counts('aircraft'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            AircraftRelationManager::class,
            DocumentsRelationManager::class,
            CommunicationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
