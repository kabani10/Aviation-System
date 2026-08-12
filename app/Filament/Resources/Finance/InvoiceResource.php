<?php

namespace App\Filament\Resources\Finance;

use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;
use App\Filament\Resources\Finance\InvoiceResource\Pages;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Company-wide "browse everything" pipeline view, same split as
 * QuotationResource: List + View only here, with Generate/Send/Record
 * payment living on InvoicesRelationManager under FlightRequestResource.
 * No line-items sub-table on this resource — an Invoice has none of its
 * own (see Invoice's docblock); the "quotation" entry below links through
 * to the accepted Quotation's own line items instead of duplicating them.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'invoices';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    // Accounting, not Operations — see QuotationResource's note (Phase 21).
    protected static ?string $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 2;

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('invoice_number'),
            TextEntry::make('flight')
                ->label('Flight')
                ->state(fn (Invoice $record): string => $record->flightRequest->displayLabel()),
            TextEntry::make('status')
                ->badge()
                ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                ->color(fn (InvoiceStatus $state): string => $state->color()),
            TextEntry::make('quotation')
                ->label('Generated from')
                ->state(fn (Invoice $record): string => $record->quotation->displayLabel())
                ->url(fn (Invoice $record): string => QuotationResource::getUrl('view', ['record' => $record->quotation])),
            TextEntry::make('due_date')->dateTime()->placeholder('—'),
            TextEntry::make('sent_at')->dateTime()->placeholder('—'),
            TextEntry::make('paid_at')->dateTime()->placeholder('—'),
            TextEntry::make('createdBy.name')->label('Created by')->placeholder('—'),
            TextEntry::make('total_amount')
                ->label('Amount due')
                ->state(fn (Invoice $record): float => $record->totalAmount())
                ->money('USD')
                ->visible(fn (): bool => Auth::user()->can('finance.view_prices')),
            TextEntry::make('profit_margin')
                ->label('Profit margin')
                ->state(fn (Invoice $record): ?float => $record->profitMargin())
                ->money('USD')
                ->placeholder('—')
                ->visible(fn (): bool => Auth::user()->can('finance.view_costs')),
            TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')->searchable(),
                TextColumn::make('flight')
                    ->label('Flight')
                    ->state(fn (Invoice $record): string => $record->flightRequest->displayLabel()),
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
                TextColumn::make('paid_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InvoiceStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])),
            ])
            ->actions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'view' => Pages\ViewInvoice::route('/{record}'),
        ];
    }
}
