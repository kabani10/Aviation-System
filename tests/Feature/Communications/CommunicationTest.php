<?php

use App\Domain\Communications\Actions\LogCommunication;
use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\Communications\CommunicationResource\Pages\CreateCommunication;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;

it('logs a note through the panel, attributed to the author', function () {
    $company = Company::factory()->create();
    $admin = adminFor($company);

    // Livewire::test() mounts the component directly and skips the HTTP
    // middleware stack — in real panel usage SetCurrentCompany sets this
    // from the authenticated request before the component ever runs.
    app(CurrentCompany::class)->set($company->id);

    Livewire::actingAs($admin)
        ->test(CreateCommunication::class)
        ->fillForm([
            'type' => CommunicationType::Note->value,
            'subject' => 'Follow-up call',
            'body' => 'Called the customer, they confirmed the passenger count.',
            'occurred_at' => now(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $entry = $company->communications()->first();

    expect($entry)->not->toBeNull();
    expect($entry->type)->toBe(CommunicationType::Note);
    expect($entry->author_id)->toBe($admin->id);
    expect($entry->authorName())->toBe($admin->name);
    expect($entry->subjectLabel())->toBe('Company profile');
});

it('falls back to the author label when there is no user (e.g. an external sender)', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $entry = app(LogCommunication::class)(
        $company,
        CommunicationType::EmailIn,
        'body text',
        authorLabel: 'external@customer.com',
    );

    expect($entry->author_id)->toBeNull();
    expect($entry->authorName())->toBe('external@customer.com');
});

it('never shows one company\'s communications to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    app(LogCommunication::class)($companyA, CommunicationType::Note, 'Company A only', subject: 'A-only subject');

    $adminB = adminFor($companyB);

    $this->withSession(['2fa_passed' => true])
        ->actingAs($adminB)
        ->get('/admin/communications')
        ->assertOk()
        ->assertDontSee('A-only subject');
});
