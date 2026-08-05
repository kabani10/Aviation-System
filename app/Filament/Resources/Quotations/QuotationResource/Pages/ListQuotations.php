<?php

namespace App\Filament\Resources\Quotations\QuotationResource\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Resources\Pages\ListRecords;

/** No create page — a Quotation is only ever generated from a FlightRequest (see QuotationsRelationManager), so the default "New" header action has nothing to point at. */
class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
