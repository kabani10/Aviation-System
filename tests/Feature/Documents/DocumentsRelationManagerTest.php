<?php

use App\Domain\FlightRequests\Models\FlightRequest;
use App\Domain\Tenancy\Models\Company;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ViewFlightRequest;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('documents'));

/**
 * Filament defaults every relation manager to read-only on a resource's View
 * page (Panel::hasReadOnlyRelationManagersOnResourceViewPagesByDefault(), on
 * by default, never overridden in AdminPanelProvider) — Create/Edit/Delete
 * silently vanish there, for every role including Admin, since isReadOnly()
 * short-circuits before any permission check runs.
 * DocumentsRelationManager::isReadOnly() overrides this back to false, since
 * several roles (Procurement, Finance, Management) only ever reach a flight
 * request's View page and would otherwise never be able to attach paperwork
 * at all — see the class docblock.
 */
it('uploads a document from the View page, not just Edit', function () {
    $company = Company::factory()->create();
    $admin = adminFor($company);
    app(CurrentCompany::class)->set($company->id);

    $flightRequest = FlightRequest::factory()->create();

    $component = Livewire::actingAs($admin)
        ->test(DocumentsRelationManager::class, [
            'ownerRecord' => $flightRequest,
            'pageClass' => ViewFlightRequest::class,
        ]);

    $component->assertTableActionVisible('create');

    $component->callTableAction('create', data: [
        'file' => UploadedFile::fake()->create('insurance.pdf', 10, 'application/pdf'),
        'notes' => 'Valid through end of year.',
    ])->assertHasNoTableActionErrors();

    $document = $flightRequest->documents()->first();

    expect($document)->not->toBeNull();
    // category/title are hidden during upload (see the form's docblock) —
    // category defaults, title falls back to the filename.
    expect($document->category)->toBe(DocumentsRelationManager::DEFAULT_CATEGORY);
    expect($document->title)->toBe('insurance.pdf');
    expect($document->notes)->toBe('Valid through end of year.');
    Storage::disk('documents')->assertExists($document->path);
});
