<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers;

use App\Domain\FlightRequests\Models\FlightLeg;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Actions\CreateQuotationFromServices;
use App\Domain\Quotations\Actions\RecordQuotationResponse;
use App\Domain\Quotations\Actions\SendQuotation;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Services\Enums\ServiceStatus;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * This flight's quotation history — where Generate/Send/Record-response
 * actually happen (see QuotationResource for the read-only company-wide
 * pipeline view of the same records). Multiple quotations per flight are
 * expected, not an edge case: a rejected quote gets superseded by a fresh
 * one after re-pricing, and both stay visible here.
 *
 * "Generate" can scope to one leg instead of the whole flight (Phase 18) —
 * the leg picker's options are narrowed to legs that actually have
 * priceable services (same "options list is a convenience, not the
 * boundary" pattern as ServicesRelationManager's own flight_leg_id field —
 * a server-side rule still rejects a leg that doesn't belong to this
 * flight, even though the picker never offers one).
 */
class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $recordTitleAttribute = 'id';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scope')
                    ->label('Scope')
                    ->state(fn (Quotation $record): string => $record->flightLeg?->displayLabel() ?? 'Whole flight'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (QuotationStatus $state): string => $state->label())
                    ->color(fn (QuotationStatus $state): string => $state->color()),
                TextColumn::make('total_selling_price')
                    ->label('Total price')
                    ->state(fn (Quotation $record): float => $record->totalSellingPrice())
                    ->money('USD')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_prices')),
                TextColumn::make('total_cost')
                    ->label('Total cost')
                    ->state(fn (Quotation $record): ?float => $record->totalCost())
                    ->money('USD')
                    ->placeholder('—')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_costs')),
                TextColumn::make('valid_until')->dateTime()->placeholder('—'),
                TextColumn::make('sent_at')->dateTime()->placeholder('—'),
                TextColumn::make('responded_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('generate')
                    ->label('Generate quotation')
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn (): bool => Auth::user()->can('quotations.manage') && $this->hasPriceableServices())
                    ->form([
                        Select::make('flight_leg_id')
                            ->label('Scope')
                            ->native(false)
                            ->placeholder('Whole flight')
                            ->helperText('Leave blank to include every priced service on the flight, or pick one leg to quote just that leg.')
                            ->options(fn (): array => $this->legsWithPriceableServices()
                                ->mapWithKeys(fn (FlightLeg $leg): array => [$leg->id => $leg->displayLabel()])
                                ->all())
                            // The options list above is a UI convenience, not
                            // the actual boundary — same principle as
                            // ServicesRelationManager's own flight_leg_id field.
                            ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                                /** @var FlightRequest $flightRequest */
                                $flightRequest = $this->getOwnerRecord();

                                if ($value && ! $flightRequest->legs()->where('id', $value)->exists()) {
                                    $fail('This leg does not belong to this flight.');
                                }
                            }),
                        Textarea::make('notes')->rows(2),
                        DateTimePicker::make('valid_until')->native(false)->helperText('Leave blank if this quote does not expire.'),
                    ])
                    ->action(function (array $data): void {
                        /** @var FlightRequest $flightRequest */
                        $flightRequest = $this->getOwnerRecord();

                        app(CreateQuotationFromServices::class)(
                            $flightRequest,
                            Auth::user(),
                            $data['notes'] ?: null,
                            $data['valid_until'] ? Carbon::parse($data['valid_until']) : null,
                            $data['flight_leg_id'] ? FlightLeg::query()->find($data['flight_leg_id']) : null,
                        );
                    })
                    ->successNotificationTitle('Quotation generated'),
            ])
            ->actions([
                Action::make('send')
                    ->label('Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (Quotation $record): bool => Auth::user()->can('quotations.manage') && $record->status === QuotationStatus::Draft)
                    ->requiresConfirmation()
                    ->modalDescription('Emails the quotation to the customer\'s billing address and moves the flight to Quotation Sent.')
                    ->action(function (Quotation $record): void {
                        try {
                            app(SendQuotation::class)($record);
                        } catch (RuntimeException $exception) {
                            Notification::make()->title('Could not send quotation')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Quotation sent')->success()->send();
                    }),

                Action::make('markAccepted')
                    ->label('Mark accepted')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Quotation $record): bool => Auth::user()->can('quotations.manage') && $record->status === QuotationStatus::Sent)
                    ->form([Textarea::make('notes')->label('Notes (optional)')->rows(2)])
                    ->action(fn (Quotation $record, array $data) => app(RecordQuotationResponse::class)($record, QuotationStatus::Accepted, $data['notes'] ?: null))
                    ->successNotificationTitle('Quotation marked accepted — flight confirmed'),

                Action::make('markRejected')
                    ->label('Mark rejected')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Quotation $record): bool => Auth::user()->can('quotations.manage') && $record->status === QuotationStatus::Sent)
                    ->form([Textarea::make('notes')->label('Notes (optional)')->rows(2)])
                    ->action(fn (Quotation $record, array $data) => app(RecordQuotationResponse::class)($record, QuotationStatus::Rejected, $data['notes'] ?: null))
                    ->successNotificationTitle('Quotation marked rejected'),
            ]);
    }

    private function hasPriceableServices(): bool
    {
        /** @var FlightRequest $flightRequest */
        $flightRequest = $this->getOwnerRecord();

        return $flightRequest->services()
            ->where('status', '!=', ServiceStatus::Cancelled)
            ->whereNotNull('selling_price')
            ->exists();
    }

    /** @return Collection<int, FlightLeg> only legs with at least one priceable service — no point offering an empty scope. */
    private function legsWithPriceableServices(): Collection
    {
        /** @var FlightRequest $flightRequest */
        $flightRequest = $this->getOwnerRecord();

        return $flightRequest->legs()
            ->whereHas('services', fn ($query) => $query
                ->where('status', '!=', ServiceStatus::Cancelled)
                ->whereNotNull('selling_price'))
            ->get();
    }
}
