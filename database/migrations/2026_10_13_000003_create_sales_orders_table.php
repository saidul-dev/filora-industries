<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A buyer's order as received — freely editable while Pending, locked
        // once Confirmed, and the source a Proforma Invoice is generated
        // from. No stock or ledger effect at any point: the PI (and the
        // deliveries against it) is the controlling document for that.
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();

            // The responsible Sales Person — a Sales Person only ever sees
            // their own orders (SalesOrder::scopeVisibleTo).
            $table->foreignId('sales_person_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->string('buyer_reference')->nullable();
            $table->text('delivery_terms')->nullable();
            $table->text('note')->nullable();

            // See SalesOrder::STATUSES.
            $table->string('status')->default('pending');

            $table->decimal('subtotal_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);

            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'order_date']);
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
