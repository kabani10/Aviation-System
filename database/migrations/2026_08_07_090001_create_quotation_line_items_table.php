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
        Schema::create('quotation_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            // Nullable + nullOnDelete even though services are never
            // actually hard-deleted (cancelled instead, same convention as
            // everywhere else) — defensive, matching supplier_id's pattern
            // on services itself.
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            // Snapshotted at generation time (Service::type->label()) —
            // deliberately a string, not a live join, so a later rename of
            // the service type's label doesn't rewrite history on an
            // already-sent quotation.
            $table->string('description');
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('selling_price', 12, 2);
            $table->timestamps();

            $table->index(['company_id', 'quotation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_line_items');
    }
};
