<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Concerns\WritesSalesOrders;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\ProformaInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sales Orders as received from buyers — step one of the order-to-delivery
 * cycle (docs/order-to-delivery.md). Freely editable while Pending,
 * locked once Confirmed, then turned into a Proforma Invoice. Never posts
 * stock or ledger entries itself.
 */
class SalesOrderController extends Controller implements HasMiddleware
{
    use PicksProducts;
    use WritesSalesOrders;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:sales.view', only: ['index', 'show', 'print']),
            new Middleware('permission:sales.create', only: ['create', 'store']),
            new Middleware('permission:sales.edit', only: ['edit', 'update', 'plan', 'split']),
            new Middleware('permission:sales.delete', only: ['destroy']),
            new Middleware('permission:sales.approve', only: ['confirm', 'cancel']),
        ];
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $status = array_key_exists($request->get('status'), SalesOrder::STATUSES) ? $request->get('status') : null;
        $partyId = $request->filled('party_id') ? $request->integer('party_id') : null;
        $q = $request->get('q');

        $orders = SalesOrder::with(['party', 'salesPerson'])
            ->withCount('items')
            ->visibleTo($user)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($partyId, fn ($query) => $query->where('party_id', $partyId))
            ->when($q, fn ($query) => $query->where(fn ($query) => $query
                ->where('order_no', 'like', "%{$q}%")
                ->orWhere('buyer_reference', 'like', "%{$q}%")))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $customers = $this->customers();

        return view('admin.sales-orders.index', compact('orders', 'customers', 'status', 'partyId', 'q'));
    }

    public function create()
    {
        return view('admin.sales-orders.create', $this->formOptions() + ['items' => collect()]);
    }

    public function store(Request $request)
    {
        [$validated, $rows, $subtotal, $discount] = $this->validateOrder($request);

        $order = $this->createSalesOrder($this->headerAttributes($validated), $rows, $discount);

        return redirect()->route('sales-orders.show', $order)
            ->with('success', "Sales Order {$order->order_no} created — ".count($rows).' item(s).');
    }

    public function show(SalesOrder $salesOrder)
    {
        $this->authorizeVisible($salesOrder);

        $salesOrder->load([
            'party', 'salesPerson', 'creator', 'confirmedBy', 'proformaInvoices',
            'items.product.stockUnit', 'items.productVariant.attributeValues',
            'deliveries' => fn ($q) => $q->with(['site', 'proformaInvoice'])->withCount('items')->latest('id'),
            'items.productionItems.productionOrder', 'purchases.items',
            'productionOrders' => fn ($q) => $q->with(['site', 'items.product.stockUnit', 'items.productVariant.attributeValues'])->latest('id'),
        ]);

        // Keep the parts of a split line together: each line sits right
        // after the first line of the same product / style / color / size.
        $key = fn ($item) => implode('|', [$item->product_id, $item->product_variant_id, $item->style, $item->color, $item->length_per_unit]);
        $firstId = $salesOrder->items->sortBy('id')->groupBy($key)->map(fn ($group) => $group->min('id'));
        $salesOrder->setRelation('items', $salesOrder->items
            ->sortBy([fn ($a, $b) => $firstId[$key($a)] <=> $firstId[$key($b)], fn ($a, $b) => $a->id <=> $b->id])
            ->values());

        return view('admin.sales-orders.show', ['order' => $salesOrder]);
    }

    public function print(SalesOrder $salesOrder)
    {
        $this->authorizeVisible($salesOrder);

        $salesOrder->load(['party', 'items.product.stockUnit', 'items.productVariant.attributeValues']);
        $company = CompanySetting::current();

        return view('admin.sales-orders.print', ['order' => $salesOrder, 'company' => $company]);
    }

    public function edit(SalesOrder $salesOrder)
    {
        $this->authorizeVisible($salesOrder);

        if (! $salesOrder->isEditable()) {
            return redirect()->route('sales-orders.show', $salesOrder)->with('error', 'Only a pending Sales Order can be edited.');
        }

        $salesOrder->load(['items.product.stockUnit', 'items.productVariant.attributeValues']);

        $items = $salesOrder->items->map(fn ($item) => $this->itemRow($item, [
            'quantity' => (float) $item->quantity,
            'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : '',
            'style' => $item->style ?? '',
            'color' => $item->color ?? '',
            'length_per_unit' => $item->length_per_unit !== null ? (float) $item->length_per_unit : '',
            'remarks' => $item->remarks ?? '',
        ]))->values();

        return view('admin.sales-orders.edit', $this->formOptions() + ['order' => $salesOrder, 'items' => $items]);
    }

    /**
     * Replaces every line wholesale — safe because only a pending order is
     * editable, and nothing downstream references a pending order's lines.
     */
    public function update(Request $request, SalesOrder $salesOrder)
    {
        $this->authorizeVisible($salesOrder);

        if (! $salesOrder->isEditable()) {
            return back()->with('error', 'Only a pending Sales Order can be edited.');
        }

        [$validated, $rows, $subtotal, $discount] = $this->validateOrder($request);

        DB::transaction(function () use ($salesOrder, $validated, $rows, $subtotal, $discount) {
            $salesOrder->update($this->headerAttributes($validated, $salesOrder) + [
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $subtotal - $discount,
            ]);

            $salesOrder->items()->delete();
            $this->createItems($salesOrder, $rows);
        });

        return redirect()->route('sales-orders.show', $salesOrder)->with('success', "Sales Order {$salesOrder->order_no} updated.");
    }

    public function destroy(SalesOrder $salesOrder)
    {
        $this->authorizeVisible($salesOrder);

        if (! $salesOrder->isEditable()) {
            return back()->with('error', 'Only a pending Sales Order can be deleted — cancel it instead.');
        }

        $salesOrder->delete();

        return redirect()->route('sales-orders.index')->with('success', "Sales Order {$salesOrder->order_no} deleted.");
    }

    public function confirm(SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'pending') {
            return back()->with('error', 'Only a pending Sales Order can be confirmed.');
        }

        $salesOrder->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by' => Auth::id(),
        ]);

        return back()->with('success', "Sales Order {$salesOrder->order_no} confirmed — it can now be turned into a Proforma Invoice.");
    }

    /**
     * How each line of a confirmed order will be fulfilled — from stock, a
     * direct purchase or factory production. Drives the order's own
     * "Produce" / "Create Purchase Order" actions, and is copied onto a PI
     * generated from the order.
     */
    public function plan(Request $request, SalesOrder $salesOrder)
    {
        $this->authorizeVisible($salesOrder);

        if (! $salesOrder->canSourceDirectly()) {
            return back()->with('error', 'Only a confirmed order not yet on a Proforma Invoice can be planned here — plan it on its PI instead.');
        }

        $validated = $request->validate([
            'fulfillment' => ['required', 'array'],
            'fulfillment.*' => ['required', Rule::in(array_keys(ProformaInvoiceItem::FULFILLMENTS))],
        ]);

        $salesOrder->load('items.product');

        foreach ($salesOrder->items as $item) {
            $plan = $validated['fulfillment'][$item->id] ?? null;

            if (! $plan) {
                continue;
            }

            if ($plan === 'production' && ! $item->product->isManufactured()) {
                throw ValidationException::withMessages(["fulfillment.{$item->id}" => "{$item->product->name} is not a Manufactured product — it can't be fulfilled by factory production."]);
            }

            $item->update(['fulfillment' => $plan]);
        }

        return back()->with('success', "Fulfillment plan for {$salesOrder->order_no} saved.");
    }

    /**
     * Split a line in two so part of it can be fulfilled another way — e.g.
     * 300 produced and 200 bought. The new line copies style, color, price
     * and remarks; the order total stays the same (bar cent rounding).
     * Only for a line nothing has happened to yet.
     */
    public function split(Request $request, SalesOrder $salesOrder, SalesOrderItem $item)
    {
        $this->authorizeVisible($salesOrder);
        abort_unless($item->sales_order_id === $salesOrder->id, 404);

        if (! $salesOrder->canSourceDirectly()) {
            return back()->with('error', 'Only a confirmed order not yet on a Proforma Invoice can have its lines split.');
        }

        $item->load(['product', 'productionItems.productionOrder']);

        if ((float) $item->delivered_quantity > 0) {
            return back()->with('error', "{$item->label()} already has deliveries — it can't be split.");
        }
        if ($live = $item->liveProductionOrder()) {
            return back()->with('error', "{$item->label()} is on Production Order {$live->production_no} — cancel it first to split the line.");
        }

        $quantity = (float) $item->quantity;
        $validated = $request->validateWithBag('split', [
            'split_quantity' => ['required', 'numeric', 'gt:0', 'lt:'.$quantity],
            'split_fulfillment' => ['required', Rule::in(array_keys(ProformaInvoiceItem::FULFILLMENTS))],
        ], [
            'split_quantity.lt' => 'Split off less than the whole line ('.rtrim(rtrim(number_format($quantity, 4), '0'), '.').').',
        ]);

        if ($validated['split_fulfillment'] === 'production' && ! $item->product->isManufactured()) {
            throw ValidationException::withMessages(['split_fulfillment' => "{$item->product->name} is not a Manufactured product — it can't be produced."])
                ->errorBag('split');
        }

        $splitQty = round((float) $validated['split_quantity'], 4);
        $keepQty = round($quantity - $splitQty, 4);
        $price = $item->unit_price;
        $subtotalFor = fn (float $qty) => $price === null ? 0 : round($qty * (float) $price, 2);

        $new = DB::transaction(function () use ($salesOrder, $item, $splitQty, $keepQty, $subtotalFor, $validated) {
            // The line being split keeps its plan; pin it so it doesn't drift with the default.
            $item->update([
                'quantity' => $keepQty,
                'subtotal' => $subtotalFor($keepQty),
                'fulfillment' => $item->plannedFulfillment(),
            ]);

            $new = $salesOrder->items()->create([
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'style' => $item->style,
                'color' => $item->color,
                'length_per_unit' => $item->length_per_unit,
                'remarks' => $item->remarks,
                'quantity' => $splitQty,
                'unit_price' => $item->unit_price,
                'subtotal' => $subtotalFor($splitQty),
                'delivered_quantity' => 0,
                'fulfillment' => $validated['split_fulfillment'],
            ]);

            $subtotal = round((float) $salesOrder->items()->sum('subtotal'), 2);
            $salesOrder->update([
                'subtotal_amount' => $subtotal,
                'total_amount' => round($subtotal - (float) $salesOrder->discount_amount, 2),
            ]);

            return $new;
        });

        $qty = fn ($v) => rtrim(rtrim(number_format($v, 4), '0'), '.');

        return back()->with('success', "{$item->label()} split: {$qty($keepQty)} by ".strtolower(ProformaInvoiceItem::FULFILLMENTS[$item->fulfillment])
            ." and {$qty($splitQty)} by ".strtolower(ProformaInvoiceItem::FULFILLMENTS[$new->fulfillment]).'.');
    }

    public function cancel(SalesOrder $salesOrder)
    {
        if (! in_array($salesOrder->status, ['pending', 'confirmed'], true)) {
            return back()->with('error', 'This Sales Order can no longer be cancelled.');
        }

        $salesOrder->update(['status' => 'cancelled']);

        return back()->with('success', "Sales Order {$salesOrder->order_no} cancelled.");
    }

    protected function authorizeVisible(SalesOrder $order): void
    {
        abort_unless($order->isVisibleTo(Auth::user()), 403);
    }

    protected function formOptions(): array
    {
        return [
            'customers' => $this->customers(),
            'itemOptions' => $this->productOptions(['manufactured', 'direct_purchase']),
            // Only someone who sees every order picks the responsible Sales
            // Person — a Sales Person always enters orders as themselves.
            'salesPeople' => $this->salesPersonOptions(),
        ];
    }

    protected function customers()
    {
        return Party::where('is_customer', true)->where('status', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return array{0: array, 1: array, 2: float, 3: float}
     */
    protected function validateOrder(Request $request): array
    {
        $validated = $request->validate([
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'sales_person_id' => ['nullable', 'integer', 'exists:users,id'],
            'order_date' => ['required', 'date'],
            'delivery_start_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'buyer_reference' => ['nullable', 'string', 'max:100'],
            'buyer_name' => ['nullable', 'string', 'max:150'],
            'delivery_terms' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            // The same product repeats across lines with different styles
            // and colors, so no "distinct" rule here.
            'items.*.item' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            // Optional — the price can be agreed later and set on the PI.
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.style' => ['nullable', 'string', 'max:100'],
            'items.*.color' => ['nullable', 'string', 'max:100'],
            'items.*.length_per_unit' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        if (! Party::whereKey($validated['party_id'])->where('is_customer', true)->exists()) {
            throw ValidationException::withMessages(['party_id' => 'Pick a party marked as a Customer.']);
        }

        $rows = [];
        foreach ($validated['items'] as $i => $row) {
            [$product, $variant] = $this->resolveItem($row['item']);

            if (! $product) {
                throw ValidationException::withMessages(["items.{$i}.item" => 'Pick a valid product or variant.']);
            }

            $rows[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => (float) $row['quantity'],
                'unit_price' => isset($row['unit_price']) ? (float) $row['unit_price'] : null,
                'subtotal' => round((float) $row['quantity'] * (float) ($row['unit_price'] ?? 0), 2),
                'style' => $row['style'] ?? null,
                'color' => $row['color'] ?? null,
                'length_per_unit' => isset($row['length_per_unit']) ? (float) $row['length_per_unit'] : null,
                'remarks' => $row['remarks'] ?? null,
            ];
        }

        $subtotal = round(array_sum(array_column($rows, 'subtotal')), 2);
        $discount = round((float) ($validated['discount'] ?? 0), 2);

        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the order subtotal.']);
        }

        return [$validated, $rows, $subtotal, $discount];
    }

    protected function headerAttributes(array $validated, ?SalesOrder $existing = null): array
    {
        return [
            'party_id' => $validated['party_id'],
            'sales_person_id' => $this->resolveSalesPerson($validated, $existing),
            'order_date' => $validated['order_date'],
            'delivery_start_date' => $validated['delivery_start_date'] ?? null,
            'delivery_date' => $validated['delivery_date'] ?? null,
            'buyer_reference' => $validated['buyer_reference'] ?? null,
            'buyer_name' => $validated['buyer_name'] ?? null,
            'delivery_terms' => $validated['delivery_terms'] ?? null,
            'note' => $validated['note'] ?? null,
        ];
    }
}
