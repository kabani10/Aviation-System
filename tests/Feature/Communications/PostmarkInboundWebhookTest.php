<?php

use App\Domain\Communications\Enums\CommunicationType;
use App\Domain\Tenancy\Models\Company;
use App\Support\Tenancy\CurrentCompany;

// Set explicitly rather than relying on whatever's in .env — CI copies
// .env.example, which leaves this blank, so reading the real environment
// value here would make these tests pass locally and fail in CI.
beforeEach(fn () => config(['services.postmark.inbound_secret' => 'test-inbound-secret']));

function inboundUrl(Company $company, ?string $token): string
{
    $query = $token === null ? '' : '?token='.$token;

    return "/api/webhooks/postmark/inbound/{$company->slug}{$query}";
}

it('rejects a request with a missing or wrong token', function () {
    $company = Company::factory()->create();

    $this->postJson(inboundUrl($company, null), [])->assertForbidden();
    $this->postJson(inboundUrl($company, 'wrong-token'), [])->assertForbidden();

    expect($company->communications()->count())->toBe(0);
});

it('404s for a company slug that does not exist', function () {
    $this->postJson('/api/webhooks/postmark/inbound/does-not-exist?token='.config('services.postmark.inbound_secret'), [])
        ->assertNotFound();
});

it('creates a Communication from a valid inbound payload', function () {
    $company = Company::factory()->create();

    $payload = [
        'From' => 'ops@customer-airline.com',
        'FromName' => 'Customer Ops',
        'Subject' => 'Handling request for tomorrow',
        'TextBody' => 'We need fuel and handling for G650 tomorrow.',
        'Date' => '2026-08-04T09:00:00Z',
        'MessageID' => 'msg-001',
        'OriginalRecipient' => "ops@{$company->slug}.inbound.example",
        'Attachments' => [],
    ];

    $response = $this->postJson(inboundUrl($company, config('services.postmark.inbound_secret')), $payload);

    $response->assertOk();

    $entry = $company->communications()->first();
    expect($entry)->not->toBeNull();
    expect($entry->type)->toBe(CommunicationType::EmailIn);
    expect($entry->subject)->toBe('Handling request for tomorrow');
    expect($entry->from_address)->toBe('ops@customer-airline.com');
    expect($entry->author_label)->toBe('Customer Ops');
    expect($entry->metadata['postmark_message_id'])->toBe('msg-001');
});

it('stores each attachment as a Document on the Communication', function () {
    $company = Company::factory()->create();

    $payload = [
        'From' => 'ops@customer-airline.com',
        'Subject' => 'Documents attached',
        'TextBody' => 'See attached.',
        'Attachments' => [
            [
                'Name' => 'insurance.pdf',
                'ContentType' => 'application/pdf',
                'Content' => base64_encode('fake pdf content'),
            ],
        ],
    ];

    $this->postJson(inboundUrl($company, config('services.postmark.inbound_secret')), $payload)
        ->assertOk();

    $entry = $company->communications()->first();
    $document = $entry->documents()->first();

    expect($document)->not->toBeNull();
    expect($document->title)->toBe('insurance.pdf');
    expect($document->category)->toBe('email_attachment');
    expect($document->company_id)->toBe($company->id);
});

it('keeps inbound emails isolated per company even when posted around the same time', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $this->postJson(inboundUrl($companyA, config('services.postmark.inbound_secret')), [
        'From' => 'a@customer.com', 'Subject' => 'For A', 'TextBody' => '...',
    ])->assertOk();

    $this->postJson(inboundUrl($companyB, config('services.postmark.inbound_secret')), [
        'From' => 'b@customer.com', 'Subject' => 'For B', 'TextBody' => '...',
    ])->assertOk();

    // The webhook controller sets CurrentCompany per-request (there's no
    // session to derive it from); after two requests it's left pointing at
    // whichever company handled the last one. Clear it so these assertions
    // read as "what's actually in the database", not "what's visible
    // through whatever tenant context the last request happened to leave".
    app(CurrentCompany::class)->clear();

    expect($companyA->communications()->count())->toBe(1);
    expect($companyB->communications()->count())->toBe(1);
    expect($companyA->communications()->first()->subject)->toBe('For A');
    expect($companyB->communications()->first()->subject)->toBe('For B');
});
