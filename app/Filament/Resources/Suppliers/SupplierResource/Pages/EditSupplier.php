<?php

namespace App\Filament\Resources\Suppliers\SupplierResource\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\EditRecord;

/** No delete action — deactivate (is_active) instead, same convention as employees/customers. */
class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;
}
