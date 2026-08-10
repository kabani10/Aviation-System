<?php

namespace Database\Seeders;

use App\Domain\Aircraft\Models\Aircraft;
use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerContact;
use App\Domain\Documents\Actions\UploadDocument;
use App\Domain\Finance\Actions\CreateInvoiceFromQuotation;
use App\Domain\Finance\Actions\RecordInvoicePayment;
use App\Domain\Finance\Actions\SendInvoice;
use App\Domain\Finance\Enums\InvoiceStatus;
use App\Domain\Finance\Models\Invoice;
use App\Domain\FlightRequests\Actions\CompleteFlight;
use App\Domain\FlightRequests\Actions\MarkFlightInOperation;
use App\Domain\FlightRequests\Enums\FlightStatus;
use App\Domain\FlightRequests\Enums\RequestSource;
use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Quotations\Actions\CreateQuotationFromServices;
use App\Domain\Quotations\Actions\RecordQuotationResponse;
use App\Domain\Quotations\Actions\SendQuotation;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\ReferenceData\Models\Airport;
use App\Domain\Services\Actions\RecordSupplierQuote;
use App\Domain\Services\Actions\SendSupplierRequest;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\Models\Service;
use App\Domain\Shared\Enums\ServiceType;
use App\Domain\Suppliers\Models\Supplier;
use App\Domain\Suppliers\Models\SupplierContact;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Fills one company with realistic data covering every status this app
 * knows about — every FlightStatus, ServiceStatus, QuotationStatus, and
 * InvoiceStatus appears somewhere, so a new contributor (or a demo) can see
 * the whole lifecycle without clicking through it by hand. Not part of
 * DatabaseSeeder::run() — roles/reference data are needed everywhere
 * (tests included), this is local/demo-only: `php artisan db:seed
 * --class=DemoDataSeeder`.
 *
 * Reuses the same domain Actions the Filament UI calls (SendSupplierRequest,
 * CreateQuotationFromServices, SendInvoice, ...) rather than inserting rows
 * directly, so the data this produces is exactly as internally consistent
 * as a real operator's — Communications get logged, statuses transition
 * the same way. Mail is faked (see run()) so seeding never depends on
 * Mailpit being up.
 */
class DemoDataSeeder extends Seeder
{
    private Company $company;

    private User $admin;

    /** @var Collection<int, Supplier> */
    private Collection $suppliers;

    /** @var Collection<int, Customer> */
    private Collection $customers;

    /** @var Collection<int, Airport> */
    private Collection $airports;

    public function run(): void
    {
        $this->company = Company::first() ?? Company::factory()->create(['name' => 'Meridian Trip Support']);
        app(CurrentCompany::class)->set($this->company->id);

        $this->admin = $this->company->users()->first() ?? $this->createFallbackAdmin();
        $this->airports = Airport::query()->inRandomOrder()->take(10)->get();

        Mail::fake(); // seeding shouldn't depend on Mailpit being reachable; the Communications/statuses this leaves behind are what matters

        // All or nothing — a mistake partway through (a thrown RuntimeException
        // from a domain Action, same validation a real operator would hit)
        // shouldn't leave half a demo company behind.
        DB::transaction(function () {
            $this->suppliers = $this->seedSuppliers();
            $this->customers = $this->seedCustomers();

            $this->seedNewRequest();
            $this->seedMultiLegTrip();
            $this->seedAiDraftNeedingReview();
            $this->seedUnderReview();
            $this->seedQuotationInProgress();
            $this->seedQuotationSentAndExpiring();
            $this->seedConfirmedWithQuotationHistory();
            $this->seedInOperation();
            $this->seedCompleted();
            $this->seedInvoicedAndOverdue();
            $this->seedClosedAndPaid();
            $this->seedCancelled();
        });

        $this->command?->info("Demo data seeded into company \"{$this->company->name}\" — log in as {$this->admin->email}.");
    }

