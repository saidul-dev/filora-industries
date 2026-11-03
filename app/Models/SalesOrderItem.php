<?php

namespace App\Models;

use App\Models\Concerns\DescribesOrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
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

    /**
     * Still to be put into production for this line: the undelivered
     * quantity less what non-cancelled Production Orders produced or plan to.
     */
    public function quantityToProduce(): float
    {
        $covered = $this->productionOrders->sum(fn (ProductionOrder $order) => $order->outputQuantity());

        return max(round(min($this->remaining(), (float) $this->quantity - $covered), 4), 0);
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
