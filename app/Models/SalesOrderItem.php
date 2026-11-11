<?php

namespace App\Models;

use App\Models\Concerns\DescribesOrderLine;
use App\Models\Concerns\ProducedOnProductionOrders;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    use DescribesOrderLine;
    use ProducedOnProductionOrders;

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
        'fulfillment',
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

    /**
     * How the line will be fulfilled — as planned on the order, otherwise
     * the product type's default (production for Manufactured, purchase
     * for anything else). Uses ProformaInvoiceItem::FULFILLMENTS.
     */
    public function plannedFulfillment(): string
    {
        return $this->fulfillment ?? ProformaInvoiceItem::defaultFulfillmentFor($this->product);
    }

    public function getFulfillmentLabelAttribute(): string
    {
        return ProformaInvoiceItem::FULFILLMENTS[$this->plannedFulfillment()] ?? $this->plannedFulfillment();
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