    private function createFallbackAdmin(): User
    {
        $admin = User::factory()->for($this->company)->create([
            'name' => 'Demo Admin',
            'email' => 'demo-admin@'.str($this->company->name)->slug().'.example',
            'password' => 'password123',
        ]);
        $admin->assignRole('Admin');

        $this->command?->warn("No existing user found — created {$admin->email} / password123.");

        return $admin;
    }

    /** @return Collection<int, Supplier> */
    private function seedSuppliers(): Collection
    {
        $roster = [
            ['name' => 'SkyLink Ground Services', 'types' => [ServiceType::GroundHandling, ServiceType::Parking]],
            ['name' => 'AeroFuel Partners', 'types' => [ServiceType::Fuel]],
            ['name' => 'Global Permits Ltd', 'types' => [ServiceType::LandingPermit, ServiceType::OverflightPermit, ServiceType::AirportSlots]],
            ['name' => 'Élite Catering & Hospitality', 'types' => [ServiceType::Catering, ServiceType::Hotel]],
            ['name' => 'SecureCrew Logistics', 'types' => [ServiceType::CrewTransport, ServiceType::PassengerTransport, ServiceType::Security, ServiceType::VipHandling]],
        ];

        return collect($roster)->map(function (array $entry) {
            $supplier = Supplier::create([
                'name' => $entry['name'],
                'currency' => 'USD',
                'payment_terms' => 'Net 30',
                'services_offered' => collect($entry['types'])->map->value->all(),
                'is_active' => true,
            ]);

            SupplierContact::create([
                'supplier_id' => $supplier->id,
                'name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'phone' => fake()->phoneNumber(),
                'title' => 'Operations Coordinator',
                'is_primary' => true,
            ]);

            return $supplier;
        });
    }

    /** @return Collection<int, Customer> */
    private function seedCustomers(): Collection
    {
        $roster = [
            ['name' => 'Meridian Capital Partners', 'billing_email' => 'accounts@meridiancapital.example'],
            ['name' => 'Falcon Private Jets Brokerage', 'billing_email' => 'billing@falconjets.example'],
            ['name' => 'Obsidian Holdings', 'billing_email' => 'ap@obsidianholdings.example'],
            ['name' => 'Starlight Charters', 'billing_email' => 'finance@starlightcharters.example'],
            // Deliberately no billing_email — surfaces the "missing information"
            // finding on any flight tied to this customer.
            ['name' => 'Vantage Group', 'billing_email' => null],
        ];

        return collect($roster)->map(function (array $entry) {
            $customer = Customer::create([
                'name' => $entry['name'],
                'billing_email' => $entry['billing_email'],
                'payment_terms' => 'Net 30',
                'is_active' => true,
            ]);

            CustomerContact::create([
                'customer_id' => $customer->id,
                'name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'phone' => fake()->phoneNumber(),
                'title' => 'Flight Coordinator',
                'is_primary' => true,
            ]);

            Aircraft::create([
                'customer_id' => $customer->id,
                'registration' => strtoupper('N'.fake()->unique()->numerify('###').fake()->randomLetter()),
                'aircraft_type' => fake()->randomElement([
                    'Gulfstream G650', 'Bombardier Global 6000', 'Dassault Falcon 7X',
                    'Cessna Citation X', 'Embraer Legacy 650',
                ]),
                'mtow_kg' => fake()->numberBetween(8000, 45000),
                'is_active' => true,
            ]);

            return $customer;
        });
    }

    private function supplierFor(ServiceType $type): Supplier
    {
        return $this->suppliers->first(fn (Supplier $s) => in_array($type->value, $s->services_offered, strict: true));
    }

    /** @return array{0: Airport, 1: Airport} */
    private function randomAirportPair(): array
    {
        return $this->airports->random(2)->values()->all();
    }

