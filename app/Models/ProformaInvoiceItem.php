<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProformaInvoiceItem extends Model
{
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

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    /**
     * How much of this line still has to be put into production: the
     * ordered quantity less what non-cancelled Production Orders already
     * produced or plan to, capped at what is still undelivered.
     */
    public function quantityToProduce(): float
    {
        $covered = $this->productionOrders->sum(fn (ProductionOrder $order) => $order->outputQuantity());

        return max(round(min($this->remaining(), (float) $this->quantity - $covered), 4), 0);
    }

    public function remaining(): float
    {
        return max(round((float) $this->quantity - (float) $this->delivered_quantity, 4), 0);
    }

    /**
     * "Style TS472302 · Color 0160 · 2000 m · Rush" — the thread details
     * that tell apart lines of the same product. Empty when there are none.
     */
    public function lineDetails(): string
    {
        return collect([
            $this->style ? "Style {$this->style}" : null,
            $this->color ? "Color {$this->color}" : null,
            $this->length_per_unit !== null ? rtrim(rtrim(number_format((float) $this->length_per_unit, 2), '0'), '.').' m' : null,
            $this->remarks,
        ])->filter()->implode(' · ');
    }

    public function label(): string
    {
        return $this->product->name.($this->productVariant ? ' — '.$this->productVariant->label : '');
    }

    public function getFulfillmentLabelAttribute(): string
    {
        return self::FULFILLMENTS[$this->fulfillment] ?? $this->fulfillment;
    }
}
