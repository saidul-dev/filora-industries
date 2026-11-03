<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A confirmed Sales Order can be planned and sourced before any PI:
        // each line says how it will be fulfilled (stock / purchase /
        // production), and Production Orders and Purchase Orders can be
        // raised straight from it. A PI raised later picks them up on issue.
        Schema::table('sales_order_items', function (Blueprint $table) {
            // Null until planned — SalesOrderItem::plannedFulfillment() then
            // falls back to the product type's default.
            $table->string('fulfillment')->nullable()->after('subtotal');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('proforma_invoice_id')->nullable()->change();
            $table->foreignId('proforma_invoice_item_id')->nullable()->change();
            $table->foreignId('sales_order_id')->nullable()->after('proforma_invoice_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_item_id')->nullable()->after('sales_order_id')->constrained()->restrictOnDelete();
        });

        // A PO raised from a Sales Order skips the requisition step.
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('purchase_requisition_id')->nullable()->change();
            $table->foreignId('proforma_invoice_id')->nullable()->change();
            $table->foreignId('sales_order_id')->nullable()->after('proforma_invoice_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropForeign(['sales_order_item_id']);
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn(['sales_order_item_id', 'sales_order_id']);
        });

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn('fulfillment');
        });
    }
};