    private function makeFlight(Customer $customer, string $callsign, array $overrides = []): FlightRequest
    {
        $aircraft = $customer->aircraft()->first();
        [$origin, $destination] = $this->randomAirportPair();
        $departure = fake()->dateTimeBetween('+3 days', '+3 weeks');

        $flightRequest = FlightRequest::create(array_merge([
            'customer_id' => $customer->id,
            'aircraft_id' => $aircraft->id,
            'callsign' => $callsign,
            'passenger_count' => fake()->numberBetween(1, 14),
            'crew_count' => fake()->numberBetween(2, 4),
            'status' => FlightStatus::NewRequest,
            'requested_services_summary' => 'Ground handling, fuel, and catering for the outbound leg.',
        ], $overrides));

        $flightRequest->legs()->create([
            'sequence' => 1,
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'departure_at' => $departure,
            'arrival_at' => (clone $departure)->modify('+'.fake()->numberBetween(1, 10).' hours'),
        ]);

        return $flightRequest;
    }

    private function makeService(FlightRequest $flightRequest, ServiceType $type, array $overrides = []): Service
    {
        return Service::create(array_merge([
            'flight_request_id' => $flightRequest->id,
            'flight_leg_id' => $flightRequest->legs()->first()->id,
            'type' => $type,
            'status' => ServiceStatus::NotStarted,
        ], $overrides));
    }

    private function seedNewRequest(): void
    {
        $customer = $this->customers[0];

        $flight = $this->makeFlight($customer, 'N1AAA', [
            'callsign' => $customer->aircraft()->first()->registration,
        ]);

        // Just entered, nobody's touched it yet — the one place
        // ServiceStatus::NotStarted (the default) actually shows up, since
        // every other scenario below moves a service past it immediately.
        $this->makeService($flight, ServiceType::GroundHandling);
    }

    /**
     * DXB -> IST -> CDG: the multi-leg case every other scenario in this
     * file glosses over by only ever touching a flight's first leg.
     * Ground handling is booked separately per leg, with a different
     * supplier and price at each stop — the whole reason legs exist as
     * their own record rather than one flat services list on the flight.
     */
    private function seedMultiLegTrip(): void
    {
        $customer = $this->customers[1];
        $dxb = Airport::where('icao_code', 'OMDB')->firstOrFail();
        $ist = Airport::where('icao_code', 'LTFM')->firstOrFail();
        $cdg = Airport::where('icao_code', 'LFPG')->firstOrFail();

        $flight = $this->makeFlight($customer, $customer->aircraft()->first()->registration, [
            'requested_services_summary' => 'Ground handling at both the Istanbul stopover and the Paris destination.',
        ]);

        $firstLeg = $flight->legs()->first();
        $firstLeg->update([
            'origin_airport_id' => $dxb->id,
            'destination_airport_id' => $ist->id,
        ]);

        $secondLeg = $flight->legs()->create([
            'sequence' => 2,
            'origin_airport_id' => $ist->id,
            'destination_airport_id' => $cdg->id,
            'departure_at' => $firstLeg->arrival_at->addHours(2),
            'arrival_at' => $firstLeg->arrival_at->copy()->addHours(6),
        ]);

        $this->makeService($flight, ServiceType::GroundHandling, [
            'flight_leg_id' => $firstLeg->id,
            'supplier_id' => $this->supplierFor(ServiceType::GroundHandling)->id,
            'cost' => 1900,
            'selling_price' => 2500,
            'status' => ServiceStatus::Confirmed,
            'supplier_confirmed_at' => now()->subDays(3),
        ]);

        $this->makeService($flight, ServiceType::GroundHandling, [
            'flight_leg_id' => $secondLeg->id,
            'supplier_id' => $this->supplierFor(ServiceType::GroundHandling)->id,
            'status' => ServiceStatus::SupplierRequestSent,
            'quote_requested_at' => now()->subDay(),
        ]);
    }

