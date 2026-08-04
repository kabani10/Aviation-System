<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flight_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('aircraft_id')->constrained();
            $table->string('callsign')->nullable();
            $table->foreignId('origin_airport_id')->constrained('airports');
            $table->foreignId('destination_airport_id')->constrained('airports');
            $table->dateTime('departure_at');
            $table->dateTime('arrival_at');
            $table->unsignedInteger('passenger_count')->nullable();
            $table->unsignedInteger('crew_count')->nullable();
            $table->string('status')->default('new_request');
            $table->text('special_instructions')->nullable();
            // Freeform capture of what the customer originally asked for
            // ("handling, fuel, permits, catering..."), before it's broken
            // into structured Service records in Phase 6.
            $table->text('requested_services_summary')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'departure_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_requests');
    }
};
