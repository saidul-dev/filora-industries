<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderService;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Third-party work during production — a supplier dyes, prints or
 * embroiders the goods and bills for it. Each entry posts
 * Dr Inventory / Cr Accounts Payable (the supplier), so the supplier's
 * balance shows what is owed and the cost travels with the goods: on
 * completion it is added to the finished goods' unit cost. Paying the
 * supplier is an ordinary Payment.
 */
class ProductionOrderServiceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:production.edit'),
        ];
    }

    public function store(Request $request, ProductionOrder $productionOrder)
    {
        if (! $productionOrder->isOpen()) {
            return back()->with('error', 'Third-party work can only be added while the production order is open.');
        }

        $validated = $request->validateWithBag('service', [
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('is_supplier', true)],
            'service' => ['required', 'string', 'max:100'],
            'production_order_item_id' => ['nullable', 'integer', Rule::exists('production_order_items', 'id')->where('production_order_id', $productionOrder->id)],
            'service_date' => ['required', 'date'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'party_id.exists' => 'Pick a supplier.',
        ]);

        $service = DB::transaction(function () use ($validated, $productionOrder) {
            $service = $productionOrder->services()->create(array_merge($validated, [
                'amount' => round((float) $validated['amount'], 2),
                'created_by' => Auth::id(),
            ]));
            $service->load('party');

            $amount = (float) $service->amount;
            $transaction = LedgerService::post([
                'type' => 'production_service',
                'date' => $service->service_date,
                'site_id' => $productionOrder->site_id,
                'narration' => "{$service->service} by {$service->party->name} for {$productionOrder->production_no}".($service->reference_no ? " (bill {$service->reference_no})" : ''),
                'reference' => $productionOrder,
                'created_by' => Auth::id(),
                'lines' => [
                    ['account' => 'inventory', 'debit' => $amount],
                    ['account' => 'accounts_payable', 'credit' => $amount, 'party_id' => $service->party_id],
                ],
            ]);
            $service->update(['ledger_transaction_id' => $transaction->id]);

            $this->reallocate($productionOrder);

            return $service;
        });

        return back()->with('success', "{$service->service} by {$service->party->name} added — ".number_format((float) $service->amount, 2)." posted to the supplier's account.");
    }

    /**
     * Remove a wrong entry: a reversing voucher takes it back off the
     * supplier's account.
     */
    public function destroy(ProductionOrder $productionOrder, ProductionOrderService $service)
    {
        abort_unless($service->production_order_id === $productionOrder->id, 404);

        if (! $productionOrder->isOpen()) {
            return back()->with('error', 'Third-party work can only be removed while the production order is open.');
        }

        DB::transaction(function () use ($productionOrder, $service) {
            $amount = (float) $service->amount;
            $service->load('party');

            LedgerService::post([
                'type' => 'production_service',
                'date' => now()->toDateString(),
                'site_id' => $productionOrder->site_id,
                'narration' => "Reversal: {$service->service} by {$service->party->name} removed from {$productionOrder->production_no}",
                'reference' => $productionOrder,
                'created_by' => Auth::id(),
                'lines' => [
                    ['account' => 'accounts_payable', 'debit' => $amount, 'party_id' => $service->party_id],
                    ['account' => 'inventory', 'credit' => $amount],
                ],
            ]);

            $service->delete();

            $this->reallocate($productionOrder);
        });

        return back()->with('success', 'Third-party work removed and reversed from the supplier\'s account.');
    }

    /**
     * Spread the third-party cost over the order's lines: work entered for
     * a line stays on it; work for the whole order is split by quantity.
     */
    protected function reallocate(ProductionOrder $productionOrder): void
    {
        $productionOrder->load(['items', 'services']);

        $shared = (float) $productionOrder->services->whereNull('production_order_item_id')->sum('amount');
        $totalQuantity = $productionOrder->totalQuantity();
        $items = $productionOrder->items->sortBy('id')->values();
        $allocated = 0.0;

        foreach ($items as $index => $item) {
            $own = (float) $productionOrder->services->where('production_order_item_id', $item->id)->sum('amount');

            // The last line takes the rounding remainder of the shared cost.
            $share = $index === $items->count() - 1
                ? round($shared - $allocated, 2)
                : ($totalQuantity > 0 ? round($shared * (float) $item->quantity / $totalQuantity, 2) : 0.0);
            $allocated += $share;

            $item->update(['service_cost' => round($own + $share, 2)]);
        }

        $productionOrder->update(['service_cost' => round((float) $productionOrder->services->sum('amount'), 2)]);
    }
}
