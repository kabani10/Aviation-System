<?php

namespace Database\Factories\Domain\Suppliers\Models;

use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierContact>
 */
class SupplierContactFactory extends Factory
{
    protected $model = SupplierContact::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'title' => fake()->jobTitle(),
            'is_primary' => false,
        ];
    }

    /** See CustomerContactFactory — same reasoning. */
    public function configure(): static
    {
        return $this->afterMaking(function (SupplierContact $contact) {
            $contact->company_id ??= $contact->supplier?->company_id;
        });
    }
}
