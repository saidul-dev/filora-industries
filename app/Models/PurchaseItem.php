<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_cost',
        'subtotal',
        'received_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'subtotal' => 'decimal:2',
        'received_quantity' => 'decimal:4',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function remaining(): float
    {
        return max(round((float) $this->quantity - (float) $this->received_quantity, 4), 0);
    }

    public function label(): string
    {
        return $this->product->name.($this->productVariant ? ' — '.$this->productVariant->label : '');
    }
}
