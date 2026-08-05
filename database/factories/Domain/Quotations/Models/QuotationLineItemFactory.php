<?php

namespace Database\Factories\Domain\Quotations\Models;

use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationLineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationLineItem>
 */
class QuotationLineItemFactory extends Factory
{
    protected $model = QuotationLineItem::class;

    public function definition(): array
    {
        return [
            'quotation_id' => Quotation::factory(),
            'description' => fake()->randomElement(['Ground handling', 'Fuel', 'Landing permit']),
            'cost' => fake()->randomFloat(2, 100, 3000),
            'selling_price' => fake()->randomFloat(2, 150, 4000),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (QuotationLineItem $lineItem) {
            $lineItem->company_id ??= $lineItem->quotation?->company_id;
        });
    }
}
