<?php

namespace App\Filament\Resources\Communications\CommunicationResource\Pages;

use App\Filament\Resources\Communications\CommunicationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCommunications extends ListRecords
{
    protected static string $resource = CommunicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
