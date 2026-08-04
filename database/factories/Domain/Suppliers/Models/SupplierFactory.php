<?php

namespace Database\Factories\Domain\Suppliers\Models;

use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->company().' '.fake()->randomElement(['Handling', 'Fuel', 'Aviation Services']),
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP', 'AED']),
            'payment_terms' => fake()->randomElement(['Net 15', 'Net 30', 'Prepaid']),
            'services_offered' => fake()->randomElements(
                array_map(fn (ServiceType $case) => $case->value, ServiceType::cases()),
                fake()->numberBetween(1, 3),
            ),
            'is_active' => true,
        ];
    }
}
