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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flight_request_id')->constrained()->cascadeOnDelete();
            // Required, not nullable — an Invoice always comes from a
            // specific accepted Quotation (CreateInvoiceFromQuotation). No
            // separate line items table: the quotation's own lineItems are
            // already a frozen snapshot, so Invoice::totalAmount()/
            // profitMargin() just delegate to it rather than copying the
            // same numbers a second time.
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number');
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_date')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_number']);
            $table->index(['company_id', 'flight_request_id']);
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
