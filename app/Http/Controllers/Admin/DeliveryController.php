<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Delivery;
use App\Models\ProformaInvoice;
use App\Models\SalesOrder;
use App\Models\Site;
use App\Models\StockMovement;
use App\Services\LedgerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deliveries — against a Proforma Invoice, or straight against a confirmed
 * Sales Order that has no PI yet (a PI can be raised afterwards and picks
 * those challans up when issued). The finished goods can come from
 * existing stock, purchased finished goods or factory output, and leave
 * from whichever Site holds them, in as many lots as needed
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

        $deliveries = Delivery::with(['proformaInvoice.party', 'salesOrder.party', 'site'])
            ->withCount('items')
            ->where(fn ($query) => $query
                ->whereHas('proformaInvoice', fn ($pi) => $pi->visibleTo($user))
                ->orWhere(fn ($query) => $query->whereNull('proforma_invoice_id')
                    ->whereHas('salesOrder', fn ($so) => $so->visibleTo($user))))
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->when($q, fn ($query) => $query->where(fn ($query) => $query
                ->where('delivery_no', 'like', "%{$q}%")
                ->orWhereHas('proformaInvoice', fn ($pi) => $pi->where('pi_no', 'like', "%{$q}%"))
                ->orWhereHas('salesOrder', fn ($so) => $so->where('order_no', 'like', "%{$q}%"))))
            ->orderByDesc('delivery_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $sites = Site::where('status', true)->orderBy('name')->get();

        return view('admin.deliveries.index', compact('deliveries', 'sites', 'siteId', 'q'));
    }

    /**
     * Pick what goods go out against — a live PI, or a confirmed Sales
     * Order with no PI — and the Site they leave from. Every undelivered
     * line is listed with what that Site has on hand.
     */
    public function create(Request $request)
    {
        $user = Auth::user();
        $source = $request->get('source') === 'order' || $request->filled('sales_order_id') ? 'order' : 'pi';

        $invoices = ProformaInvoice::active()->visibleTo($user)->with('party')->orderByDesc('id')->get()
            ->map(fn ($pi) => (object) ['id' => $pi->id, 'name' => "{$pi->pi_no} — {$pi->party->name}"]);

        $orders = SalesOrder::whereIn('status', ['confirmed', 'partially_delivered'])->visibleTo($user)
            ->with('party')->orderByDesc('id')->get()
            ->map(fn ($so) => (object) ['id' => $so->id, 'name' => "{$so->order_no} — {$so->party->name}"]);

        $invoice = $source === 'pi' && $request->filled('proforma_invoice_id')
            ? ProformaInvoice::active()->visibleTo($user)->with(['party', 'items.product.stockUnit', 'items.productVariant.attributeValues'])
                ->find($request->integer('proforma_invoice_id'))
            : null;

        $order = $source === 'order' && $request->filled('sales_order_id')
            ? SalesOrder::whereIn('status', ['confirmed', 'partially_delivered'])->visibleTo($user)
                ->with(['party', 'items.product.stockUnit', 'items.productVariant.attributeValues'])
                ->find($request->integer('sales_order_id'))
            : null;

        $document = $invoice ?? $order;
        $openItems = $document ? $document->items->filter(fn ($item) => $item->remaining() > 0)->values() : collect();

        $sites = Site::where('status', true)->orderBy('name')->get();
        $site = $request->filled('site_id') ? $sites->firstWhere('id', $request->integer('site_id')) : null;

        if ($document && ! $site) {
            // Default to whichever site can cover the most of what's left.
            $site = $sites->sortByDesc(fn (Site $s) => $openItems->sum(
                fn ($item) => min($item->remaining(), $this->stockBalance($item->product_id, $item->product_variant_id, $s->id))
            ))->first();
        }

        $lines = $site
            ? $openItems->map(fn ($item) => (object) [
                'item' => $item,
                'remaining' => $item->remaining(),
                'available' => $this->stockBalance($item->product_id, $item->product_variant_id, $site->id),
            ])
            : collect();

        // Sales are priced in USD; offer the rate the last delivery used.
        $usdRate = Delivery::latest('id')->value('usd_rate');

        return view('admin.deliveries.create', compact('source', 'invoices', 'orders', 'invoice', 'order', 'document', 'sites', 'site', 'lines', 'usdRate'));
    }

    /**
     * Posts, atomically: one `sale` stock movement per line at the Site,
     * the delivered quantities on the PI and/or Sales Order lines, and one
     * ledger voucher for the challan — Dr Accounts Receivable / Cr Sales
     * Revenue at the PI (or order) price net of its discount, pro rata,
     * converted from USD to BDT at the challan's usd_rate, and Dr Cost of
     * Goods Sold / Cr Inventory at the goods' current average cost (already
     * BDT).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'proforma_invoice_id' => ['required_without:sales_order_id', 'nullable', 'integer', 'exists:proforma_invoices,id'],
            'sales_order_id' => ['required_without:proforma_invoice_id', 'nullable', 'integer', 'exists:sales_orders,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'delivery_date' => ['required', 'date'],
            'usd_rate' => ['required', 'numeric', 'gt:0'],
            'vehicle_no' => ['nullable', 'string', 'max:50'],
            'receiver_name' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = Auth::user();
        $siteId = (int) $validated['site_id'];

        if (! empty($validated['proforma_invoice_id'])) {
            $invoice = ProformaInvoice::with(['party', 'items.product', 'items.productVariant', 'items.salesOrderItem'])->findOrFail($validated['proforma_invoice_id']);
            abort_unless($invoice->isVisibleTo($user), 403);

            if (! $invoice->isActive()) {
                throw ValidationException::withMessages(['proforma_invoice_id' => "{$invoice->pi_no} is not open for delivery (status: {$invoice->status_label})."]);
            }

            $order = null;
            $document = $invoice;
            $netRate = 1 - $invoice->discountRate();
        } else {
            $order = SalesOrder::with(['party', 'items.product', 'items.productVariant'])->findOrFail($validated['sales_order_id']);
            abort_unless($order->isVisibleTo($user), 403);

            if (! $order->canDeliverDirectly()) {
                throw ValidationException::withMessages(['sales_order_id' => "{$order->order_no} can't be delivered directly (status: {$order->status_label})".($order->status === 'pi_generated' ? ' — deliver it against its Proforma Invoice.' : '.')]);
            }

            $invoice = null;
            $document = $order;
            $netRate = 1 - $order->discountRate();
        }

        $lines = $this->deliveryLines($document, $validated['items'], $siteId, $netRate, priceFromRequest: $order !== null);

        $delivery = DB::transaction(function () use ($validated, $invoice, $order, $document, $lines, $siteId) {
            $reference = $invoice ? $invoice->pi_no : $order->order_no;

            $delivery = Delivery::create([
                'delivery_no' => 'PENDING',
                'proforma_invoice_id' => $invoice?->id,
                'sales_order_id' => $order?->id,
                'site_id' => $siteId,
                'delivery_date' => $validated['delivery_date'],
                'usd_rate' => $validated['usd_rate'],
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
                // A PI line generated from an order line keeps both in step.
                $orderItem = $invoice ? $item->salesOrderItem : $item;

                $delivery->items()->create([
                    'proforma_invoice_item_id' => $invoice ? $item->id : null,
                    'sales_order_item_id' => $orderItem?->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'unit_cost' => $row['unit_cost'],
                ]);

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'site_id' => $siteId,
                    'type' => 'sale',
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'note' => "Delivered against {$reference}",
                    'moved_at' => $validated['delivery_date'],
                    'created_by' => Auth::id(),
                    'reference_type' => Delivery::class,
                    'reference_id' => $delivery->id,
                ]);

                $item->update(['delivered_quantity' => (float) $item->delivered_quantity + $row['quantity']]);

                if ($invoice && $orderItem) {
                    $orderItem->update(['delivered_quantity' => (float) $orderItem->delivered_quantity + $row['quantity']]);
                }

                $saleAmount += round($row['quantity'] * $row['unit_price'], 2);
                $costAmount += round($row['quantity'] * $row['unit_cost'], 2);
            }

            $saleAmountUsd = round($saleAmount, 2);
            $saleAmount = round($saleAmountUsd * (float) $validated['usd_rate'], 2);
            $costAmount = round($costAmount, 2);
            $delivery->update(['sale_amount_usd' => $saleAmountUsd, 'sale_amount' => $saleAmount, 'cost_amount' => $costAmount]);

            $ledgerLines = [];

            if ($saleAmount > 0) {
                $ledgerLines[] = ['account' => 'accounts_receivable', 'debit' => $saleAmount, 'party_id' => $document->party_id];
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
                    'site_id' => $siteId,
                    'narration' => "Delivery {$delivery->delivery_no} against {$reference} to {$document->party->name} (USD {$saleAmountUsd} @ {$validated['usd_rate']})",
                    'reference' => $delivery,
                    'created_by' => Auth::id(),
                    'lines' => $ledgerLines,
                ]);
            }

            $invoice ? $invoice->recalculateDeliveryStatus() : $order->recalculateStatus();

            return $delivery;
        });

        return redirect()->route('deliveries.show', $delivery)
            ->with('success', "Delivery {$delivery->delivery_no} posted against {$delivery->sourceNo()}.");
    }

    public function show(Delivery $delivery)
    {
        $delivery->load([
            'proformaInvoice.party', 'salesOrder.party', 'site', 'creator',
            'items.product.stockUnit', 'items.productVariant.attributeValues', 'items.proformaInvoiceItem', 'items.salesOrderItem',
            'ledgerTransaction.lines.account',
        ]);

        abort_unless($delivery->isVisibleTo(Auth::user()), 403);

        return view('admin.deliveries.show', compact('delivery'));
    }

    public function print(Delivery $delivery)
    {
        $delivery->load([
            'proformaInvoice.party', 'proformaInvoice.salesOrders', 'salesOrder.party', 'site', 'creator',
            'items.product.stockUnit', 'items.productVariant.attributeValues', 'items.proformaInvoiceItem', 'items.salesOrderItem',
        ]);

        abort_unless($delivery->isVisibleTo(Auth::user()), 403);

        $company = CompanySetting::current();

        return view('admin.deliveries.print', compact('delivery', 'company'));
    }

    /**
     * The submitted quantities checked against what's left on each line
     * and what the Site holds. A PI line is billed at the PI price; a
     * direct order line at the price typed on the challan (pre-filled from
     * the order), since the order may not carry one yet.
     *
     * @param  ProformaInvoice|SalesOrder  $document
     * @return array<int, array{item: Model, quantity: float, unit_price: float, unit_cost: float}>
     */
    protected function deliveryLines($document, array $rows, int $siteId, float $netRate, bool $priceFromRequest): array
    {
        $lines = [];

        foreach ($rows as $itemId => $row) {
            $quantity = (float) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $item = $document->items->firstWhere('id', (int) $itemId);

            if (! $item) {
                throw ValidationException::withMessages(["items.{$itemId}" => 'Invalid order line.']);
            }

            if ($quantity > $item->remaining() + 0.00001) {
                throw ValidationException::withMessages([
                    "items.{$itemId}.quantity" => "{$item->label()}: only {$item->remaining()} remains to be delivered.",
                ]);
            }

            $available = $this->stockBalance($item->product_id, $item->product_variant_id, $siteId);

            if ($quantity > $available + 0.00001) {
                throw ValidationException::withMessages([
                    "items.{$itemId}.quantity" => "{$item->label()}: only {$available} in stock at this site.",
                ]);
            }

            $price = $priceFromRequest ? ($row['unit_price'] ?? $item->unit_price) : $item->unit_price;

            if ($price === null) {
                throw ValidationException::withMessages([
                    "items.{$itemId}.unit_price" => "{$item->label()}: set a unit price to deliver it.",
                ]);
            }

            $lines[] = [
                'item' => $item,
                'quantity' => $quantity,
                'unit_price' => round((float) $price * $netRate, 4),
                'unit_cost' => round((float) ($item->productVariant?->estimated_cost ?? $item->product->estimated_cost), 4),
            ];
        }

        if (! $lines) {
            throw ValidationException::withMessages(['items' => 'Enter a delivery quantity for at least one item.']);
        }

        return $lines;
    }
}
