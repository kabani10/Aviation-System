<?php

namespace App\Domain\Aircraft\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Documents\Concerns\HasDocuments;
use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'registration', 'aircraft_type', 'mtow_kg', 'is_active', 'notes'])]
class Aircraft extends Model
{
    use BelongsToCompany, HasDocuments, HasFactory;

    protected function casts(): array
    {
        return [
            'mtow_kg' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function displayLabel(): string
    {
        return "{$this->registration} ({$this->aircraft_type})";
    }
}
