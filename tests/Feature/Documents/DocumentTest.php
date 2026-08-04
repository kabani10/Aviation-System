<?php

use App\Domain\Documents\Actions\UploadDocument;
use App\Domain\Tenancy\Models\Company;
use App\Filament\Resources\Documents\DocumentResource\Pages\CreateDocument;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');
});

it('uploads a document through the panel and stores it on the private disk', function () {
    $company = Company::factory()->create();
    $admin = adminFor($company);

    // Livewire::test() mounts the component directly and skips the HTTP
    // middleware stack — in real panel usage SetCurrentCompany sets this
    // from the authenticated request before the component ever runs.
    app(CurrentCompany::class)->set($company->id);

    $file = UploadedFile::fake()->create('license.pdf', 100, 'application/pdf');

    Livewire::actingAs($admin)
        ->test(CreateDocument::class)
        ->fillForm([
            'file' => $file,
            'category' => 'business_license',
            'title' => 'Business License 2026',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = $company->documents()->first();

    expect($document)->not->toBeNull();
    expect($document->category)->toBe('business_license');
    expect($document->title)->toBe('Business License 2026');
    expect($document->uploaded_by)->toBe($admin->id);
    expect($document->subjectLabel())->toBe('Company profile');
    Storage::disk('documents')->assertExists($document->path);
});

it('deletes the underlying file when a document is deleted', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $document = app(UploadDocument::class)(
        $company,
        UploadedFile::fake()->create('temp.pdf', 10),
        'other',
    );

    $path = $document->path;
    Storage::disk('documents')->assertExists($path);

    $document->delete();

    Storage::disk('documents')->assertMissing($path);
});

it('flags an expired document', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $expired = app(UploadDocument::class)(
        $company,
        UploadedFile::fake()->create('a.pdf', 10),
        'permit',
        expiresAt: now()->subDay()->toDateTimeString(),
    );

    $valid = app(UploadDocument::class)(
        $company,
        UploadedFile::fake()->create('b.pdf', 10),
        'permit',
        expiresAt: now()->addYear()->toDateTimeString(),
    );

    expect($expired->isExpired())->toBeTrue();
    expect($valid->isExpired())->toBeFalse();
});

it('never lets one company download another company\'s document, even with a valid signature', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CurrentCompany::class)->set($companyA->id);
    $document = app(UploadDocument::class)($companyA, UploadedFile::fake()->create('secret.pdf', 10), 'other');

    $signedUrl = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document]);

    $userB = adminFor($companyB);

    $this->withSession(['2fa_passed' => true])
        ->actingAs($userB)
        ->get($signedUrl)
        ->assertNotFound();
});

it('lets the owning company download its own document via the signed link', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);
    $document = app(UploadDocument::class)($company, UploadedFile::fake()->create('mine.pdf', 10), 'other');

    $admin = adminFor($company);
    $signedUrl = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document]);

    $this->withSession(['2fa_passed' => true])
        ->actingAs($admin)
        ->get($signedUrl)
        ->assertOk();
});
