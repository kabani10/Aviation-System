<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers;

use App\AI\SupplierRecommendation\DataTransferObjects\SupplierRecommendation;
use App\AI\SupplierRecommendation\Recommenders\SupplierRecommender;
use App\AI\Support\ClaudeApiException;
use App\Domain\FlightRequests\Models\FlightLeg;
use App\Domain\Services\Actions\SendSupplierInquiry;
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
use Filament\Forms\Components\View as ViewComponent;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

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
 * The actual "ask suppliers for a quote, compare replies, pick one" workflow
 * (the spec's Phase 8, reworked in Phase 15 to support several candidate
 * suppliers at once) mostly lives on the separate SupplierInquiriesRelationManager
 * tab — recording a response and choosing a winner happen there, once an
 * inquiry exists. Starting one is still here, as "Send RFQ" below: it's a
 * per-service action, and only a Service row (not the flight-wide inquiries
 * tab) has a single service in scope to filter AI suggestions by without
 * more reactive form wiring than this is worth. `supplier_id`/`cost` stay
 * directly editable on this form too, as a manual override that skips the
 * RFQ comparison entirely (an operator who already knows the answer
 * shouldn't have to create and choose an inquiry just to record it).
 *
 * "Send RFQ" shows the same AI-ranked supplier suggestions "Suggest
 * supplier" used to (Phase 8), but only when finance.view_costs — the AI's
 * rationale is free-form text that can end up stating a supplier's average
 * cost outright (SupplierRecommender gives Claude that metric to reason
 * over), so showing it to someone without cost visibility would leak
 * through the back door what the cost field correctly hides. Operations
 * (services.manage but not finance.view_costs) still gets a plain supplier
 * picker with no AI block and no wasted Claude API call — the
 * recommendations are only computed when they'll actually be shown.
 *
 * Phase 14 grouped the table by leg by default (see table()) — the flat,
 * ungrouped table plus a Leg filter a reviewer had to reach for was the gap
 * flagged against the workflow this resource implements: "for each leg,
 * multiple services, the user should be able to go to the service of a leg."
 */
class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    protected static ?string $recordTitleAttribute = 'type';

    /**
     * Same cross-request caching reasoning as Phase 8's original
     * "Suggest supplier" — Filament rebuilds the ->form() schema on submit
     * too, so without this a "Send RFQ" open+save is two real Claude calls
     * for one interaction, each a multi-second external call inside what
     * should be an instant save. Cached as a plain array, not the
     * SupplierRecommendation DTOs directly, since a cached readonly object
     * can come back __PHP_Incomplete_Class through a real Redis
     * serialize/unserialize round-trip.
     *
     * @return array{recommendations: Collection<int, SupplierRecommendation>, error: ?string}
     */
    private function supplierRecommendationsFor(Service $service): array
    {
        $cacheKey = "supplier-recommendations:{$service->id}";

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return [
                'recommendations' => collect($cached['recommendations'])
                    ->map(fn (array $entry): SupplierRecommendation => new SupplierRecommendation(
                        supplierId: $entry['supplierId'],
                        rationale: $entry['rationale'],
                    )),
                'error' => null,
            ];
        }

        try {
            $recommendations = app(SupplierRecommender::class)($service);
        } catch (ClaudeApiException $exception) {
            return ['recommendations' => collect(), 'error' => 'Could not get AI suggestions right now: '.$exception->getMessage()];
        }

        Cache::put($cacheKey, [
            'recommendations' => $recommendations
                ->map(fn (SupplierRecommendation $recommendation): array => [
                    'supplierId' => $recommendation->supplierId,
                    'rationale' => $recommendation->rationale,
                ])
                ->all(),
        ], now()->addMinutes(5));

        return ['recommendations' => $recommendations, 'error' => null];
    }

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
                ->helperText('The chosen supplier — normally set by picking a winning inquiry on the Supplier Inquiries tab, but editable here directly too.'),

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['flightLeg.originAirport', 'flightLeg.destinationAirport']))
            // Grouped by leg by default, not just filterable by it — "which
            // leg does this belong to" is the first thing an operator on a
            // multi-leg flight needs, not something to discover by scanning
            // a flat table or reaching for the filter first. Ordering groups
            // by the raw flight_leg_id (the default — no orderQueryUsing)
            // still comes out leg-sequence order in practice: every creation
            // path (CreateFlightRequestFromExtraction, CreateFlightRequest,
            // LegsRelationManager) always creates legs in sequence order, so
            // ascending id already matches ascending sequence. The "Leg"
            // column stays too, for whenever an operator switches the
            // "Group by" control back to "None".
            ->groups([
                Group::make('flight_leg_id')
                    ->label('Leg')
                    ->getTitleFromRecordUsing(fn (Service $record): string => $record->flightLeg?->displayLabel() ?? 'No leg'),
            ])
            ->defaultGroup('flight_leg_id')
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

                Action::make('sendInquiry')
                    ->label('Send RFQ')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (): bool => Auth::user()->can('services.manage'))
                    // No supplier_id precondition anymore — see the class
                    // docblock. Calling this again for the same service (a
                    // second candidate supplier) is the normal multi-RFQ
                    // case, not an edge case.
                    ->form(function (Service $record): array {
                        $canSeeAiSuggestions = Auth::user()->can('finance.view_costs');
                        $result = $canSeeAiSuggestions ? $this->supplierRecommendationsFor($record) : null;

                        return [
                            ...($canSeeAiSuggestions ? [
                                ViewComponent::make('filament.flight-requests.supplier-suggestions')
                                    ->viewData([
                                        'recommendations' => $result['recommendations'],
                                        'supplierNames' => Supplier::query()->pluck('name', 'id'),
                                        'error' => $result['error'],
                                    ]),
                            ] : []),

                            Select::make('supplier_id')
                                ->label('Supplier')
                                ->searchable()
                                ->native(false)
                                ->live()
                                ->options(fn (): array => Supplier::query()
                                    ->where('is_active', true)
                                    ->whereJsonContains('services_offered', $record->type->value)
                                    ->pluck('name', 'id')
                                    ->all())
                                ->default(fn (): ?int => $canSeeAiSuggestions ? $result['recommendations']->first()?->supplierId : null)
                                ->afterStateUpdated(fn (callable $set) => $set('supplier_contact_id', null))
                                ->required(),

                            Select::make('supplier_contact_id')
                                ->label('Send to')
                                ->options(fn (Get $get): array => $get('supplier_id')
                                    ? Supplier::query()->find($get('supplier_id'))?->contacts()->pluck('name', 'id')->all() ?? []
                                    : [])
                                ->required()
                                ->native(false),

                            Textarea::make('message')
                                ->label('Additional message (optional)')
                                ->rows(3),
                        ];
                    })
                    ->action(function (Service $record, array $data): void {
                        $supplier = Supplier::query()->findOrFail($data['supplier_id']);
                        $contact = SupplierContact::query()->findOrFail($data['supplier_contact_id']);

                        app(SendSupplierInquiry::class)($record, $supplier, $contact, $data['message'] ?: null, Auth::user());
                    })
                    ->successNotificationTitle('Quote request sent'),
            ]);
    }
}
