<?php

namespace Tests\Feature;

use App\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
    }

    private function payload(array $overrides = []): array
    {
        $customer = $this->makeCustomer();
        $zipper = $this->makeProduct('manufactured', ['selling_price' => 12]);
        $label = $this->makeProduct('direct_purchase', ['selling_price' => 3]);

        return array_merge([
            'party_id' => $customer->id,
            'order_date' => '2026-10-01',
            'delivery_date' => '2026-10-30',
            'discount' => 100,
            'items' => [
                ['item' => "product-{$zipper->id}", 'quantity' => 1000, 'unit_price' => 12],
                ['item' => "product-{$label->id}", 'quantity' => 500, 'unit_price' => 3],
            ],
        ], $overrides);
    }

    public function test_sales_person_creates_an_order_as_themselves(): void
    {
        $salesPerson = $this->userWithRole('Sales Person');

        $this->actingAs($salesPerson)->post(route('sales-orders.store'), $this->payload())
            ->assertRedirect();

        $order = SalesOrder::with('items')->first();
        $this->assertSame('SO-000001', $order->order_no);
        $this->assertSame('pending', $order->status);
        $this->assertSame($salesPerson->id, $order->sales_person_id);
        $this->assertCount(2, $order->items);
        $this->assertEquals(13500, (float) $order->subtotal_amount);
        $this->assertEquals(13400, (float) $order->total_amount);
    }

    public function test_pending_order_can_be_edited_but_confirmed_order_cannot(): void
    {
        $admin = $this->admin();
        $payload = $this->payload();
        $this->actingAs($admin)->post(route('sales-orders.store'), $payload);
        $order = SalesOrder::first();

        // Pending: drop a line and change the remaining quantity.
        $this->actingAs($admin)->put(route('sales-orders.update', $order), array_merge($payload, [
            'discount' => 0,
            'items' => [['item' => $payload['items'][0]['item'], 'quantity' => 1200, 'unit_price' => 12]],
        ]))->assertRedirect(route('sales-orders.show', $order));

        $order->refresh()->load('items');
        $this->assertCount(1, $order->items);
        $this->assertEquals(14400, (float) $order->total_amount);

        $this->actingAs($admin)->post(route('sales-orders.confirm', $order))->assertRedirect();
        $this->assertSame('confirmed', $order->refresh()->status);
        $this->assertNotNull($order->confirmed_at);

        $this->actingAs($admin)->put(route('sales-orders.update', $order), $payload);
        $this->assertCount(1, $order->items()->get());

        $this->actingAs($admin)->get(route('sales-orders.edit', $order))
            ->assertRedirect(route('sales-orders.show', $order));
    }

    public function test_sales_person_only_sees_their_own_orders_and_cannot_confirm(): void
    {
        $alice = $this->userWithRole('Sales Person');
        $bob = $this->userWithRole('Sales Person');

        $this->actingAs($alice)->post(route('sales-orders.store'), $this->payload());
        $aliceOrder = SalesOrder::first();
        $this->flushSession();

        $this->actingAs($bob)->get(route('sales-orders.index'))
            ->assertOk()->assertDontSee($aliceOrder->order_no);
        $this->actingAs($bob)->get(route('sales-orders.show', $aliceOrder))->assertForbidden();

        $this->actingAs($alice)->get(route('sales-orders.index'))
            ->assertOk()->assertSee($aliceOrder->order_no);
        $this->actingAs($alice)->post(route('sales-orders.confirm', $aliceOrder))->assertForbidden();

        $this->actingAs($this->admin())->get(route('sales-orders.show', $aliceOrder))->assertOk();
    }

    public function test_order_requires_a_customer_and_valid_lines(): void
    {
        $supplier = $this->makeSupplier();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('sales-orders.store'), $this->payload(['party_id' => $supplier->id]))
            ->assertSessionHasErrors('party_id');

        $this->actingAs($admin)->post(route('sales-orders.store'), $this->payload(['items' => [['item' => 'product-999', 'quantity' => 1, 'unit_price' => 1]]]))
            ->assertSessionHasErrors('items.0.item');

        $this->actingAs($admin)->post(route('sales-orders.store'), $this->payload(['discount' => 999999]))
            ->assertSessionHasErrors('discount');

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_only_pending_orders_can_be_deleted(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('sales-orders.store'), $this->payload());
        $order = SalesOrder::first();

        $this->actingAs($admin)->post(route('sales-orders.confirm', $order));
        $this->actingAs($admin)->delete(route('sales-orders.destroy', $order));
        $this->assertNotNull($order->fresh());

        $this->actingAs($admin)->post(route('sales-orders.cancel', $order));
        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
