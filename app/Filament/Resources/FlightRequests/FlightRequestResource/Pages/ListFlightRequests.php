<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Pages;

use App\Filament\Resources\FlightRequests\FlightRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFlightRequests extends ListRecords
{
    protected static string $resource = FlightRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
