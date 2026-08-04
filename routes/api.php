<?php

use App\Http\Controllers\PostmarkInboundController;
use Illuminate\Support\Facades\Route;

// The company forwards ops@theircompany.com to a unique address that routes
// here via Postmark's inbound webhook — see ARCHITECTURE.md "Documents &
// communications" and PostmarkInboundController for how tenancy and auth
// are handled without a logged-in user.
Route::post('/webhooks/postmark/inbound/{company:slug}', PostmarkInboundController::class)
    ->name('webhooks.postmark.inbound');
