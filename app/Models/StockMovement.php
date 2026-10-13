<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    /**
     * Fixed movement types and their ledger direction. Direction is always
     * derived from this map (see booted()) — never set directly — so a row
     * can never disagree with its own type.
     */
    public const TYPES = [
        'initial_stock' => 'in',
        'purchase' => 'in',
        'production' => 'in',
        'transfer_in' => 'in',
        'adjustment_in' => 'in',
        'sales_return' => 'in',
        // Raw materials put back into stock when a Production Order is
        // cancelled before completion (reverses its production_consumption).
        'production_return' => 'in',
        'sale' => 'out',
        'transfer_out' => 'out',
        'adjustment_out' => 'out',
        'purchase_return' => 'out',
        'production_consumption' => 'out',
        'damage_expiry' => 'out',
        'sample' => 'out',
        'internal_consumption' => 'out',
    ];

    /**
     * Fixed reason list for adjustment_in/adjustment_out movements (the
     * Stock Adjustment screen). Damage/expiry write-offs use their own
     * `damage_expiry` type instead of an adjustment reason — see TYPES.
     */
    public const REASONS = [
        'physical_count' => 'Physical Count / Recount',
        'data_entry_error' => 'Data Entry Error',
        'found_stock' => 'Found Stock',
        'missing_stock' => 'Missing / Shortage',
        'other' => 'Other',
    ];

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'site_id',
        'type',
        'reason',
        'quantity',
        'unit_cost',
        'batch_no',
        'expiry_date',
        'serial_no',
        'note',
        'moved_at',
        'created_by',
        'reference_type',
        'reference_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'expiry_date' => 'date',
        'moved_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement) {
            $movement->direction = self::TYPES[$movement->type]
                ?? throw new \InvalidArgumentException("Unknown stock movement type [{$movement->type}].");
        });

        static::created(function (StockMovement $movement) {
            if ($movement->direction === 'in' && $movement->unit_cost !== null) {
                $movement->recalculateAverageCost();
            }
        });

        // The app never exposes a way to edit/delete a posted movement, but
        // if one is ever removed directly (DB tooling, a future admin
        // feature), the average cost must not stay stuck on stale history.
        static::deleted(function (StockMovement $movement) {
            if ($movement->direction === 'in' && $movement->unit_cost !== null) {
                $movement->recalculateAverageCost();
            }
        });
    }

    /**
     * Global (all-Sites), all-time weighted-average cost: SUM(qty × cost)
     * over every "in" movement that recorded a unit_cost, ÷ their total
     * qty — stored back onto the Product/ProductVariant so every screen
     * (Product page, Stock Report, Stock Adjustment) reads one trustworthy
     * number instead of a manually-typed static estimate. "out" movements
     * never change it — they're valued at whatever the average already is.
     * Movements with no recorded cost don't dilute it either.
     */
    public function recalculateAverageCost(): void
    {
        $query = static::query()
            ->where('direction', 'in')
            ->whereNotNull('unit_cost')
            ->where('product_id', $this->product_id);

        $this->product_variant_id
            ? $query->where('product_variant_id', $this->product_variant_id)
            : $query->whereNull('product_variant_id');

        $totals = $query->selectRaw('SUM(quantity * unit_cost) as cost_sum, SUM(quantity) as qty_sum')->first();

        if (! $totals || (float) $totals->qty_sum <= 0) {
            return;
        }

        $avgCost = round((float) $totals->cost_sum / (float) $totals->qty_sum, 4);
        $target = $this->product_variant_id ? $this->productVariant : $this->product;

        if ($target && (float) $target->estimated_cost !== $avgCost) {
            $target->estimated_cost = $avgCost;
            $target->saveQuietly();
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The ledger voucher this movement posted (currently only
     * internal_consumption does) — see InternalConsumptionController.
     */
    public function ledgerTransaction(): MorphOne
    {
        return $this->morphOne(LedgerTransaction::class, 'reference');
    }

    /**
     * Current on-hand quantity of a product (or one of its variants) at a
     * given site: SUM(qty) over "in" movements minus SUM(qty) over "out"
     * movements. Used by the POS terminal to block selling more than is
     * actually in stock.
     */
    public static function balanceFor(int $productId, ?int $productVariantId, int $siteId): float
    {
        $query = static::query()
            ->where('site_id', $siteId)
            ->where('product_id', $productId);

        $productVariantId
            ? $query->where('product_variant_id', $productVariantId)
            : $query->whereNull('product_variant_id');

        $balance = $query->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as balance")
            ->value('balance');

        return (float) ($balance ?? 0);
    }
}
