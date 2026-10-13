<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * The "product-{id}" / "variant-{id}" item picker shared by the
 * order-to-delivery documents (Sales Order, Proforma Invoice, Requisition,
 * Purchase Order) — same value convention StockTransferController uses, so
 * a simple product and one variant of a variable product can sit side by
 * side in the same cart.
 */
trait PicksProducts
{
    /**
     * Every active simple product and active variant, optionally limited
     * to some Product::TYPES, as cart-builder options.
     *
     * @param  array<string>|null  $types
     */
    protected function productOptions(?array $types = null): Collection
    {
        $products = Product::with('stockUnit')
            ->where('status', true)->where('has_variants', false)
            ->when($types, fn ($q) => $q->whereIn('product_type', $types))
            ->orderBy('name')->get();

        $variants = ProductVariant::with(['product.stockUnit', 'attributeValues'])
            ->where('status', true)
            ->whereHas('product', fn ($q) => $q->where('status', true)->where('has_variants', true)
                ->when($types, fn ($q) => $q->whereIn('product_type', $types)))
            ->get();

        $options = $products->map(fn (Product $p) => [
            'id' => "product-{$p->id}",
            'name' => "{$p->name} ({$p->sku})",
            'unit' => $p->stockUnit?->short_name,
            'price' => (float) $p->selling_price,
            'cost' => round((float) $p->estimated_cost, 2),
            'type' => $p->product_type,
        ])->concat($variants->map(fn (ProductVariant $v) => [
            'id' => "variant-{$v->id}",
            'name' => "{$v->product->name} — {$v->label} ({$v->sku})",
            'unit' => $v->product->stockUnit?->short_name,
            'price' => (float) $v->selling_price,
            'cost' => round((float) ($v->estimated_cost ?? $v->product->estimated_cost), 2),
            'type' => $v->product->product_type,
        ]));

        return $options->sortBy('name')->values();
    }

    /**
     * Split a picker value back into its Product (and ProductVariant, for
     * a variant row). Returns [null, null] for anything unrecognised.
     *
     * @return array{0: ?Product, 1: ?ProductVariant}
     */
    protected function resolveItem(?string $item): array
    {
        if (! $item || ! str_contains($item, '-')) {
            return [null, null];
        }

        [$kind, $id] = explode('-', $item, 2);

        if ($kind === 'product') {
            return [Product::where('status', true)->where('has_variants', false)->find($id), null];
        }

        if ($kind === 'variant') {
            $variant = ProductVariant::with('product')->where('status', true)->find($id);

            return [$variant?->product, $variant];
        }

        return [null, null];
    }

    /**
     * A document line (any model with product/productVariant relations)
     * back into the cart-builder's row shape.
     */
    protected function itemRow($line, array $extra = []): array
    {
        return array_merge([
            'id' => $line->product_variant_id ? "variant-{$line->product_variant_id}" : "product-{$line->product_id}",
            'name' => $line->productVariant
                ? "{$line->product->name} — {$line->productVariant->label} ({$line->productVariant->sku})"
                : "{$line->product->name} ({$line->product->sku})",
            'unit' => $line->product->stockUnit?->short_name,
        ], $extra);
    }

    /**
     * On-hand balance — at one Site, or across every Site when $siteId is null.
     */
    protected function stockBalance(int $productId, ?int $variantId, ?int $siteId = null): float
    {
        $query = StockMovement::where('product_id', $productId)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId));
        $variantId ? $query->where('product_variant_id', $variantId) : $query->whereNull('product_variant_id');

        return (float) ($query->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as balance")
            ->value('balance') ?? 0);
    }
}