    /** Simulates what CreateFlightRequestFromExtraction leaves behind — unreviewed, gaps in it. */
    private function seedAiDraftNeedingReview(): void
    {
        $customer = $this->customers[4]; // Vantage Group — no billing email, doubles up the "missing information" demo

        $flight = $this->makeFlight($customer, $customer->aircraft()->first()->registration, [
            'passenger_count' => null,
            'crew_count' => null,
            'source' => RequestSource::Email,
            'reviewed_at' => null,
            'extraction_metadata' => [
                'confidence' => 'medium',
                'raw_excerpt' => 'Hi, need handling + fuel for our G650 next week, will confirm pax count shortly. Thanks.',
            ],
        ]);

        app(LogCommunication::class)(
            communicable: $flight,
            type: CommunicationType::EmailIn,
            body: 'Hi, need handling + fuel for our G650 next week, will confirm pax count shortly. Thanks.',
            subject: 'Trip support request',
            fromAddress: 'ops@vantagegroup.example',
        );
    }

    private function seedUnderReview(): void
    {
        $customer = $this->customers[1];
        $flight = $this->makeFlight($customer, $customer->aircraft()->first()->registration, [
            'status' => FlightStatus::UnderReview,
        ]);

        $this->makeService($flight, ServiceType::GroundHandling, [
            'status' => ServiceStatus::InformationRequired,
            'notes' => 'Waiting on exact passenger count before requesting a quote.',
        ]);

        $this->makeService($flight, ServiceType::LandingPermit, [
            'status' => ServiceStatus::AtRisk,
            'deadline' => now()->subDay(),
            'notes' => 'Permit application deadline has already passed — needs escalation.',
        ]);
    }

    private function seedQuotationInProgress(): void
    {
        $customer = $this->customers[2];
        $flight = $this->makeFlight($customer, $customer->aircraft()->first()->registration, [
            'status' => FlightStatus::QuotationInProgress,
        ]);

        $groundHandlingSupplier = $this->supplierFor(ServiceType::GroundHandling);
        $groundHandling = $this->makeService($flight, ServiceType::GroundHandling, [
            'supplier_id' => $groundHandlingSupplier->id,
        ]);
        app(SendSupplierRequest::class)($groundHandling, $groundHandlingSupplier->contacts()->firstOrFail(), 'Please quote ground handling for this flight.', $this->admin);
        // Backdated after the fact so it reads as stale — trips the
        // "unanswered for over a week" operational risk finding.
        $groundHandling->update(['quote_requested_at' => now()->subDays(10)]);

        $fuelSupplier = $this->supplierFor(ServiceType::Fuel);
        $fuel = $this->makeService($flight, ServiceType::Fuel, [
            'supplier_id' => $fuelSupplier->id,
        ]);
        app(SendSupplierRequest::class)($fuel, $fuelSupplier->contacts()->firstOrFail(), null, $this->admin);
        app(RecordSupplierQuote::class)($fuel, 4200, 'Quoted by email.', $this->admin);
        $fuel->update(['selling_price' => 5100]); // selling price is set separately from the supplier's cost, same as the real workflow

        // Only the priced Fuel line makes it into the Draft quotation — this
        // flight is deliberately still "in progress", not sent.
        app(CreateQuotationFromServices::class)($flight, $this->admin, 'Draft pending ground handling pricing.');
    }

    private function seedQuotationSentAndExpiring(): void
    {
        $customer = $this->customers[3];
        $flight = $this->makeFlight($customer, $customer->aircraft()->first()->registration, [
            'status' => FlightStatus::UnderReview,
        ]);

        $this->makeService($flight, ServiceType::GroundHandling, [
            'supplier_id' => $this->supplierFor(ServiceType::GroundHandling)->id,
            'cost' => 2200,
            'selling_price' => 2900,
            'status' => ServiceStatus::Confirmed,
            'supplier_confirmed_at' => now()->subDays(2),
        ]);

        $this->makeService($flight, ServiceType::Catering, [
            'supplier_id' => $this->supplierFor(ServiceType::Catering)->id,
            'cost' => 600,
            'selling_price' => 900,
            'status' => ServiceStatus::WaitingCustomerApproval,
            'notes' => 'Customer is deciding between the standard and premium catering package.',
        ]);

        $quotation = app(CreateQuotationFromServices::class)($flight, $this->admin, null, now()->subDay());
        app(SendQuotation::class)($quotation); // valid_until already in the past — surfaces as an expired, unresponded quotation
    }

