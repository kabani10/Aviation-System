<?php

namespace Database\Factories\Domain\Customers\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerContact>
 */
class CustomerContactFactory extends Factory
{
    protected $model = CustomerContact::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'title' => fake()->jobTitle(),
            'is_primary' => false,
        ];
    }

    /**
     * company_id isn't derived from customer_id automatically (BelongsToCompany
     * only fills it from CurrentCompany, which a plain factory call in a test
     * won't have set) — outside a real request, the caller has to say which
     * company this belongs to, same as CustomerFactory/AircraftFactory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (CustomerContact $contact) {
            $contact->company_id ??= $contact->customer?->company_id;
        });
    }
}
