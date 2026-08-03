<?php

namespace App\Filament\Resources\Tenancy\UserResource\Pages;

use App\Filament\Resources\Tenancy\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * No delete action here on purpose — employees are deactivated (is_active),
 * not removed, so their history (assignments, audit log) stays intact.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = $this->record->roles->first()?->name;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $record->syncRoles([$data['role']]);
        unset($data['role']);

        $record->fill($data);
        $record->save();

        return $record;
    }
}
