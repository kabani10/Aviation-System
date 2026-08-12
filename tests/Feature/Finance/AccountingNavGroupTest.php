<?php

use App\Filament\Resources\Finance\InvoiceResource;
use App\Filament\Resources\Quotations\QuotationResource;

it('groups Quotations and Invoices under a shared Accounting nav section, Quotations first', function () {
    expect(QuotationResource::getNavigationGroup())->toBe('Accounting');
    expect(InvoiceResource::getNavigationGroup())->toBe('Accounting');
    expect(QuotationResource::getNavigationSort())->toBeLessThan(InvoiceResource::getNavigationSort());
});