    private function seedConfirmedWithQuotationHistory(): void
    {
        $customer = $this->customers[0];
        $flight = $this->makeFlight($customer, $customer->aircraft()->first()->registration, [
            'status' => FlightStatus::UnderReview,
        ]);

        $this->makeService($flight, ServiceType::GroundHandling, [
            'supplier_id' => $this->supplierFor(ServiceType::GroundHandling)->id,
            'cost' => 2500,
            'selling_price' => 3200,
            'status' => ServiceStatus::Confirmed,
            'supplier_confirmed_at' => now()->subDays(5),
        ]);
        $this->makeService($flight, ServiceType::VipHandling, [
            'supplier_id' => $this->supplierFor(ServiceType::VipHandling)->id,
            'cost' => 800,
            'selling_price' => 1200,
            'status' => ServiceStatus::Confirmed,
            'supplier_confirmed_at' => now()->subDays(5),
        ]);

        // An earlier quotation the customer rejected as too expensive —
        // superseded by the one below, history kept rather than edited.
        $rejected = app(CreateQuotationFromServices::class)($flight, $this->admin, 'Initial pricing.');
        app(SendQuotation::class)($rejected);
        app(RecordQuotationResponse::class)($rejected->fresh(), QuotationStatus::Rejected, 'Too expensive — asked for a revised quote.');

        $accepted = app(CreateQuotationFromServices::class)($flight, $this->admin, 'Revised pricing after customer pushback.');
        app(SendQuotation::class)($accepted);
        app(RecordQuotationResponse::class)($accepted->fresh(), QuotationStatus::Accepted, 'Confirmed by phone.');
    }

    private function seedInOperation(): void
    {
        [$flight] = $this->buildConfirmedFlight($this->customers[1], 'InOp');

        app(MarkFlightInOperation::class)($flight->fresh());
    }

    private function seedCompleted(): void
    {
        [$flight, $services] = $this->buildConfirmedFlight($this->customers[2], 'Cmpl');

        app(MarkFlightInOperation::class)($flight->fresh());
        app(CompleteFlight::class)($flight->fresh());

        // One service actually finished, one got cancelled after the flight
        // landed early (a real "the plan changed" case, not every service
        // on a completed flight necessarily ran).
        $services[0]->update(['status' => ServiceStatus::Completed]);
        $services[1]->update(['status' => ServiceStatus::Cancelled, 'notes' => 'Cancelled — customer arranged their own transport on arrival.']);

        // A generated-but-not-yet-sent invoice, to show InvoiceStatus::Draft.
        app(CreateInvoiceFromQuotation::class)($flight->fresh(), $this->admin, 'Awaiting final review before sending.');
    }

    private function seedInvoicedAndOverdue(): void
    {
        [$flight] = $this->buildConfirmedFlight($this->customers[3], 'Ovrd');

        app(MarkFlightInOperation::class)($flight->fresh());
        app(CompleteFlight::class)($flight->fresh());

        $invoice = app(CreateInvoiceFromQuotation::class)($flight->fresh(), $this->admin, null, now()->subDays(10));
        app(SendInvoice::class)($invoice->fresh());
    }

