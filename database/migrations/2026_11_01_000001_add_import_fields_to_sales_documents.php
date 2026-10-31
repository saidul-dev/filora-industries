<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer order sheets name the end brand ("Buyer Name") separately
        // from the paying customer, and give a delivery window rather than
        // one date — delivery_date stays the close of that window.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('buyer_name')->nullable()->after('buyer_reference');
            $table->date('delivery_start_date')->nullable()->after('order_date');
        });

        // Per-line thread details. Sales Orders and PIs are priced in USD
        // per cone to 4 decimals (sheets show $0.22 but hold ~0.2186), so
        // line totals match the customer's sheet.
        foreach (['sales_order_items', 'proforma_invoice_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('style')->nullable()->after('product_variant_id');
                $table->string('color')->nullable()->after('style');
                $table->decimal('length_per_unit', 14, 2)->nullable()->after('color');
                $table->string('remarks')->nullable()->after('length_per_unit');
                $table->decimal('unit_price', 14, 4)->change();
            });
        }

        // A delivery is billed in USD and converted at the day's rate; the
        // ledger only ever sees the BDT figure in sale_amount.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->decimal('usd_rate', 14, 4)->default(1)->after('delivery_date');
            $table->decimal('sale_amount_usd', 14, 2)->default(0)->after('usd_rate');
        });

        DB::table('deliveries')->update(['sale_amount_usd' => DB::raw('sale_amount')]);
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['usd_rate', 'sale_amount_usd']);
        });

        foreach (['sales_order_items', 'proforma_invoice_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['style', 'color', 'length_per_unit', 'remarks']);
                $table->decimal('unit_price', 14, 2)->change();
            });
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['buyer_name', 'delivery_start_date']);
        });
    }
};
