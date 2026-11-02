<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    /**
     * Pending → Confirmed, then either straight to delivery (Partially
     * Delivered → Delivered) or onto a Proforma Invoice (PI Generated) —
     * a PI can also be raised after goods already went out. Cancelled is
     * the exit before anything happened. Only a Pending order is editable;
     * once on a PI, the PI's own status carries the order forward.
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'partially_delivered' => 'Partially Delivered',
        'delivered' => 'Delivered',
        'pi_generated' => 'PI Generated',
        'cancelled' => 'Cancelled',
    ];

    public const STATUS_COLORS = [
        'pending' => 'amber',
        'confirmed' => 'sky',
        'partially_delivered' => 'indigo',
        'delivered' => 'teal',
        'pi_generated' => 'emerald',
        'cancelled' => 'rose',
    ];

    /**
     * Confirmed orders not on a live PI — these can be delivered against
     * directly, or put on a new PI.
     */
    public const OPEN_STATUSES = ['confirmed', 'partially_delivered', 'delivered'];

    protected $fillable = [
        'order_no',
        'party_id',
        'sales_person_id',
        'order_date',
        'delivery_start_date',
        'delivery_date',
        'buyer_reference',
        'buyer_name',
        'delivery_terms',
        'note',
        'status',
        'subtotal_amount',
        'discount_amount',
        'total_amount',
        'confirmed_at',
        'confirmed_by',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'delivery_start_date' => 'date',
        'delivery_date' => 'date',
        'confirmed_at' => 'datetime',
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_person_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function proformaInvoices(): BelongsToMany
    {
        return $this->belongsToMany(ProformaInvoice::class)->withTimestamps();
    }

    /**
     * The live (non-cancelled) PI covering this order, if any — cancelling
     * a PI hands its orders back to Confirmed so a corrected one can be
     * generated.
     */
    public function liveProformaInvoice(): ?ProformaInvoice
    {
        return $this->proformaInvoices
            ->where('status', '!=', 'cancelled')
            ->sortByDesc('id')
            ->first();
    }

    /**
     * Challans posted straight against this order (before any PI). When a
     * PI covering the order is issued they move onto that PI too.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * Goods can go out straight against the order while it is confirmed
     * and not on a live PI — afterwards they go out against the PI.
     */
    public function canDeliverDirectly(): bool
    {
        return in_array($this->status, ['confirmed', 'partially_delivered'], true);
    }

    /**
     * Back to the status the order's deliveries and PIs say it should have
     * — after a direct delivery, or when a PI covering it is cancelled.
     */
    public function recalculateStatus(): void
    {
        $this->load(['items', 'proformaInvoices']);

        $status = match (true) {
            $this->liveProformaInvoice() !== null => 'pi_generated',
            $this->items->every(fn (SalesOrderItem $item) => $item->remaining() <= 0) => 'delivered',
            $this->items->contains(fn (SalesOrderItem $item) => (float) $item->delivered_quantity > 0) => 'partially_delivered',
            default => 'confirmed',
        };

        $this->update(['status' => $status]);
    }

    /**
     * Order-wide discount as a fraction of the priced subtotal — applied
     * pro rata to directly delivered lines, as a PI does.
     */
    public function discountRate(): float
    {
        $subtotal = (float) $this->subtotal_amount;

        return $subtotal > 0 ? (float) $this->discount_amount / $subtotal : 0.0;
    }

    /**
     * Lines entered without a price — the price is then set on the PI.
     */
    public function hasUnpricedLines(): bool
    {
        return $this->items->contains(fn (SalesOrderItem $item) => $item->unit_price === null);
    }

    /**
     * A Sales Person only ever sees the orders they're responsible for;
     * anyone who can approve sales (Admin) — or any other role with
     * sales.view, e.g. Accounts — sees every order.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->seesOnlyOwnSales()
            ? $query->where('sales_person_id', $user->id)
            : $query;
    }

    public function isVisibleTo(User $user): bool
    {
        return ! $user->seesOnlyOwnSales() || $this->sales_person_id === $user->id;
    }

    public function isEditable(): bool
    {
        return $this->status === 'pending';
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
