<?php

namespace App\Filament\Resources\Tenancy;

use App\Filament\Resources\Tenancy\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Employees';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            // Deliberately not a Filament ->relationship() field: role
            // assignment goes through InviteEmployee / EditUser's own
            // syncRoles() call, not Filament's automatic relationship save,
            // so create and edit behave identically and explicitly.
            Select::make('role')
                ->options(fn () => Role::pluck('name', 'name'))
                ->required()
                ->native(false),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Deactivate instead of deleting when someone leaves the company.')
                // New employees are always active — this only matters on edit.
                ->hidden(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')->label('Role')->badge(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->label('Invited')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
