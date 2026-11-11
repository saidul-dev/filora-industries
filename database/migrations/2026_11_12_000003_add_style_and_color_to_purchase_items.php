<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A PO raised from a Sales Order buys each style / color of a
        // product as its own line, so the supplier sees what to send.
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->string('style')->nullable()->after('product_variant_id');
            $table->string('color')->nullable()->after('style');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn(['style', 'color']);
        });
    }
};
