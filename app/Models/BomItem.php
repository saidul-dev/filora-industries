<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Bill of Materials line: `quantity` of `material` (in its own stock
 * unit) is consumed to produce one stock unit of `product`.
 */
class BomItem extends Model
{
    protected $fillable = [
        'product_id',
        'material_id',
        'quantity',
        'note',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_id');
    }
}
