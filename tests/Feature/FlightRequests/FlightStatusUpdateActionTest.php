<?php

use App\Domain\Customers\Models\Customer;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ViewFlightRequest;
use App\Mail\FlightStatusUpdateMail;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('offers "send status update" to flights.manage holders, regardless of flight status', function () {
    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    $finance = userWithRoleFor($company, 'Finance');
    app(CurrentCompany::class)->set($company->id);

    $newRequest = FlightRequest::factory()->create(['status' => FlightStatus::NewRequest]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $newRequest->getRouteKey()])
        ->assertActionExists('sendStatusUpdate');

    Livewire::actingAs($finance)
        ->test(ViewFlightRequest::class, ['record' => $newRequest->getRouteKey()])
        ->assertActionHidden('sendStatusUpdate');
});

it('sends a status update end to end from the view page', function () {
    Mail::fake();

    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => 'billing@customer.com']);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id]);

    Livewire::actingAs($sales)
        ->test(ViewFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->mountAction('sendStatusUpdate')
        ->setActionData(['message' => 'All on track.'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    Mail::assertSent(FlightStatusUpdateMail::class, fn ($mail) => $mail->hasTo('billing@customer.com'));
});

it('shows a friendly error instead of crashing when the customer has no billing email', function () {
    Mail::fake();

    $company = Company::factory()->create();
    $sales = salesUserFor($company);
    app(CurrentCompany::class)->set($company->id);

    $customer = Customer::factory()->for($company)->create(['billing_email' => null]);
    $flightRequest = FlightRequest::factory()->create(['customer_id' => $customer->id]);

    Livewire::actingAs($sales)
        ->test(EditFlightRequest::class, ['record' => $flightRequest->getRouteKey()])
        ->mountAction('sendStatusUpdate')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Could not send status update');

    Mail::assertNothingSent();
});
