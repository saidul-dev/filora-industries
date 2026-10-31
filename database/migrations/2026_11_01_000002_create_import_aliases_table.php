<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spellings seen in imported order sheets, remembered when a user
        // matches them to a record by hand — see ImportAlias.
        Schema::create('import_aliases', function (Blueprint $table) {
            $table->id();
            $table->morphs('aliasable');
            $table->string('alias');
            $table->string('normalized');
            $table->timestamps();

            $table->unique(['aliasable_type', 'normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_aliases');
    }
};
