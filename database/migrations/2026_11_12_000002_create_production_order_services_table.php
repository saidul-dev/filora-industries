<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work done by a third party during production — dyeing, printing,
        // embroidery, washing… Each entry is billed by a supplier: it is
        // posted to the supplier's payable and added to the finished goods'
        // cost when the order completes.
        Schema::create('production_order_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            // Null = for the whole order (spread over its lines by quantity).
            $table->foreignId('production_order_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('service');
            $table->date('service_date');
            $table->decimal('quantity', 14, 4)->nullable();
            $table->decimal('rate', 14, 4)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('reference_no')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('service_cost', 14, 2)->default(0)->after('material_cost');
        });

        Schema::table('production_order_items', function (Blueprint $table) {
            $table->decimal('service_cost', 14, 2)->default(0)->after('material_cost');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_items', function (Blueprint $table) {
            $table->dropColumn('service_cost');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('service_cost');
        });

        Schema::dropIfExists('production_order_services');
    }
};
