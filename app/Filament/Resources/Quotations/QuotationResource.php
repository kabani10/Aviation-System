<?php

namespace App\Filament\Resources\Quotations;

use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Filament\Resources\Quotations\QuotationResource\Pages;
use App\Filament\Resources\Quotations\QuotationResource\RelationManagers\LineItemsRelationManager;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Company-wide "browse everything" view, same reasoning as
 * Document/CommunicationResource — Sales and Finance both hold
 * quotations.view and want a pipeline view across every flight, not just
 * one at a time. List + View only: a Quotation is never hand-edited, it's
 * only ever generated, sent, or responded to — see
 * QuotationsRelationManager on FlightRequestResource, where those actions
 * actually live.
 */
class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    // Same slug-collision defense as every other resource — see
    // DocumentResource for the original note.
    protected static ?string $slug = 'quotations';

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    // Accounting, not Operations — grouped with InvoiceResource (Phase 21)
    // now that a flight reaching Completed actually produces one of each
    // for Finance/Management to work from, not just Flight Requests' own
    // "Operations" territory. Sort ahead of Invoices: a quotation exists
    // before the invoice generated from it ever can.
    protected static ?string $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 1;

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('flight')
                ->label('Flight')
                ->state(fn (Quotation $record): string => $record->flightRequest->displayLabel()),
            TextEntry::make('status')
                ->badge()
                ->formatStateUsing(fn (QuotationStatus $state): string => $state->label())
                ->color(fn (QuotationStatus $state): string => $state->color()),
            TextEntry::make('valid_until')->dateTime()->placeholder('—'),
            TextEntry::make('sent_at')->dateTime()->placeholder('—'),
            TextEntry::make('responded_at')->dateTime()->placeholder('—'),
            TextEntry::make('createdBy.name')->label('Created by')->placeholder('—'),
            TextEntry::make('total_cost')
                ->label('Total cost')
                ->state(fn (Quotation $record): ?float => $record->totalCost())
                ->money('USD')
                ->placeholder('—')
                ->visible(fn (): bool => Auth::user()->can('finance.view_costs')),
            TextEntry::make('total_selling_price')
                ->label('Total price')
                ->state(fn (Quotation $record): float => $record->totalSellingPrice())
                ->money('USD')
                ->visible(fn (): bool => Auth::user()->can('finance.view_prices')),
            TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('flight')
                    ->label('Flight')
                    ->state(fn (Quotation $record): string => $record->flightRequest->displayLabel()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (QuotationStatus $state): string => $state->label())
                    ->color(fn (QuotationStatus $state): string => $state->color()),
                TextColumn::make('total_selling_price')
                    ->label('Total price')
                    ->state(fn (Quotation $record): float => $record->totalSellingPrice())
                    ->money('USD')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_prices')),
                TextColumn::make('sent_at')->dateTime()->placeholder('—'),
                TextColumn::make('responded_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(QuotationStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])),
            ])
            ->actions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LineItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotations::route('/'),
            'view' => Pages\ViewQuotation::route('/{record}'),
        ];
    }
}
