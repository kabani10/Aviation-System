<?php

namespace App\Filament\Resources\Customers\CustomerResource\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ViewRecord;

/** See FlightRequestResource's ViewFlightRequest for why this exists — same view-vs-manage gap. */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;
}
