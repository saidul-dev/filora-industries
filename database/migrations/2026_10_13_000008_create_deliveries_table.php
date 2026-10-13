<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delivery Challan — finished goods leaving a Site against a PI. A
        // PI can be delivered in several lots, from whichever Site (Factory
        // or Office) holds the stock; each lot posts the stock "out", the
        // receivable/revenue and the cost of goods sold.
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_no')->unique();
            $table->foreignId('proforma_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->date('delivery_date');

            // Revenue (PI price, net of the PI's discount) and cost (average
            // cost at delivery) of everything on this challan.
            $table->decimal('sale_amount', 14, 2)->default(0);
            $table->decimal('cost_amount', 14, 2)->default(0);

            $table->string('vehicle_no')->nullable();
            $table->string('receiver_name')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proforma_invoice_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
        Schema::dropIfExists('deliveries');
    }
};
