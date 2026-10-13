<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The controlling document of the order-to-delivery cycle: generated
        // from a confirmed Sales Order, it defines the final list of items
        // that must actually be delivered. Requisitions, Purchase Orders,
        // Production Orders, Deliveries and Collections all hang off it.
        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('pi_no')->unique();
            $table->foreignId('sales_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();

            // Copied from the Sales Order so a Sales Person's visibility
            // scope applies to their PIs too.
            $table->foreignId('sales_person_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('pi_date');
            $table->date('valid_until')->nullable();
            $table->date('delivery_date')->nullable();

            // See ProformaInvoice::STATUSES.
            $table->string('status')->default('draft');

            $table->decimal('subtotal_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);

            $table->text('terms')->nullable();
            $table->text('note')->nullable();

            // Letter of Credit — attaching one moves an Issued PI to
            // "LC Issued". The file lives on the private disk and is only
            // served through an authenticated route.
            $table->string('lc_no')->nullable();
            $table->string('lc_bank')->nullable();
            $table->date('lc_date')->nullable();
            $table->date('lc_expiry_date')->nullable();
            $table->decimal('lc_amount', 14, 2)->nullable();
            $table->string('lc_file_path')->nullable();
            $table->string('lc_file_name')->nullable();
            $table->timestamp('lc_attached_at')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'pi_date']);
        });

        Schema::create('proforma_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proforma_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('subtotal', 14, 2);

            // How this line is planned to be fulfilled — existing stock,
            // a direct purchase, or factory production (ProformaInvoiceItem::FULFILLMENTS).
            $table->string('fulfillment')->default('stock');

            // Running total posted by Deliveries against this line.
            $table->decimal('delivered_quantity', 14, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_invoice_items');
        Schema::dropIfExists('proforma_invoices');
    }
};
