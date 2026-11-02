<?php

namespace App\Models;

use App\Models\Concerns\DescribesOrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    use DescribesOrderLine;

    protected $fillable = [
        'sales_order_id',
        'product_id',
        'product_variant_id',
        'style',
        'color',
        'length_per_unit',
        'remarks',
        'quantity',
        'unit_price',
        'subtotal',
        'delivered_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'length_per_unit' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'delivered_quantity' => 'decimal:4',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
