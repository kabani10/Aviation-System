<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;

use App\Filament\Resources\FlightRequests\FlightRequestResource;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns\HasFlightRequestReviewActions;
use Filament\Resources\Pages\EditRecord;

/** No delete action — set status to Cancelled instead, same "no hard delete" convention as everywhere else. */
class EditFlightRequest extends EditRecord
{
    use HasFlightRequestReviewActions;

    protected static string $resource = FlightRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            ...$this->flightRequestReviewHeaderActions(),
        ];
    }
}
