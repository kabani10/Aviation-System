<?php

namespace App\Domain\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The tenant. Every other business record ultimately belongs to one Company
 * — see App\Domain\Shared\Concerns\BelongsToCompany.
 */
#[Fillable(['name', 'slug', 'billing_email', 'payment_terms'])]
class Company extends Model
{
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'billing_email', 'payment_terms', 'suspended_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
