<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProductionOrder extends Model
{
    /**
     * Basic (Package A) production status — Not Started → In Progress →
     * Completed, with Cancelled as the exit before completion. Raw
     * materials are consumed the moment the order is issued; finished
     * goods enter stock on completion.
     */
    public const STATUSES = [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    public const STATUS_COLORS = [
        'not_started' => 'slate',
        'in_progress' => 'amber',
        'completed' => 'emerald',
        'cancelled' => 'rose',
    ];

    protected $fillable = [
        'production_no',
        'proforma_invoice_id',
        'proforma_invoice_item_id',
        'product_id',
        'product_variant_id',
        'site_id',
        'quantity',
        'produced_quantity',
        'material_cost',
        'status',
        'start_date',
        'due_date',
        'started_at',
        'completed_at',
        'note',
        'created_by',
        'completed_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'produced_quantity' => 'decimal:4',
        'material_cost' => 'decimal:2',
        'start_date' => 'date',
        'due_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function proformaInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceItem::class);
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

    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterial::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['not_started', 'in_progress'], true);
    }

    /**
     * Output quantity this order accounts for on its PI line — what it
     * actually produced once completed, otherwise what it's planned to.
     */
    public function outputQuantity(): float
    {
        return match ($this->status) {
            'completed' => (float) $this->produced_quantity,
            'cancelled' => 0.0,
            default => (float) $this->quantity,
        };
    }

    public function label(): string
    {
        return $this->product->name.($this->productVariant ? ' — '.$this->productVariant->label : '');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
