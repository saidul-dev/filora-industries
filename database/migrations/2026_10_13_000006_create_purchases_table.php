<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Purchase Order — always made against an approved Inventory
        // Requisition (and so, transitively, a Proforma Invoice). Placing
        // the order has no stock or ledger effect; each Goods Receipt does.
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_no')->unique();
            $table->foreignId('purchase_requisition_id')->constrained()->restrictOnDelete();
            $table->foreignId('proforma_invoice_id')->constrained()->restrictOnDelete();

            // A registered supplier (Party) — or, for a one-time/ad-hoc
            // vendor, just a name/phone with payment made on receipt.
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('vendor_name')->nullable();
            $table->string('vendor_phone')->nullable();

            // Default receiving location (Factory / Office).
            $table->foreignId('site_id')->constrained()->restrictOnDelete();

            $table->date('order_date');
            $table->date('expected_date')->nullable();

            // See Purchase::STATUSES.
            $table->string('status')->default('pending');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'order_date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('subtotal', 14, 2);

            // Running total across every Goods Receipt for this line.
            $table->decimal('received_quantity', 14, 4)->default(0);
            $table->timestamps();
        });

        // Goods Receipt Note — one per receiving event (partial receipts are
        // allowed). Records where the goods physically arrived; posts the
        // stock "in" and the purchase cost to Accounts.
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no')->unique();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->date('received_date');
            $table->decimal('total_amount', 14, 2)->default(0);

            // Only for a one-time vendor: the cash/bank account the goods
            // were paid from on the spot. A registered supplier's receipt
            // goes to Accounts Payable instead.
            $table->foreignId('paid_from_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();

            $table->text('note')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
