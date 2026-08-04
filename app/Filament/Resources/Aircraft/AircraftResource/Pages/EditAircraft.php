<?php

namespace App\Filament\Resources\Aircraft\AircraftResource\Pages;

use App\Filament\Resources\Aircraft\AircraftResource;
use Filament\Resources\Pages\EditRecord;

/** No delete action — deactivate (is_active) instead, same convention as employees. */
class EditAircraft extends EditRecord
{
    protected static string $resource = AircraftResource::class;
}
