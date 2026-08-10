<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers;

use App\AI\SupplierRecommendation\Recommenders\SupplierRecommender;
use App\AI\Support\ClaudeApiException;
use App\Domain\FlightRequests\Models\FlightLeg;
use App\Domain\Services\Actions\RecordSupplierQuote;
use App\Domain\Services\Actions\SendSupplierRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
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
 *
 * "Request quote" / "Record quote" (Phase 8) operationalize the
 * SupplierRequestSent/QuotationReceived statuses that already existed on
 * ServiceStatus since Phase 6 but had no action behind them. "Record quote"
 * additionally requires finance.view_costs, same reasoning as the cost
 * field above — the whole point of the action is entering one. "Suggest
 * supplier" requires it too, for a subtler reason: the AI's rationale text
 * is free-form and can end up stating a supplier's average cost outright
 * (it's part of what SupplierRecommender gives Claude to reason over), so
 * showing it to someone without cost visibility would leak through the
 * back door what the form field correctly hides.
 */
class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    protected static ?string $recordTitleAttribute = 'type';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('flight_leg_id')
                ->label('Leg')
                ->options(fn (): array => $this->getOwnerRecord()->legs
                    ->mapWithKeys(fn (FlightLeg $leg): array => [$leg->id => $leg->displayLabel()])
                    ->all())
                ->default(fn (): ?int => $this->getOwnerRecord()->legs->count() === 1 ? $this->getOwnerRecord()->legs->first()->id : null)
                ->required()
                ->native(false)
                // The options list above is a UI convenience, not the
                // actual boundary — same principle as the aircraft_id
                // check on FlightRequestResource.
                ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                    if ($value && ! $this->getOwnerRecord()->legs()->where('id', $value)->exists()) {
                        $fail('This leg does not belong to this flight.');
                    }
                }),

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
                TextColumn::make('flightLeg.sequence')
                    ->label('Leg')
                    ->formatStateUsing(fn (Service $record): string => $record->flightLeg
                        ? "#{$record->flightLeg->sequence}: {$record->flightLeg->originAirport->icao_code}\u{2192}{$record->flightLeg->destinationAirport->icao_code}"
                        : '—'),
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
                TextColumn::make('quote_requested_at')->label('Requested')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quote_received_at')->label('Received')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost')
                    ->money('USD')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_costs')),
                TextColumn::make('selling_price')
                    ->money('USD')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_prices')),
            ])
            ->filters([
                SelectFilter::make('flight_leg_id')
                    ->label('Leg')
                    ->options(fn (): array => $this->getOwnerRecord()->legs
                        ->mapWithKeys(fn (FlightLeg $leg): array => [$leg->id => $leg->displayLabel()])
                        ->all()),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),

                Action::make('requestQuote')
                    ->label('Request quote')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (Service $record): bool => Auth::user()->can('services.manage') && $record->supplier_id !== null)
                    ->form([
                        Select::make('supplier_contact_id')
                            ->label('Send to')
                            ->options(fn (Service $record): array => $record->supplier?->contacts()->pluck('name', 'id')->all() ?? [])
                            ->required()
                            ->native(false),
                        Textarea::make('message')
                            ->label('Additional message (optional)')
                            ->rows(3),
                    ])
                    ->action(function (Service $record, array $data): void {
                        $contact = SupplierContact::query()->findOrFail($data['supplier_contact_id']);

                        app(SendSupplierRequest::class)($record, $contact, $data['message'] ?: null, Auth::user());
                    })
                    ->successNotificationTitle('Quote request sent'),

                Action::make('recordQuote')
                    ->label('Record quote')
                    ->icon('heroicon-o-currency-dollar')
                    ->visible(fn (): bool => Auth::user()->can('services.manage') && Auth::user()->can('finance.view_costs'))
                    ->form([
                        TextInput::make('cost')
                            ->numeric()
                            ->prefix('$')
                            ->required(),
                        Textarea::make('notes')
                            ->rows(2),
                    ])
                    ->action(function (Service $record, array $data): void {
                        app(RecordSupplierQuote::class)($record, (float) $data['cost'], $data['notes'] ?: null, Auth::user());
                    })
                    ->successNotificationTitle('Quote recorded'),

                Action::make('suggestSupplier')
                    ->label('Suggest supplier')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (): bool => Auth::user()->can('services.manage') && Auth::user()->can('finance.view_costs'))
                    ->modalHeading('Suggested suppliers')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (Service $record): View {
                        try {
                            $recommendations = app(SupplierRecommender::class)($record);
                            $error = null;
                        } catch (ClaudeApiException $exception) {
                            $recommendations = collect();
                            $error = 'Could not get AI suggestions right now: '.$exception->getMessage();
                        }

                        return view('filament.flight-requests.supplier-suggestions', [
                            'recommendations' => $recommendations,
                            'supplierNames' => Supplier::query()->pluck('name', 'id'),
                            'error' => $error,
                        ]);
                    }),
            ]);
    }
}
