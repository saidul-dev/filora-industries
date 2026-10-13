<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    /**
     * How a product is replenished (see docs/order-to-delivery.md):
     * manufactured items are produced at the factory against their BOM,
     * direct-purchase items are bought ready-made, and raw materials are
     * the components a BOM consumes. A Proforma Invoice line defaults
     * its fulfillment path (production vs. purchase) from this.
     */
    public const TYPES = [
        'manufactured' => 'Manufactured Product',
        'direct_purchase' => 'Direct-Purchase Finished Product',
        'raw_material' => 'Raw Material / Component',
    ];

    protected $fillable = [
        'name',
        'sku',
        'barcode',
        'category_id',
        'product_type',
        'brand_id',
        'stock_unit_id',
        'purchase_unit_id',
        'purchase_unit_conversion',
        'sale_unit_id',
        'sale_unit_conversion',
        'description',
        'short_description',
        'estimated_cost',
        'selling_price',
        'reorder_level',
        'image_path',
        'status',
        'has_variants',
        'track_batch',
        'track_expiry',
        'track_serial',
        'warranty_period',
        'warranty_unit',
        'guarantee_period',
        'guarantee_unit',
    ];

    protected $casts = [
        'status' => 'boolean',
        // 4dp: a perpetual weighted-average, recalculated on every costed "in"
        // movement (see StockMovement::recalculateAverageCost()) — rounding
        // it to money precision here would drift qty × cost off the ledger's
        // true total. UI display still formats to 2dp.
        'estimated_cost' => 'decimal:4',
        'selling_price' => 'decimal:2',
        'purchase_unit_conversion' => 'decimal:4',
        'sale_unit_conversion' => 'decimal:4',
        'reorder_level' => 'integer',
        'has_variants' => 'boolean',
        'track_batch' => 'boolean',
        'track_expiry' => 'boolean',
        'track_serial' => 'boolean',
        'warranty_period' => 'integer',
        'guarantee_period' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The unit stock quantity is tracked in — the canonical unit.
     */
    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'stock_unit_id');
    }

    /**
     * Unit used when purchasing this product. Falls back to the stock
     * unit (1:1) when not set — see purchase_unit_conversion.
     */
    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    /**
     * Unit used when selling this product. Falls back to the stock
     * unit (1:1) when not set — see sale_unit_conversion.
     */
    public function saleUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'sale_unit_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /**
     * Extra gallery photos shown on the storefront product detail page —
     * Product::image_path/image_url stays the cover image used everywhere
     * else (admin list, storefront cards, related products).
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isVariable(): bool
    {
        return (bool) $this->has_variants;
    }

    /**
     * Bill of Materials — raw materials consumed per one stock unit.
     */
    public function bomItems(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }

    public function isManufactured(): bool
    {
        return $this->product_type === 'manufactured';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->product_type] ?? $this->product_type;
    }
}
