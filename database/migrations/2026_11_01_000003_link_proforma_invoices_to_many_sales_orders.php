<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One PI can now cover several confirmed Sales Orders of the same
        // customer, so the single sales_order_id gives way to a pivot.
        Schema::create('proforma_invoice_sales_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proforma_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['proforma_invoice_id', 'sales_order_id']);
        });

        $now = now();
        DB::table('proforma_invoices')->whereNotNull('sales_order_id')->orderBy('id')
            ->each(fn ($pi) => DB::table('proforma_invoice_sales_order')->insert([
                'proforma_invoice_id' => $pi->id,
                'sales_order_id' => $pi->sales_order_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        Schema::table('proforma_invoices', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });

        // An order is often taken before the price is agreed — the price can
        // be left blank on the order and set on the PI instead.
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('sales_order_items')->whereNull('unit_price')->update(['unit_price' => 0]);

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 4)->nullable(false)->change();
        });

        Schema::table('proforma_invoices', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('pi_no')->constrained()->restrictOnDelete();
        });

        DB::table('proforma_invoice_sales_order')->orderBy('id')->get()
            ->groupBy('proforma_invoice_id')
            ->each(fn ($links, $piId) => DB::table('proforma_invoices')->where('id', $piId)
                ->update(['sales_order_id' => $links->first()->sales_order_id]));

        Schema::dropIfExists('proforma_invoice_sales_order');
    }
};
