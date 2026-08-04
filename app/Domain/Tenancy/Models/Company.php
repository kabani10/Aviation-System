<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Communications\Concerns\HasCommunications;
use App\Domain\Customers\Models\Customer;
use App\Domain\Documents\Concerns\HasDocuments;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The tenant. Every other business record ultimately belongs to one Company
 * — see App\Domain\Shared\Concerns\BelongsToCompany. Also doubles as the
 * fallback subject for company-level documents/communications (an inbound
 * email that hasn't been matched to a flight yet, a business license) until
 * a more specific module (Customer, Flight, ...) exists to hold them.
 */
#[Fillable(['name', 'slug', 'billing_email', 'payment_terms'])]
class Company extends Model
{
    use HasCommunications, HasDocuments, HasFactory, LogsActivity;

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

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** See Document::subjectLabel() / Communication::subjectLabel(). */
    public function displayLabel(): string
    {
        return 'Company profile';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'billing_email', 'payment_terms', 'suspended_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
