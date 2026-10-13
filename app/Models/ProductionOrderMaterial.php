<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderMaterial extends Model
{
    protected $fillable = [
        'production_order_id',
        'material_id',
        'quantity_per_unit',
        'quantity',
        'unit_cost',
    ];

    protected $casts = [
        'quantity_per_unit' => 'decimal:4',
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }
}
