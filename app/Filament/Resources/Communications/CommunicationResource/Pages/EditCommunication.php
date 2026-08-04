<?php

namespace App\Filament\Resources\Communications\CommunicationResource\Pages;

use App\Filament\Resources\Communications\CommunicationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCommunication extends EditRecord
{
    protected static string $resource = CommunicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
