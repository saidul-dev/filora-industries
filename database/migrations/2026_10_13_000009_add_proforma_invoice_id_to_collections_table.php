<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            // Set when the customer payment (LC proceeds, advance, balance
            // payment...) was collected against a specific Proforma Invoice
            // — the PI then shows what's been collected vs. still due.
            // Collections settling a customer's aggregate receivable leave it null.
            $table->foreignId('proforma_invoice_id')->nullable()->after('project_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proforma_invoice_id');
        });
    }
};
