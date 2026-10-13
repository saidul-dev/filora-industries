<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Delivery;
use App\Models\ProformaInvoice;
use App\Models\Site;
use App\Models\StockMovement;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deliveries against a Proforma Invoice — the finished goods for a PI can
 * come from existing stock, purchased finished goods or factory output, and
 * leave from whichever Site holds them, in as many lots as needed
 * (docs/order-to-delivery.md).
 */
class DeliveryController extends Controller implements HasMiddleware
{
    use PicksProducts;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:sales.view', only: ['index', 'show', 'print']),
            new Middleware('permission:sales.deliver', only: ['create', 'store']),
        ];
    }

    public function index(Request $request)
    {
        $siteId = $request->filled('site_id') ? $request->integer('site_id') : null;
        $q = $request->get('q');
        $user = Auth::user();

        $deliveries = Delivery::with(['proformaInvoice.party', 'site'])
            ->withCount('items')
            ->whereHas('proformaInvoice', fn ($pi) => $pi->visibleTo($user))
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->when($q, fn ($query) => $query->where(fn ($query) => $query
                ->where('delivery_no', 'like', "%{$q}%")
                ->orWhereHas('proformaInvoice', fn ($pi) => $pi->where('pi_no', 'like', "%{$q}%"))))
            ->orderByDesc('delivery_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $sites = Site::where('status', true)->orderBy('name')->get();

        return view('admin.deliveries.index', compact('deliveries', 'sites', 'siteId', 'q'));
    }

    /**
     * Pick a live PI and the Site goods leave from — every undelivered line
     * is listed with what that Site has on hand.
     */
    public function create(Request $request)
    {
        $invoices = ProformaInvoice::active()->with('party')->orderByDesc('id')->get()
            ->map(fn ($pi) => (object) ['id' => $pi->id, 'name' => "{$pi->pi_no} — {$pi->party->name}"]);

        $invoice = $request->filled('proforma_invoice_id')
            ? ProformaInvoice::active()->with(['party', 'items.product.stockUnit', 'items.productVariant.attributeValues'])
                ->find($request->integer('proforma_invoice_id'))
            : null;

        $sites = Site::where('status', true)->orderBy('name')->get();
        $site = $request->filled('site_id') ? $sites->firstWhere('id', $request->integer('site_id')) : null;

        if ($invoice && ! $site) {
            // Default to whichever site can cover the most of what's left.
            $site = $sites->sortByDesc(fn (Site $s) => $invoice->items->sum(
                fn ($item) => min($item->remaining(), $this->stockBalance($item->product_id, $item->product_variant_id, $s->id))
            ))->first();
        }

        $lines = $invoice && $site
            ? $invoice->items->filter(fn ($item) => $item->remaining() > 0)->map(fn ($item) => (object) [
                'item' => $item,
                'remaining' => $item->remaining(),
                'available' => $this->stockBalance($item->product_id, $item->product_variant_id, $site->id),
            ])->values()
            : collect();

        return view('admin.deliveries.create', compact('invoices', 'invoice', 'sites', 'site', 'lines'));
    }

    /**
     * Posts, atomically: one `sale` stock movement per line at the Site,
     * the PI lines' delivered quantities, and one ledger voucher for the
     * challan — Dr Accounts Receivable / Cr Sales Revenue at the PI price
     * (net of the PI's discount, pro rata) and Dr Cost of Goods Sold /
     * Cr Inventory at the goods' current average cost.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'proforma_invoice_id' => ['required', 'integer', 'exists:proforma_invoices,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'delivery_date' => ['required', 'date'],
            'vehicle_no' => ['nullable', 'string', 'max:50'],
            'receiver_name' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        $invoice = ProformaInvoice::with(['party', 'items.product', 'items.productVariant'])->findOrFail($validated['proforma_invoice_id']);

        if (! $invoice->isActive()) {
            throw ValidationException::withMessages(['proforma_invoice_id' => "{$invoice->pi_no} is not open for delivery (status: {$invoice->status_label})."]);
        }

        $netRate = 1 - $invoice->discountRate();
        $lines = [];

        foreach ($validated['items'] as $itemId => $row) {
            $quantity = (float) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $item = $invoice->items->firstWhere('id', (int) $itemId);

            if (! $item) {
                throw ValidationException::withMessages(["items.{$itemId}" => 'Invalid PI line.']);
            }

            if ($quantity > $item->remaining() + 0.00001) {
                throw ValidationException::withMessages([
                    "items.{$itemId}.quantity" => "{$item->label()}: only {$item->remaining()} remains to be delivered.",
                ]);
            }

            $available = $this->stockBalance($item->product_id, $item->product_variant_id, (int) $validated['site_id']);

            if ($quantity > $available + 0.00001) {
                throw ValidationException::withMessages([
                    "items.{$itemId}.quantity" => "{$item->label()}: only {$available} in stock at this site.",
                ]);
            }

            $lines[] = [
                'item' => $item,
                'quantity' => $quantity,
                'unit_price' => round((float) $item->unit_price * $netRate, 4),
                'unit_cost' => round((float) ($item->productVariant?->estimated_cost ?? $item->product->estimated_cost), 4),
            ];
        }

        if (! $lines) {
            throw ValidationException::withMessages(['items' => 'Enter a delivery quantity for at least one item.']);
        }

        $delivery = DB::transaction(function () use ($validated, $invoice, $lines) {
            $delivery = Delivery::create([
                'delivery_no' => 'PENDING',
                'proforma_invoice_id' => $invoice->id,
                'site_id' => $validated['site_id'],
                'delivery_date' => $validated['delivery_date'],
                'vehicle_no' => $validated['vehicle_no'] ?? null,
                'receiver_name' => $validated['receiver_name'] ?? null,
                'note' => $validated['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $delivery->update(['delivery_no' => 'DC-'.str_pad($delivery->id, 6, '0', STR_PAD_LEFT)]);

            $saleAmount = 0.0;
            $costAmount = 0.0;

            foreach ($lines as $row) {
                $item = $row['item'];

                $delivery->items()->create([
                    'proforma_invoice_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'unit_cost' => $row['unit_cost'],
                ]);

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'site_id' => $validated['site_id'],
                    'type' => 'sale',
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'note' => "Delivered against {$invoice->pi_no}",
                    'moved_at' => $validated['delivery_date'],
                    'created_by' => Auth::id(),
                    'reference_type' => Delivery::class,
                    'reference_id' => $delivery->id,
                ]);

                $item->update(['delivered_quantity' => (float) $item->delivered_quantity + $row['quantity']]);

                $saleAmount += round($row['quantity'] * $row['unit_price'], 2);
                $costAmount += round($row['quantity'] * $row['unit_cost'], 2);
            }

            $saleAmount = round($saleAmount, 2);
            $costAmount = round($costAmount, 2);
            $delivery->update(['sale_amount' => $saleAmount, 'cost_amount' => $costAmount]);

            $ledgerLines = [];

            if ($saleAmount > 0) {
                $ledgerLines[] = ['account' => 'accounts_receivable', 'debit' => $saleAmount, 'party_id' => $invoice->party_id];
                $ledgerLines[] = ['account' => 'sales_revenue', 'credit' => $saleAmount];
            }

            if ($costAmount > 0) {
                $ledgerLines[] = ['account' => 'cost_of_goods_sold', 'debit' => $costAmount];
                $ledgerLines[] = ['account' => 'inventory', 'credit' => $costAmount];
            }

            if ($ledgerLines) {
                LedgerService::post([
                    'type' => 'sale',
                    'date' => $validated['delivery_date'],
                    'site_id' => $validated['site_id'],
                    'narration' => "Delivery {$delivery->delivery_no} against {$invoice->pi_no} to {$invoice->party->name}",
                    'reference' => $delivery,
                    'created_by' => Auth::id(),
                    'lines' => $ledgerLines,
                ]);
            }

            $invoice->recalculateDeliveryStatus();

            return $delivery;
        });

        return redirect()->route('deliveries.show', $delivery)
            ->with('success', "Delivery {$delivery->delivery_no} posted against {$invoice->pi_no}.");
    }

    public function show(Delivery $delivery)
    {
        $delivery->load(['proformaInvoice.party', 'site', 'creator', 'items.product.stockUnit', 'items.productVariant.attributeValues', 'ledgerTransaction.lines.account']);

        abort_unless($delivery->proformaInvoice->isVisibleTo(Auth::user()), 403);

        return view('admin.deliveries.show', compact('delivery'));
    }

    public function print(Delivery $delivery)
    {
        $delivery->load(['proformaInvoice.party', 'proformaInvoice.salesOrder', 'site', 'creator', 'items.product.stockUnit', 'items.productVariant.attributeValues']);

        abort_unless($delivery->proformaInvoice->isVisibleTo(Auth::user()), 403);

        $company = CompanySetting::current();

        return view('admin.deliveries.print', compact('delivery', 'company'));
    }
}
