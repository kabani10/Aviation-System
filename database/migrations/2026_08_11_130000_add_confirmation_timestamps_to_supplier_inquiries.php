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
        Schema::table('supplier_inquiries', function (Blueprint $table) {
            // Separate from requested_at/responded_at (the quote cycle) —
            // a chosen inquiry goes through a second round-trip once it's
            // time to book, and reusing the quote-cycle timestamps for that
            // would erase the response-time history ComputeSupplierPerformance
            // reads.
            $table->timestamp('confirmation_requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_inquiries', function (Blueprint $table) {
            $table->dropColumn(['confirmation_requested_at', 'confirmed_at']);
        });
    }
};
