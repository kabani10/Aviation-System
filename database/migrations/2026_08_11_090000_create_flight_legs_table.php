<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flight can be one-way (one leg) or multi-stop (several) — DXB-IST then
 * IST-CDG, for instance. Route, times, and (as of the next migration)
 * services all live at the leg level, not the flight level, since a
 * ground-handling requirement at IST is nothing to do with the one at CDG.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flight_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('origin_airport_id')->constrained('airports');
            $table->foreignId('destination_airport_id')->constrained('airports');
            $table->dateTime('departure_at');
            $table->dateTime('arrival_at');
            $table->timestamps();

            $table->unique(['flight_request_id', 'sequence']);
            $table->index(['company_id', 'departure_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_legs');
    }
};
