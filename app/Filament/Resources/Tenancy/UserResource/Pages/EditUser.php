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
        $previousRole = $record->roles->first()?->name;
        $newRole = $data['role'];
        unset($data['role']);

        if ($newRole !== $previousRole) {
            $record->syncRoles([$newRole]);

            activity()
                ->causedBy(auth()->user())
                ->performedOn($record)
                ->withProperties(['from' => $previousRole, 'to' => $newRole])
                ->log('role_changed');
        }

        $record->fill($data);
        $record->save();

        return $record;
    }
}
