<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;

use App\Filament\Resources\FlightRequests\FlightRequestResource;
use Filament\Resources\Pages\EditRecord;

/** No delete action — set status to Cancelled instead, same "no hard delete" convention as everywhere else. */
class EditFlightRequest extends EditRecord
{
    protected static string $resource = FlightRequestResource::class;
}
