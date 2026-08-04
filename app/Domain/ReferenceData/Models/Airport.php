<?php

namespace App\Domain\ReferenceData\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** See Country — same "shared, seeder-managed, no tenant panel CRUD" reasoning. */
#[Fillable(['icao_code', 'iata_code', 'name', 'city'])]
class Airport extends Model
{
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function displayLabel(): string
    {
        return "{$this->icao_code} — {$this->name}";
    }
}
