<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A confidently-extracted flight leg is now enough to create a
     * FlightRequest on its own — see CreateFlightRequestFromExtraction.
     * Customer and aircraft can be identified afterward by an operator, so
     * they're no longer required at creation time.
     */
    public function up(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->foreignId('aircraft_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable(false)->change();
            $table->foreignId('aircraft_id')->nullable(false)->change();
        });
    }
};
