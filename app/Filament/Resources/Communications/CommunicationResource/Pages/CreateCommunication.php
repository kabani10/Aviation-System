<?php

namespace App\Filament\Resources\Communications\CommunicationResource\Pages;

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Communications\Models\Communication;
use App\Filament\Resources\Communications\CommunicationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class CreateCommunication extends CreateRecord
{
    protected static string $resource = CommunicationResource::class;

    protected function handleRecordCreation(array $data): Communication
    {
        $user = Auth::user();

        return app(LogCommunication::class)(
            communicable: $user->company,
            type: CommunicationType::from($data['type']),
            body: $data['body'],
            subject: $data['subject'] ?? null,
            fromAddress: $data['from_address'] ?? null,
            toAddress: $data['to_address'] ?? null,
            occurredAt: Carbon::parse($data['occurred_at']),
            author: $user,
        );
    }
}
