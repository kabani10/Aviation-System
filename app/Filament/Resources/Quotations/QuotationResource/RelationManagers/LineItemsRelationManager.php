<?php

namespace App\Filament\Resources\Quotations\QuotationResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/** Read-only — line items are a snapshot (see Quotation's docblock), never hand-edited, so there are deliberately no create/edit/delete actions here. */
class LineItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'lineItems';

    protected static ?string $recordTitleAttribute = 'description';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description'),
                TextColumn::make('cost')
                    ->money('USD')
                    ->placeholder('—')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_costs')),
                TextColumn::make('selling_price')
                    ->money('USD')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_prices')),
            ])
            ->headerActions([])
            ->actions([]);
    }
}
