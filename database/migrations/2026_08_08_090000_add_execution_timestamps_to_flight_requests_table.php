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
        Schema::table('flight_requests', function (Blueprint $table) {
            // Set by MarkFlightInOperation / CompleteFlight respectively —
            // same "track the checkpoint, don't just flip a status" convention
            // as Service's quote_requested_at/quote_received_at and
            // Quotation's sent_at/responded_at.
            $table->timestamp('operation_started_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('operation_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            $table->dropColumn(['operation_started_at', 'completed_at']);
        });
    }
};
