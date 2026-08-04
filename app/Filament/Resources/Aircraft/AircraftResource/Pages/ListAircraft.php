<?php

namespace App\Filament\Resources\Aircraft\AircraftResource\Pages;

use App\Filament\Resources\Aircraft\AircraftResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAircraft extends ListRecords
{
    protected static string $resource = AircraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
