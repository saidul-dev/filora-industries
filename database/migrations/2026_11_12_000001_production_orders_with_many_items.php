<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Production Order is now raised for one Sales Order or PI and can
        // cover one or several of its manufactured lines. Each line sits on
        // one live Production Order at most.
        Schema::create('production_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proforma_invoice_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('produced_quantity', 14, 4)->nullable();
            // Σ consumed quantity × unit cost of this line's materials.
            $table->decimal('material_cost', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->foreignId('production_order_item_id')->nullable()->after('production_order_id')
                ->constrained()->cascadeOnDelete();
        });

        $now = now();
        DB::table('production_orders')->orderBy('id')->each(function ($order) use ($now) {
            $itemId = DB::table('production_order_items')->insertGetId([
                'production_order_id' => $order->id,
                'proforma_invoice_item_id' => $order->proforma_invoice_item_id,
                'sales_order_item_id' => $order->sales_order_item_id,
                'product_id' => $order->product_id,
                'product_variant_id' => $order->product_variant_id,
                'quantity' => $order->quantity,
                'produced_quantity' => $order->produced_quantity,
                'material_cost' => $order->material_cost,
                'created_at' => $order->created_at ?? $now,
                'updated_at' => $now,
            ]);

            DB::table('production_order_materials')->where('production_order_id', $order->id)
                ->update(['production_order_item_id' => $itemId]);
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropForeign(['proforma_invoice_item_id']);
            $table->dropForeign(['sales_order_item_id']);
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_variant_id']);
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn([
                'proforma_invoice_item_id', 'sales_order_item_id', 'product_id',
                'product_variant_id', 'quantity', 'produced_quantity',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('proforma_invoice_item_id')->nullable()->after('proforma_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_item_id')->nullable()->after('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->after('sales_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4)->default(0)->after('site_id');
            $table->decimal('produced_quantity', 14, 4)->nullable()->after('quantity');
        });

        // Only the first line of a multi-line order survives the rollback.
        DB::table('production_order_items')->orderBy('id')->get()->groupBy('production_order_id')
            ->each(fn ($items, $orderId) => DB::table('production_orders')->where('id', $orderId)->update([
                'proforma_invoice_item_id' => $items->first()->proforma_invoice_item_id,
                'sales_order_item_id' => $items->first()->sales_order_item_id,
                'product_id' => $items->first()->product_id,
                'product_variant_id' => $items->first()->product_variant_id,
                'quantity' => $items->first()->quantity,
                'produced_quantity' => $items->first()->produced_quantity,
            ]));

        Schema::table('production_order_materials', function (Blueprint $table) {
            $table->dropForeign(['production_order_item_id']);
            $table->dropColumn('production_order_item_id');
        });

        Schema::dropIfExists('production_order_items');
    }
};
