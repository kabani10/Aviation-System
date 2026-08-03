<?php

namespace App\Filament\Resources\Tenancy\UserResource\Pages;

use App\Domain\Tenancy\Actions\InviteEmployee;
use App\Filament\Resources\Tenancy\UserResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Routed through InviteEmployee rather than a plain model create — new
     * employees are invited (set-password email), never assigned a password
     * by the person adding them.
     */
    protected function handleRecordCreation(array $data): User
    {
        return app(InviteEmployee::class)(
            company: auth()->user()->company,
            name: $data['name'],
            email: $data['email'],
            role: $data['role'],
        );
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Invitation sent')
            ->body('They\'ll receive an email to set their password.');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
