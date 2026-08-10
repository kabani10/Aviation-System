<?php

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

it('shows the Mailpit panel on the flight request page when MAILPIT_URL is configured', function () {
    config(['services.mailpit.url' => 'http://127.0.0.1:8025']);

    $company = Company::factory()->create();
    $admin = adminFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    $this->withSession(['2fa_passed' => true])->actingAs($admin)
        ->get("/admin/flight-requests/{$flightRequest->getRouteKey()}")
        ->assertOk()
        ->assertSee('Emails (Mailpit)')
        ->assertSee('http://127.0.0.1:8025', escape: false);
});

it('hides the Mailpit panel entirely when MAILPIT_URL is not configured', function () {
    config(['services.mailpit.url' => null]);

    $company = Company::factory()->create();
    $admin = adminFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    $this->withSession(['2fa_passed' => true])->actingAs($admin)
        ->get("/admin/flight-requests/{$flightRequest->getRouteKey()}")
        ->assertOk()
        ->assertDontSee('Emails (Mailpit)');
});

it('does not show the Mailpit panel on an unrelated admin page', function () {
    config(['services.mailpit.url' => 'http://127.0.0.1:8025']);

    $company = Company::factory()->create();
    $admin = adminFor($company);

    $this->withSession(['2fa_passed' => true])->actingAs($admin)
        ->get('/admin/customers')
        ->assertOk()
        ->assertDontSee('Emails (Mailpit)');
});
