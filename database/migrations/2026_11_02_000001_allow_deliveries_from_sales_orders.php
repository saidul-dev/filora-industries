<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A challan can now go out straight against a confirmed Sales Order,
        // without a Proforma Invoice — so each delivery (and each of its
        // lines) points at either a PI or a Sales Order.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('proforma_invoice_id')->nullable()->change();
            $table->foreignId('sales_order_id')->nullable()->after('proforma_invoice_id')->constrained()->restrictOnDelete();
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->foreignId('proforma_invoice_item_id')->nullable()->change();
            $table->foreignId('sales_order_item_id')->nullable()->after('proforma_invoice_item_id')->constrained()->restrictOnDelete();
        });

        // Everything delivered against the order line, directly or through
        // a PI covering it.
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('delivered_quantity', 14, 4)->default(0)->after('subtotal');
        });

        // A PI is often raised after goods already went out against the
        // order, so each PI line remembers the order line it came from —
        // that's how earlier challans are carried onto the PI.
        Schema::table('proforma_invoice_items', function (Blueprint $table) {
            $table->foreignId('sales_order_item_id')->nullable()->after('proforma_invoice_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proforma_invoice_items', function (Blueprint $table) {
            $table->dropForeign(['sales_order_item_id']);
            $table->dropColumn('sales_order_item_id');
        });

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn('delivered_quantity');
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropForeign(['sales_order_item_id']);
            $table->dropColumn('sales_order_item_id');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
    }
};
