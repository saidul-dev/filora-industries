<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bill of Materials — one row per raw material/component consumed to
        // produce ONE stock unit of a manufactured product. Defined at the
        // product level, so every variant of a manufactured product shares
        // the same recipe.
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('products')->restrictOnDelete();

            // In the material's own stock unit, per one finished unit.
            $table->decimal('quantity', 14, 4);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
    }
};
