<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    /**
     * Pending → Confirmed → PI Generated, with Cancelled as the only other
     * exit. Only a Pending order is editable (quantities, add/remove
     * lines); from PI Generated on, the Proforma Invoice's own status
     * carries the order forward.
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'pi_generated' => 'PI Generated',
        'cancelled' => 'Cancelled',
    ];

    public const STATUS_COLORS = [
        'pending' => 'amber',
        'confirmed' => 'sky',
        'pi_generated' => 'emerald',
        'cancelled' => 'rose',
    ];

    protected $fillable = [
        'order_no',
        'party_id',
        'sales_person_id',
        'order_date',
        'delivery_date',
        'buyer_reference',
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
