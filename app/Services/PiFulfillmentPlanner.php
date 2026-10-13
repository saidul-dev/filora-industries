<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProformaInvoice;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * Works out what a Proforma Invoice still has to buy, line by line, from
 * its fulfillment plan (docs/order-to-delivery.md):
 *
 *  - a "purchase" line needs its undelivered quantity as finished goods;
 *  - a "production" line needs its BOM raw materials for the quantity no
 *    Production Order has been issued for yet;
 *  - a "stock" line needs nothing bought.
 *
 * Demand is netted against what's on hand across every Site and what open
 * requisitions under the same PI are already sourcing, so the suggestion
 * is "what else to requisition", not the gross requirement.
 */
class PiFulfillmentPlanner
{
    /**
     * @return Collection<int, object{product: Product, variant: ?ProductVariant, quantity: float, demand: float, on_hand: float, requested: float, reason: string}>
     */
    public function purchaseSuggestions(ProformaInvoice $invoice): Collection
    {
        $invoice->loadMissing(['items.product.bomItems.material', 'items.productVariant.attributeValues', 'items.productionOrders']);

        $demand = [];

        foreach ($invoice->items as $line) {
            $remaining = $this->outstandingQuantity($line);

            if ($remaining <= 0) {
                continue;
            }

            if ($line->fulfillment === 'purchase') {
                $this->addDemand($demand, $line->product, $line->productVariant, $remaining, 'finished_goods');
            }

            if ($line->fulfillment === 'production') {
                foreach ($line->product->bomItems as $bom) {
                    $this->addDemand($demand, $bom->material, null, $remaining * (float) $bom->quantity, 'raw_material');
                }
            }
        }

        if (! $demand) {
            return collect();
        }

        $requested = $this->alreadyRequested($invoice);

        return collect($demand)->map(function ($row, $key) use ($requested) {
            $onHand = $this->onHand($row['product']->id, $row['variant']?->id);
            $alreadyRequested = (float) ($requested[$key] ?? 0);
            $quantity = round($row['quantity'] - $onHand - $alreadyRequested, 4);

            return (object) [
                'product' => $row['product'],
                'variant' => $row['variant'],
                'demand' => round($row['quantity'], 4),
                'on_hand' => $onHand,
                'requested' => $alreadyRequested,
                'quantity' => max($quantity, 0),
                'reason' => $row['reason'],
            ];
        })->filter(fn ($row) => $row->quantity > 0)->values();
    }

    /**
     * How much of a PI line still has to be sourced — its undelivered
     * quantity, or for a production line, what no Production Order has
     * covered yet (an issued order has already consumed its materials).
     */
    protected function outstandingQuantity($line): float
    {
        return $line->fulfillment === 'production' ? $line->quantityToProduce() : $line->remaining();
    }

    protected function addDemand(array &$demand, Product $product, ?ProductVariant $variant, float $quantity, string $reason): void
    {
        $key = $product->id.':'.($variant?->id ?? '');
        $demand[$key] ??= ['product' => $product, 'variant' => $variant, 'quantity' => 0.0, 'reason' => $reason];
        $demand[$key]['quantity'] += $quantity;
    }

    /**
     * @return array<string, float> keyed "productId:variantId"
     */
    protected function alreadyRequested(ProformaInvoice $invoice): array
    {
        return PurchaseRequisitionItem::query()
            ->whereHas('requisition', fn ($q) => $q->where('proforma_invoice_id', $invoice->id)
                ->whereIn('status', PurchaseRequisition::OPEN_STATUSES))
            ->get(['product_id', 'product_variant_id', 'quantity'])
            ->groupBy(fn ($item) => $item->product_id.':'.($item->product_variant_id ?? ''))
            ->map(fn ($items) => (float) $items->sum('quantity'))
            ->all();
    }

    protected function onHand(int $productId, ?int $variantId): float
    {
        $query = StockMovement::where('product_id', $productId);
        $variantId ? $query->where('product_variant_id', $variantId) : $query->whereNull('product_variant_id');

        return (float) ($query->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as balance")
            ->value('balance') ?? 0);
    }
}
