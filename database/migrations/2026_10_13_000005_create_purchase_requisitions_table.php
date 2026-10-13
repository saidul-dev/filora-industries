<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inventory Requisition — a request to buy finished goods (for a
        // direct-purchase PI line) or raw materials (for production), always
        // raised under a controlling Proforma Invoice. Purchases are only
        // ever made against an approved requisition.
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('requisition_no')->unique();
            $table->foreignId('proforma_invoice_id')->constrained()->restrictOnDelete();

            // Where the goods are needed (Factory / Office) — becomes the
            // default receiving location of the Purchase Order.
            $table->foreignId('site_id')->constrained()->restrictOnDelete();

            $table->date('request_date');
            $table->date('required_by')->nullable();

            // See PurchaseRequisition::STATUSES.
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'request_date']);
        });

        Schema::create('purchase_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('estimated_unit_cost', 14, 4)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
    }
};
