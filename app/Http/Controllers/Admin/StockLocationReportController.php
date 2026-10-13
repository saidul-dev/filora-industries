<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Real-time stock for raw materials and finished goods split by location
 * (Factory / Office / any other Site) side by side, with the consolidated
 * total and its value at average cost — the Stock Report shows one Site at
 * a time, this shows them all at once. Balances come straight from the
 * stock_movements ledger, same as everywhere else.
 */
class StockLocationReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:inventory.view'),
        ];
    }

    public function index(Request $request)
    {
        $sites = Site::where('status', true)->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $type = array_key_exists($request->get('type'), Product::TYPES) ? $request->get('type') : null;
        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;
        $q = $request->get('q');
        $showAll = $request->boolean('show_all');

        $productQuery = fn ($query) => $query->where('status', true)
            ->when($type, fn ($q2) => $q2->where('product_type', $type))
            ->when($categoryId, fn ($q2) => $q2->where('category_id', $categoryId));

        $simple = Product::with('stockUnit')->where('has_variants', false)->tap($productQuery)
            ->when($q, fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")))
            ->get()
            ->map(fn (Product $p) => (object) [
                'key' => "p{$p->id}",
                'name' => $p->name,
                'sku' => $p->sku,
                'type' => $p->product_type,
                'unit' => $p->stockUnit?->short_name,
                'cost' => (float) $p->estimated_cost,
            ]);

        $variants = ProductVariant::with(['product.stockUnit', 'attributeValues'])
            ->where('status', true)
            ->whereHas('product', fn ($query) => $productQuery($query->where('has_variants', true)))
            ->get()
            ->filter(fn (ProductVariant $v) => ! $q
                || str_contains(strtolower($v->product->name), strtolower($q))
                || str_contains(strtolower($v->sku), strtolower($q)))
            ->map(fn (ProductVariant $v) => (object) [
                'key' => "v{$v->id}",
                'name' => "{$v->product->name} — {$v->label}",
                'sku' => $v->sku,
                'type' => $v->product->product_type,
                'unit' => $v->product->stockUnit?->short_name,
                'cost' => (float) ($v->estimated_cost ?? $v->product->estimated_cost),
            ]);

        // One grouped query for every balance, keyed "p{product}" / "v{variant}".
        $balances = StockMovement::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->selectRaw("site_id, product_id, product_variant_id, SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as balance")
            ->groupBy('site_id', 'product_id', 'product_variant_id')
            ->get()
            ->groupBy(fn ($row) => $row->product_variant_id ? "v{$row->product_variant_id}" : "p{$row->product_id}")
            ->map(fn ($rows) => $rows->pluck('balance', 'site_id')->map(fn ($b) => (float) $b));

        $rows = $simple->concat($variants)->map(function ($row) use ($balances, $sites) {
            $bySite = $balances[$row->key] ?? collect();
            $row->sites = $sites->mapWithKeys(fn (Site $site) => [$site->id => (float) ($bySite[$site->id] ?? 0)]);
            $row->total = (float) $row->sites->sum();
            $row->value = $row->total * $row->cost;

            return $row;
        })
            ->when(! $showAll, fn ($rows) => $rows->filter(fn ($row) => abs($row->total) > 0.00001))
            ->sortBy('name')
            ->values();

        $siteValues = $sites->mapWithKeys(fn (Site $site) => [
            $site->id => $rows->sum(fn ($row) => $row->sites[$site->id] * $row->cost),
        ]);
        $totalValue = (float) $rows->sum('value');

        $perPage = 25;
        $page = max(1, $request->integer('page', 1));
        $paginated = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.stock.locations', [
            'rows' => $paginated,
            'sites' => $sites,
            'categories' => $categories,
            'type' => $type,
            'categoryId' => $categoryId,
            'q' => $q,
            'showAll' => $showAll,
            'siteValues' => $siteValues,
            'totalValue' => $totalValue,
        ]);
    }
}
