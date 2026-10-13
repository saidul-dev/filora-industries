<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The controlling document for everything after a Sales Order is confirmed
 * — see docs/order-to-delivery.md.
 */
class ProformaInvoice extends Model
{
    /**
     * Draft (editable) → Issued → LC Issued → In Procurement/Production →
     * Ready for Delivery → Partially Delivered → Delivered → Closed, with
     * Cancelled as the exit for a PI nothing has happened against yet.
     * "In Procurement/Production" is entered automatically by the first
     * requisition or production order raised under the PI, and the two
     * delivery statuses by the deliveries posted against it.
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'issued' => 'Issued',
        'lc_issued' => 'LC Issued',
        'in_progress' => 'In Procurement/Production',
        'ready' => 'Ready for Delivery',
        'partially_delivered' => 'Partially Delivered',
        'delivered' => 'Delivered',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    public const STATUS_COLORS = [
        'draft' => 'slate',
        'issued' => 'sky',
        'lc_issued' => 'violet',
        'in_progress' => 'amber',
        'ready' => 'teal',
        'partially_delivered' => 'indigo',
        'delivered' => 'emerald',
        'closed' => 'slate',
        'cancelled' => 'rose',
    ];

    /**
     * Statuses in which the PI is live: requisitions and production orders
     * can be raised under it, and goods can be delivered against it.
     */
    public const ACTIVE_STATUSES = ['issued', 'lc_issued', 'in_progress', 'ready', 'partially_delivered'];

    protected $fillable = [
        'pi_no',
        'sales_order_id',
        'party_id',
        'sales_person_id',
        'pi_date',
        'valid_until',
        'delivery_date',
        'status',
        'subtotal_amount',
        'discount_amount',
        'total_amount',
        'terms',
        'note',
        'lc_no',
        'lc_bank',
        'lc_date',
        'lc_expiry_date',
        'lc_amount',
        'lc_file_path',
        'lc_file_name',
        'lc_attached_at',
        'issued_at',
        'closed_at',
        'created_by',
    ];

    protected $casts = [
        'pi_date' => 'date',
        'valid_until' => 'date',
        'delivery_date' => 'date',
        'lc_date' => 'date',
        'lc_expiry_date' => 'date',
        'lc_attached_at' => 'datetime',
        'issued_at' => 'datetime',
        'closed_at' => 'datetime',
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'lc_amount' => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

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
        return $this->hasMany(ProformaInvoiceItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(PurchaseRequisition::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->seesOnlyOwnSales()
            ? $query->where('sales_person_id', $user->id)
            : $query;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isVisibleTo(User $user): bool
    {
        return ! $user->seesOnlyOwnSales() || $this->sales_person_id === $user->id;
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function hasLc(): bool
    {
        return $this->lc_no !== null;
    }

    /**
     * The first requisition/production order raised under an Issued (or LC
     * Issued) PI moves it into "In Procurement/Production". Later statuses
     * are never pulled back.
     */
    public function markInProgress(): void
    {
        if (in_array($this->status, ['issued', 'lc_issued'], true)) {
            $this->update(['status' => 'in_progress']);
        }
    }

    /**
     * Order-wide discount as a fraction of the subtotal — applied pro rata
     * to every delivered line so delivered revenue adds up to the PI total.
     */
    public function discountRate(): float
    {
        $subtotal = (float) $this->subtotal_amount;

        return $subtotal > 0 ? (float) $this->discount_amount / $subtotal : 0.0;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
