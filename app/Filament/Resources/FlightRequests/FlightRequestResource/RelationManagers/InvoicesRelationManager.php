<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers;

use App\Domain\Finance\Actions\CreateInvoiceFromQuotation;
use App\Domain\Finance\Actions\RecordInvoicePayment;
use App\Domain\Finance\Actions\SendInvoice;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Enums\QuotationStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * This flight's invoice history — Generate/Send/Record payment (see
 * InvoiceResource for the read-only company-wide pipeline view). Gated on
 * finance.manage throughout, not quotations.manage: invoicing is Finance's
 * job per the spec, not Sales's, even though the two RelationManagers
 * otherwise mirror each other closely.
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    ->color(fn (InvoiceStatus $state): string => $state->color()),
                TextColumn::make('total_amount')
                    ->label('Amount due')
                    ->state(fn (Invoice $record): float => $record->totalAmount())
                    ->money('USD')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_prices')),
                TextColumn::make('due_date')->dateTime()->placeholder('—')
                    ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('sent_at')->dateTime()->placeholder('—'),
                TextColumn::make('paid_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('generate')
                    ->label('Generate invoice')
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn (): bool => Auth::user()->can('finance.manage') && $this->isInvoiceable())
                    ->form([
                        Textarea::make('notes')->rows(2),
                        DateTimePicker::make('due_date')->native(false)->helperText('Leave blank if this invoice has no fixed due date.'),
                    ])
                    ->action(function (array $data): void {
                        /** @var FlightRequest $flightRequest */
                        $flightRequest = $this->getOwnerRecord();

                        try {
                            app(CreateInvoiceFromQuotation::class)(
                                $flightRequest,
                                Auth::user(),
                                $data['notes'] ?: null,
                                $data['due_date'] ? Carbon::parse($data['due_date']) : null,
                            );
                        } catch (RuntimeException $exception) {
                            Notification::make()->title('Could not generate invoice')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Invoice generated')->success()->send();
                    }),
            ])
            ->actions([
                Action::make('send')
                    ->label('Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (Invoice $record): bool => Auth::user()->can('finance.manage') && $record->status === InvoiceStatus::Draft)
                    ->requiresConfirmation()
                    ->modalDescription("Emails the invoice to the customer's billing address and moves the flight to Invoiced.")
                    ->action(function (Invoice $record): void {
                        try {
                            app(SendInvoice::class)($record);
                        } catch (RuntimeException $exception) {
                            Notification::make()->title('Could not send invoice')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Invoice sent')->success()->send();
                    }),

                Action::make('markPaid')
                    ->label('Record payment')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Invoice $record): bool => Auth::user()->can('finance.manage') && $record->status === InvoiceStatus::Sent)
                    ->requiresConfirmation()
                    ->modalDescription('Marks this invoice paid and closes the flight.')
                    ->form([Textarea::make('notes')->label('Notes (optional)')->rows(2)])
                    ->action(fn (Invoice $record, array $data) => app(RecordInvoicePayment::class)($record, $data['notes'] ?: null))
                    ->successNotificationTitle('Payment recorded — flight closed'),
            ]);
    }

    private function isInvoiceable(): bool
    {
        /** @var FlightRequest $flightRequest */
        $flightRequest = $this->getOwnerRecord();

        return $flightRequest->status === FlightStatus::Completed
            && $flightRequest->quotations()->where('status', QuotationStatus::Accepted)->exists();
    }
}
