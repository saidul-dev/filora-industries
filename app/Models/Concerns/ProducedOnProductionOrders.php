<?php

namespace App\Models\Concerns;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sales Order and Proforma Invoice lines put into production. A line sits
 * on one live (non-cancelled) Production Order at most; that order may
 * cover other lines of the same document too.
 */
trait ProducedOnProductionOrders
{
    public function productionItems(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    public function liveProductionOrder(): ?ProductionOrder
    {
        return $this->productionItems->first(fn (ProductionOrderItem $item) => $item->isLive())?->productionOrder;
    }

    /**
     * Still to be put into production: the undelivered quantity less what
     * non-cancelled Production Orders produced or plan to.
     */
    public function quantityToProduce(): float
    {
        $covered = $this->productionItems->sum(fn (ProductionOrderItem $item) => $item->outputQuantity());

        return max(round(min($this->remaining(), (float) $this->quantity - $covered), 4), 0);
    }
}
