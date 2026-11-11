<?php

namespace App\Models;

use App\Models\Concerns\DescribesOrderLine;
use App\Models\Concerns\ProducedOnProductionOrders;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProformaInvoiceItem extends Model
{
    use DescribesOrderLine;
    use ProducedOnProductionOrders {
        liveProductionOrder as ownLiveProductionOrder;
    }

    /**
     * Planned fulfillment path per PI line — a single PI can mix all three.
     */
    public const FULFILLMENTS = [
        'stock' => 'From Existing Stock',
        'purchase' => 'Direct Purchase',
        'production' => 'Factory Production',
    ];

    protected $fillable = [
        'proforma_invoice_id',
        'sales_order_item_id',
        'product_id',
        'product_variant_id',
        'style',
        'color',
        'length_per_unit',
        'remarks',
        'quantity',
        'unit_price',
        'subtotal',
        'fulfillment',
        'delivered_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'length_per_unit' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'delivered_quantity' => 'decimal:4',
    ];

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * Manufactured products are produced, everything else is bought —
     * the planner can still override either to "From Existing Stock".
     */
    public static function defaultFulfillmentFor(Product $product): string
    {
        return $product->isManufactured() ? 'production' : 'purchase';
    }

    /**
     * A line sits on one live Production Order at most — its own, or the
     * one already raised from the Sales Order line it came from (adopted
     * once the PI is issued).
     */
    public function liveProductionOrder(): ?ProductionOrder
    {
        return $this->ownLiveProductionOrder()
            ?? ($this->sales_order_item_id
                ? ProductionOrderItem::with('productionOrder')->where('sales_order_item_id', $this->sales_order_item_id)
                    ->whereHas('productionOrder', fn ($q) => $q->where('status', '!=', 'cancelled'))
                    ->first()?->productionOrder
                : null);
    }

    /**
     * The Sales Order line this PI line was generated from, if any.
     */
    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function getFulfillmentLabelAttribute(): string
    {
        return self::FULFILLMENTS[$this->fulfillment] ?? $this->fulfillment;
    }
}
