<?php

namespace App\Filament\Resources\Finance\InvoiceResource\Pages;

use App\Filament\Resources\Finance\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

/** No create page — an Invoice is only ever generated from a FlightRequest's accepted Quotation (see InvoicesRelationManager). */
class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
