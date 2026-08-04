<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers;

use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Every service on this flight — ground handling, fuel, permits. Cost and
 * selling price are gated on finance.view_costs / finance.view_prices, not
 * just services.manage: "Sales may see selling prices but not necessarily
 * all supplier costs" from the original spec is a field-level distinction,
 * not a screen-level one, so both the form fields and table columns check
 * the finer permission. Hidden fields are also not dehydrated — a hidden
 * field that still submits null would silently wipe an existing cost/price
 * when a non-Finance user saves other changes to the service.
 */
class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    protected static ?string $recordTitleAttribute = 'type';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('type')
                ->options(collect(ServiceType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(fn (callable $set) => $set('supplier_id', null)),

            Select::make('status')
                ->options(collect(ServiceStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                ->required()
                ->native(false)
                ->default(ServiceStatus::NotStarted->value),

            Select::make('responsible_user_id')
                ->label('Responsible employee')
                ->relationship('responsibleUser', 'name')
                ->searchable()
                ->preload(),

            Select::make('supplier_id')
                ->label('Supplier')
                ->options(fn (Get $get): array => Supplier::query()
                    ->when(
                        $get('type'),
                        fn ($query, $type) => $query->whereJsonContains('services_offered', $type),
                    )
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->helperText('Filtered to suppliers who list this service, once a type is chosen — not enforced, just a shortlist.'),

            TextInput::make('cost')
                ->numeric()
                ->prefix('$')
                ->visible(fn (): bool => Auth::user()->can('finance.view_costs'))
                ->dehydrated(fn (): bool => Auth::user()->can('finance.view_costs')),

            TextInput::make('selling_price')
                ->numeric()
                ->prefix('$')
                ->visible(fn (): bool => Auth::user()->can('finance.view_prices'))
                ->dehydrated(fn (): bool => Auth::user()->can('finance.view_prices')),

            DateTimePicker::make('supplier_confirmed_at')
                ->label('Supplier confirmed at')
                ->native(false),

            DateTimePicker::make('deadline')
                ->native(false),

            Textarea::make('notes')
                ->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->formatStateUsing(fn (ServiceType $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ServiceStatus $state): string => $state->label())
                    ->color(fn (ServiceStatus $state): string => $state->color()),
                TextColumn::make('responsibleUser.name')->label('Responsible')->placeholder('—'),
                TextColumn::make('supplier.name')->placeholder('—'),
                TextColumn::make('deadline')
                    ->dateTime()
                    ->placeholder('—')
                    ->color(fn ($record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('cost')
                    ->money('USD')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_costs')),
                TextColumn::make('selling_price')
                    ->money('USD')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_prices')),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }
}
