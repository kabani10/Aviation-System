<?php

namespace App\Filament\Resources\FlightRequests;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\ReferenceData\Models\Airport;
use App\Filament\RelationManagers\CommunicationsRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\LegsRelationManager;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\QuotationsRelationManager;
use App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers\ServicesRelationManager;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The central record — see ARCHITECTURE.md "Flight Requests". Everyone
 * working an operation opens this one page: customer, aircraft, route,
 * times, assigned staff, and (via the shared RelationManagers) its
 * document library and communication timeline.
 */
class FlightRequestResource extends Resource
{
    protected static ?string $model = FlightRequest::class;

    // Defensive against the "folder name kebabs to the same slug as the
    // pluralized model name" collision — see DocumentResource et al.
    protected static ?string $slug = 'flight-requests';

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Operations';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('customer_id')
                ->label('Customer')
                ->relationship('customer', 'name')
                ->required()
                ->live()
                ->searchable()
                ->preload()
                // Changing customer invalidates whatever aircraft was picked
                // for the previous one.
                ->afterStateUpdated(fn (callable $set) => $set('aircraft_id', null)),

            Select::make('aircraft_id')
                ->label('Aircraft')
                ->options(fn (Get $get): array => Aircraft::query()
                    ->where('customer_id', $get('customer_id'))
                    ->get()
                    ->mapWithKeys(fn (Aircraft $aircraft): array => [$aircraft->id => $aircraft->displayLabel()])
                    ->all())
                ->required()
                ->searchable()
                ->disabled(fn (Get $get): bool => ! $get('customer_id'))
                ->helperText('Choose a customer first — only their own fleet is offered here.')
                // The filtered options list above is a UI convenience, not
                // the actual boundary — a submitted aircraft_id that
                // doesn't belong to customer_id must still be rejected
                // server-side, same principle as the DocumentDownloadController
                // check in ARCHITECTURE.md's multi-tenancy section.
                ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                    if ($value && ! Aircraft::query()->where('id', $value)->where('customer_id', $get('customer_id'))->exists()) {
                        $fail('This aircraft does not belong to the selected customer.');
                    }
                }),

            TextInput::make('callsign')
                ->maxLength(255),

            // Route/timing aren't columns on FlightRequest anymore — they
            // belong to its first FlightLeg (see CreateFlightRequest, which
            // splits this data on save). Shown only while creating, as a
            // convenience for the common one-leg case; editing an existing
            // flight's route (or adding a second leg) happens on the Legs
            // tab instead, where "which leg" is unambiguous. Plain ->options()
            // rather than ->relationship() — this field has no matching
            // relation on FlightRequest itself to bind to.
            Select::make('origin_airport_id')
                ->label('Origin')
                ->options(fn (): array => Airport::query()->pluck('icao_code', 'id')->all())
                ->required()
                ->searchable()
                ->preload()
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),

            Select::make('destination_airport_id')
                ->label('Destination')
                ->options(fn (): array => Airport::query()->pluck('icao_code', 'id')->all())
                ->required()
                ->searchable()
                ->preload()
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),

            DateTimePicker::make('departure_at')
                ->required()
                ->native(false)
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),

            DateTimePicker::make('arrival_at')
                ->required()
                ->native(false)
                ->after('departure_at')
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),

            TextInput::make('passenger_count')
                ->label('Passengers')
                ->numeric()
                ->minValue(0),

            TextInput::make('crew_count')
                ->label('Crew')
                ->numeric()
                ->minValue(0),

            Select::make('status')
                ->options(collect(FlightStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                ->required()
                ->native(false)
                ->default(FlightStatus::NewRequest->value),

            Select::make('assignedUsers')
                ->label('Assigned employees')
                ->relationship('assignedUsers', 'name')
                ->multiple()
                ->searchable()
                ->preload(),

            Textarea::make('requested_services_summary')
                ->label('Requested services')
                ->rows(2)
                ->helperText('What the customer actually asked for, in their own words — structured services come later.'),

            Textarea::make('special_instructions')
                ->rows(2)
                ->helperText('Specific to this flight — see the customer record for standing instructions.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // legs_min_departure_at: a flight's own "departure" is its
            // earliest leg's, for sorting — a real aggregated column via
            // withMin, not computed per-row, so it stays sortable.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withMin('legs', 'departure_at')
                ->with(['legs.originAirport', 'legs.destinationAirport']))
            ->columns([
                TextColumn::make('callsign')->searchable()->placeholder('—'),
                TextColumn::make('customer.name')->searchable(),
                TextColumn::make('aircraft.registration')->label('Aircraft'),
                TextColumn::make('route')
                    ->label('Route')
                    ->state(fn (FlightRequest $record): string => $record->routeLabel()),
                TextColumn::make('legs_min_departure_at')->label('Departure')->dateTime()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (FlightStatus $state): string => $state->label())
                    ->color(fn (FlightStatus $state): string => $state->color()),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (FlightRequest $record): string => $record->needsReview() ? 'AI draft — needs review' : $record->source->label())
                    ->color(fn (FlightRequest $record): string => $record->needsReview() ? 'warning' : 'gray'),
                TextColumn::make('assignedUsers.name')->label('Assigned to')->listWithLineBreaks()->limitList(2),
                TextColumn::make('services_count')->label('Services')->counts('services'),
            ])
            ->defaultSort('legs_min_departure_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(FlightStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LegsRelationManager::class,
            ServicesRelationManager::class,
            QuotationsRelationManager::class,
            InvoicesRelationManager::class,
            DocumentsRelationManager::class,
            CommunicationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlightRequests::route('/'),
            'create' => Pages\CreateFlightRequest::route('/create'),
            'view' => Pages\ViewFlightRequest::route('/{record}'),
            'edit' => Pages\EditFlightRequest::route('/{record}/edit'),
        ];
    }
}
