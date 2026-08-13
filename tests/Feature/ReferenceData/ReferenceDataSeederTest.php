<?php

use App\Domain\ReferenceData\Models\Airport;
use App\Domain\ReferenceData\Models\Country;
use Database\Seeders\ReferenceDataSeeder;

it('seeds a comprehensive set of countries and airports, not a hand-picked handful', function () {
    expect(Country::count())->toBeGreaterThan(200);
    expect(Airport::count())->toBeGreaterThan(9000);
});

it('includes airports outside the original hand-picked business-aviation hub list', function () {
    // LHBP/LZIB are real airports that were missing from the old 39-airport
    // seed list — see database/data/README.md and Phase 22's ARCHITECTURE.md
    // notes for the real inbound email that surfaced this gap.
    expect(Airport::where('icao_code', 'LHBP')->exists())->toBeTrue();
    expect(Airport::where('icao_code', 'LZIB')->exists())->toBeTrue();
});

it('is idempotent — re-running it does not create duplicates or error on existing rows', function () {
    $countryCountBefore = Country::count();
    $airportCountBefore = Airport::count();

    app(ReferenceDataSeeder::class)->run();

    expect(Country::count())->toBe($countryCountBefore);
    expect(Airport::count())->toBe($airportCountBefore);
});
