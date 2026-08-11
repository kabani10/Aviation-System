<?php

namespace Database\Factories\Domain\Services\Models;

use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Services\Models\SupplierInquiry;
use App\Domain\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInquiry>
 */
class SupplierInquiryFactory extends Factory
{
    protected $model = SupplierInquiry::class;

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'supplier_id' => Supplier::factory(),
            'status' => SupplierInquiryStatus::Sent,
            'requested_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SupplierInquiry $inquiry) {
            $inquiry->company_id ??= $inquiry->service?->company_id;
        });
    }
}
