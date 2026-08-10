<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Services move from belonging to a FlightRequest directly to belonging to
 * one of its FlightLegs. Nullable at the DB level (like supplier_id,
 * responsible_user_id on this same table) — required-ness is enforced by
 * the Filament form and by ServiceFactory always resolving one, the same
 * "DB allows it, the app never leaves it unset" tradeoff already made
 * elsewhere in this schema rather than fighting cross-driver ALTER COLUMN
 * ... SET NOT NULL support for a one-off backfill.
 *
 * The backfill (existing services -> a new sequence-1 FlightLeg built from
 * their flight's current route) only does real work against a database
 * that already has flight_requests rows — a fresh test database has none,
 * so this is a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('flight_leg_id')->nullable()->after('flight_request_id')->constrained()->nullOnDelete();
        });

        foreach (DB::table('flight_requests')->get() as $flightRequest) {
            $legId = DB::table('flight_legs')->insertGetId([
                'company_id' => $flightRequest->company_id,
                'flight_request_id' => $flightRequest->id,
                'sequence' => 1,
                'origin_airport_id' => $flightRequest->origin_airport_id,
                'destination_airport_id' => $flightRequest->destination_airport_id,
                'departure_at' => $flightRequest->departure_at,
                'arrival_at' => $flightRequest->arrival_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('services')
                ->where('flight_request_id', $flightRequest->id)
                ->update(['flight_leg_id' => $legId]);
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flight_leg_id');
        });
    }
};
