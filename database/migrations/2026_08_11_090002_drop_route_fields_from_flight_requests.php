<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Route and timing now live entirely on FlightLeg (see the two migrations
 * before this one) — a FlightRequest with no legs never exists in
 * practice, so there's no "keep the old columns as a single-leg fallback"
 * path to preserve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'departure_at']);
            $table->dropConstrainedForeignId('origin_airport_id');
            $table->dropConstrainedForeignId('destination_airport_id');
            $table->dropColumn(['departure_at', 'arrival_at']);
        });
    }

    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            $table->foreignId('origin_airport_id')->after('callsign')->constrained('airports');
            $table->foreignId('destination_airport_id')->after('origin_airport_id')->constrained('airports');
            $table->dateTime('departure_at')->after('destination_airport_id');
            $table->dateTime('arrival_at')->after('departure_at');
            $table->index(['company_id', 'departure_at']);
        });
    }
};
