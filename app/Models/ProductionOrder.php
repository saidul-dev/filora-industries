<?php

namespace App\Models;

use App\Models\Concerns\SourcedFromSalesDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProductionOrder extends Model
{
    use SourcedFromSalesDocument;

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
        'sales_order_id',
        'site_id',
        'material_cost',
        'service_cost',
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
        'material_cost' => 'decimal:2',
        'service_cost' => 'decimal:2',
        'start_date' => 'date',
        'due_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    /**
     * The manufactured lines this order produces — one or several lines of
     * the same Sales Order / PI.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    /**
     * Third-party work (dyeing, printing…) billed against this order.
     */
    public function services(): HasMany
    {
        return $this->hasMany(ProductionOrderService::class);
    }

    /**
     * Materials consumed plus third-party work — what the finished goods cost.
     */
    public function totalCost(): float
    {
        return round((float) $this->material_cost + (float) $this->service_cost, 2);
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

    public function totalQuantity(): float
    {
        return (float) $this->items->sum('quantity');
    }

    public function totalProduced(): float
    {
        return (float) $this->items->sum('produced_quantity');
    }

    /**
     * "Metal Zipper 7\"" for one line, "Metal Zipper 7\" + 2 more" for several.
     */
    public function label(): string
    {
        $first = $this->items->first();

        if (! $first) {
            return '—';
        }

        return $first->label().($this->items->count() > 1 ? ' + '.($this->items->count() - 1).' '.__('more') : '');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
