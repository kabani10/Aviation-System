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
        Schema::table('quotations', function (Blueprint $table) {
            // Null means "whole flight" (the original, still-default
            // behavior) — set when CreateQuotationFromServices is scoped to
            // one leg instead. Nullable + nullOnDelete since a leg can be
            // deleted (LegsRelationManager allows it) without the quotation
            // it produced becoming invalid history.
            $table->foreignId('flight_leg_id')->nullable()->after('flight_request_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flight_leg_id');
        });
    }
};
