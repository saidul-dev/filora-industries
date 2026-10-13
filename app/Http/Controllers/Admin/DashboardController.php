<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection as CollectionModel;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProformaInvoice;
use App\Models\PurchaseRequisition;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;

/**
 * Owner's Daily Digest (README M9) — every stat here is scoped to the
 * user's Current Site (see SetCurrentSite middleware), except Collections
 * and Expenses: they carry no site_id (pure accounting entries, not tied
 * to a warehouse), so that side of the picture is always company-wide
 * regardless of the site selector.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $siteId = Auth::user()->current_site_id;

        $todayCollection = (float) CollectionModel::whereDate('collection_date', today())->sum('amount');
        $todayExpense = (float) Expense::whereDate('expense_date', today())->sum('amount');

        $totalReceivable = $this->accountBalance('accounts_receivable', $siteId);

        [$lowStockItems, $lowStockCount, $criticalCount] = $this->lowStock($siteId);

        [$chartLabels, $collectionSeries, $expenseSeries] = $this->weeklyChart();

        $topOverdueCustomers = Party::where('is_customer', true)->where('status', true)->get()
            ->map(fn (Party $party) => ['id' => $party->id, 'name' => $party->name, 'due' => $party->receivableBalance()])
            ->filter(fn (array $row) => $row['due'] > 0)
            ->sortByDesc('due')
            ->take(6)
            ->values();

        $pipeline = $this->orderPipeline();

        $recentCollections = CollectionModel::with('party')
            ->orderByDesc('collection_date')
            ->orderByDesc('id')
            ->take(5)
            ->get();

        return view('dashboard', compact(
            'todayCollection', 'todayExpense', 'totalReceivable',
            'lowStockItems', 'lowStockCount', 'criticalCount',
            'chartLabels', 'collectionSeries', 'expenseSeries',
            'topOverdueCustomers', 'recentCollections', 'pipeline',
        ));
    }

    /**
     * Signed balance of a system ledger account, optionally restricted to
     * one Site via the parent ledger_transactions row — mirrors
     * LedgerAccount::balance() but adds the site filter that method doesn't
     * have. Opening-balance postings carry no site_id, so they naturally
     * drop out once a specific Site is selected — acceptable since an
     * opening balance predates any site-level activity anyway.
     */
    protected function accountBalance(string $code, ?int $siteId): float
    {
        $account = LedgerAccount::where('code', $code)->first();

        if (! $account) {
            return 0.0;
        }

        $raw = (float) $account->lines()
            ->when($siteId, fn ($q) => $q->whereHas('transaction', fn ($qt) => $qt->where('site_id', $siteId)))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal');

        return $account->nature === 'credit' ? -$raw : $raw;
    }

    /**
     * Every simple product / variant tracked for reorder (reorder_level > 0)
     * whose current Site balance has dropped to or below that level — same
     * "balance <= reorder_level" convention as StockReportController. A
     * variant has no reorder_level of its own; it inherits its parent
     * product's.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: int, 2: int} [rows to display, total low-stock count, out-of-stock count within it]
     */
    protected function lowStock(?int $siteId): array
    {
        $products = Product::with('stockUnit')
            ->where('status', true)->where('has_variants', false)->where('reorder_level', '>', 0)->get();

        $variantProducts = Product::with(['stockUnit', 'variants.attributeValues'])
            ->where('status', true)->where('has_variants', true)->where('reorder_level', '>', 0)
            ->get()
            ->each(fn (Product $p) => $p->setRelation('variants', $p->variants->where('status', true)->values()));

        $productIds = $products->pluck('id');
        $variantIds = $variantProducts->flatMap(fn (Product $p) => $p->variants->pluck('id'));

        $productBalances = StockMovement::whereIn('product_id', $productIds)->whereNull('product_variant_id')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw("product_id, SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as balance")
            ->groupBy('product_id')->pluck('balance', 'product_id');

        $variantBalances = StockMovement::whereIn('product_variant_id', $variantIds)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw("product_variant_id, SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as balance")
            ->groupBy('product_variant_id')->pluck('balance', 'product_variant_id');

        $rows = collect();

        foreach ($products as $product) {
            $balance = (float) ($productBalances[$product->id] ?? 0);

            if ($balance <= $product->reorder_level) {
                $rows->push((object) [
                    'name' => $product->name,
                    'unit' => $product->stockUnit?->short_name,
                    'balance' => $balance,
                    'reorder_level' => $product->reorder_level,
                ]);
            }
        }

        foreach ($variantProducts as $product) {
            foreach ($product->variants as $variant) {
                $balance = (float) ($variantBalances[$variant->id] ?? 0);

                if ($balance <= $product->reorder_level) {
                    $rows->push((object) [
                        'name' => "{$product->name} — {$variant->label}",
                        'unit' => $product->stockUnit?->short_name,
                        'balance' => $balance,
                        'reorder_level' => $product->reorder_level,
                    ]);
                }
            }
        }

        $rows = $rows->sortBy('balance')->values();

        return [$rows->take(4), $rows->count(), $rows->where('balance', '<=', 0)->count()];
    }

    /**
     * Last 7 days (today inclusive) of Collections received vs Expenses
     * paid, for the "Collection vs Expense" chart. Both are company-wide
     * (no site_id), so there's no site filter to apply here.
     *
     * @return array{0: array<string>, 1: array<float>, 2: array<float>}
     */
    protected function weeklyChart(): array
    {
        $labels = [];
        $collectionSeries = [];
        $expenseSeries = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $labels[] = $date->format('d M');

            $collectionSeries[] = (float) CollectionModel::whereDate('collection_date', $date)->sum('amount');
            $expenseSeries[] = (float) Expense::whereDate('expense_date', $date)->sum('amount');
        }

        return [$labels, $collectionSeries, $expenseSeries];
    }

    /**
     * Order-to-delivery pipeline (docs/order-to-delivery.md) — only the
     * stages the user's permissions let them act on, each linking to its
     * filtered list. Company-wide, like the documents themselves.
     *
     * @return array<int, array{label: string, value: int, url: string, alert: bool}>
     */
    protected function orderPipeline(): array
    {
        $user = Auth::user();
        $tiles = [];

        if ($user->can('sales.view')) {
            $tiles[] = [
                'label' => __('Sales Orders Pending'),
                'value' => SalesOrder::visibleTo($user)->where('status', 'pending')->count(),
                'url' => route('sales-orders.index', ['status' => 'pending']),
                'alert' => false,
            ];
            $tiles[] = [
                'label' => __('Open Proforma Invoices'),
                'value' => ProformaInvoice::visibleTo($user)->active()->count(),
                'url' => route('proforma-invoices.index'),
                'alert' => false,
            ];
            $tiles[] = [
                'label' => __('LCs Expiring in 15 Days'),
                'value' => $expiring = ProformaInvoice::visibleTo($user)->active()
                    ->whereBetween('lc_expiry_date', [today(), today()->addDays(15)])->count(),
                'url' => route('proforma-invoices.index', ['status' => 'lc_issued']),
                'alert' => $expiring > 0,
            ];
            $tiles[] = [
                'label' => __('Ready for Delivery'),
                'value' => ProformaInvoice::visibleTo($user)->where('status', 'ready')->count(),
                'url' => route('proforma-invoices.index', ['status' => 'ready']),
                'alert' => false,
            ];
        }

        if ($user->can('requisitions.view')) {
            $tiles[] = [
                'label' => __('Requisitions Awaiting Approval'),
                'value' => $pending = PurchaseRequisition::where('status', 'pending')->count(),
                'url' => route('purchase-requisitions.index', ['status' => 'pending']),
                'alert' => $pending > 0 && $user->can('requisitions.approve'),
            ];
        }

        if ($user->can('purchases.view')) {
            $tiles[] = [
                'label' => __('Approved, Not Yet Ordered'),
                'value' => PurchaseRequisition::where('status', 'approved')->count(),
                'url' => route('purchase-requisitions.index', ['status' => 'approved']),
                'alert' => false,
            ];
        }

        if ($user->can('production.view')) {
            $tiles[] = [
                'label' => __('Production Running'),
                'value' => ProductionOrder::whereIn('status', ['not_started', 'in_progress'])->count(),
                'url' => route('production-orders.index', ['status' => 'in_progress']),
                'alert' => false,
            ];
            $tiles[] = [
                'label' => __('Production Overdue'),
                'value' => $overdue = ProductionOrder::whereIn('status', ['not_started', 'in_progress'])
                    ->whereDate('due_date', '<', today())->count(),
                'url' => route('production-orders.index'),
                'alert' => $overdue > 0,
            ];
        }

        return $tiles;
    }
}
