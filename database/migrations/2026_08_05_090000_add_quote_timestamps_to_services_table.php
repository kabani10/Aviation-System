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
        Schema::table('services', function (Blueprint $table) {
            // Set by SendSupplierRequest / RecordSupplierQuote respectively —
            // together they're the raw material ComputeSupplierPerformance
            // needs for response-time metrics. supplier_confirmed_at (already
            // on the table) stays a separate, later step: a quote being
            // received doesn't mean the supplier has confirmed availability.
            $table->timestamp('quote_requested_at')->nullable()->after('supplier_id');
            $table->timestamp('quote_received_at')->nullable()->after('quote_requested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['quote_requested_at', 'quote_received_at']);
        });
    }
};
