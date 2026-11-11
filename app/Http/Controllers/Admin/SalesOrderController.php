<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PicksProducts;
use App\Http\Controllers\Concerns\WritesSalesOrders;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\ProformaInvoiceItem;
use App\Models\SalesOrder;
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
            new Middleware('permission:sales.edit', only: ['edit', 'update', 'plan']),
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
