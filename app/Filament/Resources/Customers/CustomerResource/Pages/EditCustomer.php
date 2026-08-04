<?php

namespace App\Filament\Resources\Customers\CustomerResource\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\EditRecord;

/** No delete action — deactivate (is_active) instead, same convention as employees. */
class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;
}
