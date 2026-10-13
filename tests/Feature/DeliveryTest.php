<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProformaInvoices;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use BuildsProformaInvoices;
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private Site $factory;

    private Site $office;

    private Party $customer;

    private Product $zipper;

    private Product $label;

    private ProformaInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
        $this->factory = $this->makeSite('Factory', 'FAC-01');
        $this->office = $this->makeSite('Head Office', 'HO-01');
        $this->customer = $this->makeCustomer();

        $this->zipper = $this->makeProduct('manufactured');
        $this->label = $this->makeProduct('direct_purchase');

        $this->stockIn($this->zipper, $this->factory, 1000, 5, $this->admin);
        $this->stockIn($this->label, $this->office, 400, 1.5, $this->admin);

        // 12,000 + 1,200 = 13,200 gross; 10% discount set on the draft below.
        $this->invoice = $this->issuedPi($this->admin, $this->customer, [
            ['product' => $this->zipper, 'quantity' => 1000, 'unit_price' => 12, 'fulfillment' => 'stock'],
            ['product' => $this->label, 'quantity' => 400, 'unit_price' => 3, 'fulfillment' => 'stock'],
        ]);
    }

    private function deliver(Site $site, array $quantities): \Illuminate\Testing\TestResponse
    {
        $items = [];
        foreach ($quantities as $productId => $quantity) {
            $items[$this->invoice->items->firstWhere('product_id', $productId)->id] = ['quantity' => $quantity];
        }

        return $this->actingAs($this->admin)->post(route('deliveries.store'), [
            'proforma_invoice_id' => $this->invoice->id,
            'site_id' => $site->id,
            'delivery_date' => now()->toDateString(),
            'vehicle_no' => 'DHAKA-METRO-TA-11-2233',
            'items' => $items,
        ]);
    }

    public function test_partial_deliveries_from_two_sites_complete_the_pi(): void
    {
        $this->actingAs($this->admin)
            ->get(route('deliveries.create', ['proforma_invoice_id' => $this->invoice->id, 'site_id' => $this->factory->id]))
            ->assertOk()->assertSee($this->zipper->name);

        $this->deliver($this->factory, [$this->zipper->id => 600])->assertSessionHasNoErrors();

        $this->assertSame('partially_delivered', $this->invoice->refresh()->status);
        $this->assertEquals(400, $this->balance($this->zipper, $this->factory));

        // Labels are at the office, not the factory.
        $this->deliver($this->factory, [$this->label->id => 10])->assertSessionHasErrors();

        $this->deliver($this->office, [$this->label->id => 400])->assertSessionHasNoErrors();
        $this->deliver($this->factory, [$this->zipper->id => 400])->assertSessionHasNoErrors();

        $this->assertSame('delivered', $this->invoice->refresh()->status);
        $this->assertEquals(0, $this->balance($this->label, $this->office));
        $this->assertSame(3, Delivery::count());
        $this->assertSame('DC-000001', Delivery::first()->delivery_no);

        // Over-delivery is blocked once the line is fully delivered.
        $this->deliver($this->factory, [$this->zipper->id => 1]);
        $this->assertSame(3, Delivery::count());

        $this->actingAs($this->admin)->post(route('proforma-invoices.close', $this->invoice));
        $this->assertSame('closed', $this->invoice->refresh()->status);
    }

    public function test_delivery_posts_receivable_revenue_and_cost_of_goods_sold(): void
    {
        $this->deliver($this->factory, [$this->zipper->id => 1000]);
        $this->deliver($this->office, [$this->label->id => 400]);

        $receivable = $this->customer->receivableBalance();
        $this->assertEquals(13200, $receivable);
        $this->assertEquals(13200, LedgerAccount::where('code', 'sales_revenue')->first()->balance());
        $this->assertEquals(5600, LedgerAccount::where('code', 'cost_of_goods_sold')->first()->balance()); // 1000×5 + 400×1.5
        // Fixture opening stock bypassed the ledger, so Inventory only shows the cost credited out.
        $this->assertEquals(-5600, LedgerAccount::where('code', 'inventory')->first()->balance());

        $delivery = Delivery::first();
        $this->actingAs($this->admin)->get(route('deliveries.show', $delivery))->assertOk()->assertSee('DC-000001');
        $this->actingAs($this->admin)->get(route('deliveries.print', $delivery))->assertOk();
        $this->actingAs($this->admin)->get(route('proforma-invoices.show', $this->invoice))->assertOk()->assertSee('DC-000002');
    }

    public function test_pi_discount_is_spread_over_delivered_revenue(): void
    {
        $this->invoice->update(['discount_amount' => 1320, 'total_amount' => 11880]);

        $this->deliver($this->factory, [$this->zipper->id => 1000]);

        $this->assertEquals(10800, (float) Delivery::first()->sale_amount); // 12,000 × 0.9
        $this->assertEquals(10800, $this->customer->receivableBalance());
    }

    public function test_delivery_needs_sales_deliver_permission_and_a_live_pi(): void
    {
        $this->actingAs($this->userWithRole('Sales Person'))->post(route('deliveries.store'), [
            'proforma_invoice_id' => $this->invoice->id,
            'site_id' => $this->factory->id,
            'delivery_date' => now()->toDateString(),
            'items' => [],
        ])->assertForbidden();

        $this->actingAs($this->admin)->post(route('proforma-invoices.cancel', $this->invoice));
        $this->deliver($this->factory, [$this->zipper->id => 1])->assertSessionHasErrors('proforma_invoice_id');
        $this->assertSame(0, Delivery::count());
    }
}
