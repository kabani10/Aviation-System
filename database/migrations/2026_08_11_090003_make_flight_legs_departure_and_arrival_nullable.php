<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI extraction can identify the route (and even the customer/aircraft)
 * from an email with total confidence while the sender never gave an exact
 * departure or arrival time ("tomorrow", no arrival at all). Previously
 * that missing time blocked the whole leg — and therefore the whole
 * FlightRequest — from being created at all, silently parking a
 * recognizable request as an unlinked Communication instead. Now a leg
 * only needs a resolved route; a missing time is instead something
 * CheckMissingInformation flags for an operator to fill in, the same way
 * a missing passenger count already works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_legs', function (Blueprint $table) {
            $table->dateTime('departure_at')->nullable()->change();
            $table->dateTime('arrival_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('flight_legs', function (Blueprint $table) {
            $table->dateTime('departure_at')->nullable(false)->change();
            $table->dateTime('arrival_at')->nullable(false)->change();
        });
    }
};