    private function seedClosedAndPaid(): void
    {
        [$flight] = $this->buildConfirmedFlight($this->customers[0], 'Paid');

        app(MarkFlightInOperation::class)($flight->fresh());
        app(CompleteFlight::class)($flight->fresh());

        // A first invoice that was voided (wrong amount) before the real one
        // went out — InvoiceStatus::Cancelled has no UI action yet (see
        // ARCHITECTURE.md), so this is set directly, same as an operator
        // would have to do at the database level today.
        Invoice::create([
            'flight_request_id' => $flight->id,
            'quotation_id' => $flight->quotations()->where('status', QuotationStatus::Accepted)->firstOrFail()->id,
            'invoice_number' => 'INV-'.Str::padLeft((string) (Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count() + 1), 6, '0'),
            'status' => InvoiceStatus::Cancelled,
            'notes' => 'Voided — wrong billing amount, reissued below.',
        ]);

        $invoice = app(CreateInvoiceFromQuotation::class)($flight->fresh(), $this->admin);
        app(SendInvoice::class)($invoice->fresh());
        app(RecordInvoicePayment::class)($invoice->fresh(), 'Paid by wire transfer.');

        // A sample uploaded document, attached to the aircraft that flew —
        // shows the Documents module alongside everything else.
        app(UploadDocument::class)(
            $flight->aircraft,
            UploadedFile::fake()->create('airworthiness-certificate.pdf', 120, 'application/pdf'),
            category: 'Certificate',
            title: 'Airworthiness Certificate',
            expiresAt: now()->addYear()->toDateString(),
            uploadedBy: $this->admin,
        );
    }

    private function seedCancelled(): void
    {
        $customer = $this->customers[3];
        $flight = $this->makeFlight($customer, $customer->aircraft()->first()->registration, [
            'status' => FlightStatus::UnderReview,
        ]);

        $service = $this->makeService($flight, ServiceType::GroundHandling, [
            'supplier_id' => $this->supplierFor(ServiceType::GroundHandling)->id,
            'cost' => 1800,
            'selling_price' => 2400,
            'status' => ServiceStatus::QuotationReceived,
        ]);

        $quotation = app(CreateQuotationFromServices::class)($flight, $this->admin);
        app(SendQuotation::class)($quotation);
        app(RecordQuotationResponse::class)($quotation->fresh(), QuotationStatus::Rejected, 'Customer cancelled the trip entirely.');

        $service->update(['status' => ServiceStatus::Cancelled]);
        $flight->update(['status' => FlightStatus::Cancelled]);

        app(LogCommunication::class)(
            communicable: $flight,
            type: CommunicationType::Note,
            body: 'Customer cancelled the trip — no penalty per contract terms.',
            author: $this->admin,
        );
    }

    /**
     * Shared setup for every "flight already has an accepted quotation and
     * two confirmed services" scenario — In Operation, Completed, Invoiced,
     * and Closed all start from exactly this state before their own action
     * moves them further.
     *
     * @return array{0: FlightRequest, 1: array<int, Service>}
     */
    private function buildConfirmedFlight(Customer $customer, string $suffix): array
    {
        $flight = $this->makeFlight($customer, $customer->aircraft()->first()->registration, [
            'status' => FlightStatus::UnderReview,
        ]);

        $groundHandling = $this->makeService($flight, ServiceType::GroundHandling, [
            'supplier_id' => $this->supplierFor(ServiceType::GroundHandling)->id,
            'cost' => 2100,
            'selling_price' => 2800,
            'status' => ServiceStatus::Confirmed,
            'supplier_confirmed_at' => now()->subWeek(),
        ]);
        $fuel = $this->makeService($flight, ServiceType::Fuel, [
            'supplier_id' => $this->supplierFor(ServiceType::Fuel)->id,
            'cost' => 3900,
            'selling_price' => 4600,
            'status' => ServiceStatus::Confirmed,
            'supplier_confirmed_at' => now()->subWeek(),
        ]);

        $quotation = app(CreateQuotationFromServices::class)($flight, $this->admin);
        app(SendQuotation::class)($quotation);
        app(RecordQuotationResponse::class)($quotation->fresh(), QuotationStatus::Accepted, 'Confirmed by phone.');

        return [$flight->fresh(), [$groundHandling, $fuel]];
    }
}
