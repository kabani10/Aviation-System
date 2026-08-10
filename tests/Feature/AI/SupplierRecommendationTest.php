<?php

use App\AI\SupplierRecommendation\Recommenders\SupplierRecommender;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.anthropic.key' => 'test-anthropic-key']));

function fakeClaudeRecommendation(array $recommendations): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'recommend_suppliers', 'input' => ['recommendations' => $recommendations]],
            ],
            'stop_reason' => 'tool_use',
        ]),
    ]);
}

it('ranks candidate suppliers using the recommendation Claude returns', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $good = Supplier::factory()->for($company)->create(['name' => 'Reliable Fuel Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $risky = Supplier::factory()->for($company)->create(['name' => 'Sketchy Fuel Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null]);

    fakeClaudeRecommendation([
        ['supplier_id' => $good->id, 'rationale' => 'Fast, reliable history.'],
        ['supplier_id' => $risky->id, 'rationale' => 'Slower responses, but available.'],
    ]);

    $recommendations = app(SupplierRecommender::class)($service);

    expect($recommendations)->toHaveCount(2);
    expect($recommendations->first()->supplierId)->toBe($good->id);
    expect($recommendations->last()->supplierId)->toBe($risky->id);
});

it('drops a recommendation for a supplier id Claude hallucinated outside the candidate list', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $realCandidate = Supplier::factory()->for($company)->create(['services_offered' => [ServiceType::Fuel->value]]);
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null]);

    fakeClaudeRecommendation([
        ['supplier_id' => 999999, 'rationale' => 'Made up supplier.'],
        ['supplier_id' => $realCandidate->id, 'rationale' => 'Actually a candidate.'],
    ]);

    $recommendations = app(SupplierRecommender::class)($service);

    expect($recommendations)->toHaveCount(1);
    expect($recommendations->first()->supplierId)->toBe($realCandidate->id);
});

it('returns an empty collection without calling Claude when no supplier offers this service type', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    Supplier::factory()->for($company)->create(['services_offered' => [ServiceType::Catering->value]]);
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null]);

    Http::fake();

    $recommendations = app(SupplierRecommender::class)($service);

    expect($recommendations)->toBeEmpty();
    Http::assertNothingSent();
});

it('excludes a supplier whose recorded airports do not cover this leg', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $leg = $flightRequest->legs()->first();
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null, 'flight_leg_id' => $leg->id]);

    $covers = Supplier::factory()->for($company)->create(['name' => 'On Route Fuel Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $covers->airports()->attach($leg->origin_airport_id);

    $elsewhere = Supplier::factory()->for($company)->create(['name' => 'Elsewhere Fuel Co', 'services_offered' => [ServiceType::Fuel->value]]);
    $elsewhere->airports()->attach(Airport::query()->where('id', '!=', $leg->origin_airport_id)->where('id', '!=', $leg->destination_airport_id)->first());

    fakeClaudeRecommendation([
        ['supplier_id' => $covers->id, 'rationale' => 'Covers this airport.'],
    ]);

    app(SupplierRecommender::class)($service);

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return str_contains($body, 'On Route Fuel Co') && ! str_contains($body, 'Elsewhere Fuel Co');
    });
});

it('does not exclude a supplier with no airports recorded at all', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();
    $leg = $flightRequest->legs()->first();
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null, 'flight_leg_id' => $leg->id]);

    $noCoverageRecorded = Supplier::factory()->for($company)->create(['name' => 'No Data Fuel Co', 'services_offered' => [ServiceType::Fuel->value]]);

    fakeClaudeRecommendation([
        ['supplier_id' => $noCoverageRecorded->id, 'rationale' => 'Only candidate.'],
    ]);

    $recommendations = app(SupplierRecommender::class)($service);

    expect($recommendations)->toHaveCount(1);
    expect($recommendations->first()->supplierId)->toBe($noCoverageRecorded->id);
});

it('only offers the current company\'s own suppliers as candidates, not another tenant\'s', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyB->id);
    Supplier::factory()->for($companyB)->create(['name' => 'Other Tenant Supplier', 'services_offered' => [ServiceType::Fuel->value]]);

    app(CurrentCompany::class)->set($companyA->id);
    Supplier::factory()->for($companyA)->create(['name' => 'Own Tenant Supplier', 'services_offered' => [ServiceType::Fuel->value]]);
    $flightRequest = FlightRequest::factory()->create();
    $service = Service::factory()->for($flightRequest)->create(['type' => ServiceType::Fuel, 'supplier_id' => null]);

    fakeClaudeRecommendation([]);

    app(SupplierRecommender::class)($service);

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return str_contains($body, 'Own Tenant Supplier') && ! str_contains($body, 'Other Tenant Supplier');
    });
});
