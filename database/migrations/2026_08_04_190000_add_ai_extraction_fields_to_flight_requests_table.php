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
            $table->string('source')->default('manual')->after('status');
            // Set once an operator has looked over an AI-generated draft and
            // either confirmed or corrected it — see RequestSource::Email
            // and FlightRequest::needsReview(). Null for manually-created
            // requests, which never needed a review step to begin with.
            $table->timestamp('reviewed_at')->nullable()->after('source');
            // The raw tool-use input Claude returned, kept for transparency
            // ("why did the AI fill this field in this way") — not the
            // source of truth for any column, purely diagnostic.
            $table->json('extraction_metadata')->nullable()->after('reviewed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            $table->dropColumn(['source', 'reviewed_at', 'extraction_metadata']);
        });
    }
};
