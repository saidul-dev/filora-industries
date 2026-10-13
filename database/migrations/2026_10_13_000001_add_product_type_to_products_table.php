<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // How the item is replenished — see Product::TYPES. Decides
            // whether a PI line is fulfilled by Production (manufactured,
            // has a BOM) or by a direct Purchase, and which items can be
            // picked as BOM raw materials.
            $table->string('product_type')->default('direct_purchase')->after('category_id');
            $table->index('product_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['product_type']);
            $table->dropColumn('product_type');
        });
    }
};
