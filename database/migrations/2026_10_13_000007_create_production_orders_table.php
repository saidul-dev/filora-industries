<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Production Order — always generated under a PI line. Issuing it
        // consumes the BOM's raw materials from stock at the production
        // site straight away; completing it receives the finished goods
        // into stock there, costed at the materials consumed.
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('production_no')->unique();
            $table->foreignId('proforma_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('proforma_invoice_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            // Where the materials are consumed and the output received —
            // normally the Factory.
            $table->foreignId('site_id')->constrained()->restrictOnDelete();

            $table->decimal('quantity', 14, 4);
            $table->decimal('produced_quantity', 14, 4)->nullable();

            // Σ consumed quantity × the material's average cost at issue.
            $table->decimal('material_cost', 14, 2)->default(0);

            // See ProductionOrder::STATUSES.
            $table->string('status')->default('not_started');

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'site_id']);
        });

        // The BOM as it stood when the order was issued — kept per order so
        // later BOM edits never rewrite what an order actually consumed.
        Schema::create('production_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_per_unit', 14, 4);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_materials');
        Schema::dropIfExists('production_orders');
    }
};
