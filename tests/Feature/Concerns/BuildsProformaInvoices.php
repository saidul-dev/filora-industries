<?php

namespace Tests\Feature\Concerns;

use App\Models\Party;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\User;

/**
 * Builds an issued Proforma Invoice through the real HTTP flow (Sales
 * Order → confirm → generate PI → set fulfillment → issue).
 *
 * @mixin \Tests\TestCase
 */
trait BuildsProformaInvoices
{
    /**
     * @param  array<int, array{product: Product, quantity: float, unit_price: float, fulfillment: string}>  $lines
     */
    protected function issuedPi(User $admin, Party $customer, array $lines): ProformaInvoice
    {
        $this->actingAs($admin)->post(route('sales-orders.store'), [
            'party_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'items' => array_map(fn ($line) => [
                'item' => "product-{$line['product']->id}",
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
            ], $lines),
        ])->assertSessionHasNoErrors();

        $order = \App\Models\SalesOrder::latest('id')->first();
        $this->actingAs($admin)->post(route('sales-orders.confirm', $order));
        $this->actingAs($admin)->post(route('proforma-invoices.store', $order));

        $invoice = ProformaInvoice::latest('id')->first();

        $this->actingAs($admin)->put(route('proforma-invoices.update', $invoice), [
            'pi_date' => now()->toDateString(),
            'items' => array_map(fn ($line) => [
                'item' => "product-{$line['product']->id}",
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'fulfillment' => $line['fulfillment'],
            ], $lines),
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('proforma-invoices.issue', $invoice));

        return $invoice->refresh()->load('items');
    }
}
