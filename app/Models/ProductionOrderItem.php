<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One manufactured line of a Sales Order / PI put into production under a
 * Production Order. The order's status applies to all of its lines.
 */
class ProductionOrderItem extends Model
{
    protected $fillable = [
        'production_order_id',
        'proforma_invoice_item_id',
        'sales_order_item_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'produced_quantity',
        'material_cost',
        'service_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'produced_quantity' => 'decimal:4',
        'material_cost' => 'decimal:2',
        'service_cost' => 'decimal:2',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function proformaInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceItem::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterial::class);
    }

    public function totalCost(): float
    {
        return round((float) $this->material_cost + (float) $this->service_cost, 2);
    }

    public function isLive(): bool
    {
        return $this->productionOrder->status !== 'cancelled';
    }

    /**
     * Output this line accounts for on its order / PI line — what was
     * actually produced once completed, otherwise what is planned.
     */
    public function outputQuantity(): float
    {
        return match ($this->productionOrder->status) {
            'completed' => (float) $this->produced_quantity,
            'cancelled' => 0.0,
            default => (float) $this->quantity,
        };
    }

    public function label(): string
    {
        return $this->product->name.($this->productVariant ? ' — '.$this->productVariant->label : '');
    }

    /**
     * Style / color of the order line it was raised for.
     */
    public function lineDetails(): ?string
    {
        $line = $this->proformaInvoiceItem ?? $this->salesOrderItem;

        return $line?->lineDetails() ?: null;
    }
}
