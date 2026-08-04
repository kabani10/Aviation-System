<?php

namespace Database\Factories\Domain\Customers\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->company(),
            'billing_email' => fake()->companyEmail(),
            'payment_terms' => fake()->randomElement(['Net 15', 'Net 30', 'Due on receipt']),
            'is_active' => true,
        ];
    }
}
