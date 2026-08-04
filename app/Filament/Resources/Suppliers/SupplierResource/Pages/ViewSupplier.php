<?php

namespace App\Filament\Resources\Suppliers\SupplierResource\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\ViewRecord;

/** See FlightRequestResource's ViewFlightRequest for why this exists — same view-vs-manage gap. */
class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;
}
