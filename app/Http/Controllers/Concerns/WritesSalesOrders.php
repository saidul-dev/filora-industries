<?php

namespace App\Http\Controllers\Concerns;

use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creating a pending Sales Order and its lines — shared by the order form
 * (SalesOrderController) and the Excel import (SalesOrderImportController)
 * so both number, total and store lines the same way.
 *
 * A line row is ['product' => Product, 'variant' => ?ProductVariant,
 * 'quantity' => float, 'unit_price' => ?float (null = not priced yet), 'subtotal' => float,
 * 'style' => ?string, 'color' => ?string, 'length_per_unit' => ?float,
 * 'remarks' => ?string].
 */
trait WritesSalesOrders
{
    /**
     * @param  array  $header  SalesOrder attributes other than numbering, status and totals
     */
    protected function createSalesOrder(array $header, array $rows, float $discount = 0): SalesOrder
    {
        $subtotal = round(array_sum(array_column($rows, 'subtotal')), 2);

        return DB::transaction(function () use ($header, $rows, $subtotal, $discount) {
            $order = SalesOrder::create(array_merge($header, [
                'order_no' => 'PENDING',
                'status' => 'pending',
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $subtotal - $discount,
                'created_by' => Auth::id(),
            ]));
            $order->update(['order_no' => 'SO-'.str_pad($order->id, 6, '0', STR_PAD_LEFT)]);

            $this->createItems($order, $rows);

            return $order;
        });
    }

    /**
     * A Sales Person always enters orders as themselves; anyone else may
     * pick one, or it stays as it was (or themselves, if a Sales Person).
     */
    protected function resolveSalesPerson(array $validated, ?SalesOrder $existing = null): ?int
    {
        $user = Auth::user();

        if ($user->seesOnlyOwnSales()) {
            return $existing?->sales_person_id ?? $user->id;
        }

        if (! empty($validated['sales_person_id'])) {
            return (int) $validated['sales_person_id'];
        }

        return $existing?->sales_person_id ?? ($user->hasRole('Sales Person') ? $user->id : null);
    }

    protected function salesPersonOptions()
    {
        return Auth::user()->seesOnlyOwnSales()
            ? collect()
            : User::role('Sales Person')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    protected function createItems(SalesOrder $order, array $rows): void
    {
        foreach ($rows as $row) {
            $order->items()->create([
                'product_id' => $row['product']->id,
                'product_variant_id' => $row['variant']?->id,
                'style' => $row['style'] ?? null,
                'color' => $row['color'] ?? null,
                'length_per_unit' => $row['length_per_unit'] ?? null,
                'remarks' => $row['remarks'] ?? null,
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'subtotal' => $row['subtotal'],
            ]);
        }
    }
}
