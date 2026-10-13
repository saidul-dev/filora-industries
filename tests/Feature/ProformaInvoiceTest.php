<?php

namespace Tests\Feature;

use App\Models\ProformaInvoice;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class ProformaInvoiceTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
    }

    /**
     * A confirmed order with one manufactured line, one direct-purchase
     * line, and one line already fully covered by stock.
     */
    private function confirmedOrder(): SalesOrder
    {
        $customer = $this->makeCustomer();
        $office = $this->makeSite('Head Office', 'HO-01');
        $zipper = $this->makeProduct('manufactured');
        $label = $this->makeProduct('direct_purchase');
        $button = $this->makeProduct('direct_purchase');
        $this->stockIn($button, $office, 300, 1, $this->admin);

        $this->actingAs($this->admin)->post(route('sales-orders.store'), [
            'party_id' => $customer->id,
            'order_date' => '2026-10-01',
            'delivery_terms' => 'FOB Dhaka',
            'items' => [
                ['item' => "product-{$zipper->id}", 'quantity' => 1000, 'unit_price' => 12],
                ['item' => "product-{$label->id}", 'quantity' => 500, 'unit_price' => 3],
                ['item' => "product-{$button->id}", 'quantity' => 200, 'unit_price' => 2],
            ],
        ]);

        $order = SalesOrder::latest('id')->first();
        $this->actingAs($this->admin)->post(route('sales-orders.confirm', $order));

        return $order->refresh();
    }

    public function test_pi_is_generated_from_a_confirmed_order_with_a_fulfillment_plan(): void
    {
        $order = $this->confirmedOrder();

        $this->actingAs($this->admin)->post(route('proforma-invoices.store', $order))->assertRedirect();

        $invoice = ProformaInvoice::with('items.product')->first();
        $this->assertSame('PI-000001', $invoice->pi_no);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('FOB Dhaka', $invoice->terms);
        $this->assertEquals(13900, (float) $invoice->total_amount);
        $this->assertSame('pi_generated', $order->refresh()->status);

        $plan = $invoice->items->mapWithKeys(fn ($i) => [$i->product->product_type.'-'.(int) $i->quantity => $i->fulfillment]);
        $this->assertSame('production', $plan['manufactured-1000']);
        $this->assertSame('purchase', $plan['direct_purchase-500']);
        $this->assertSame('stock', $plan['direct_purchase-200']);

        // A second PI can't be generated from the same order.
        $this->actingAs($this->admin)->post(route('proforma-invoices.store', $order));
        $this->assertSame(1, ProformaInvoice::count());

        $this->actingAs($this->admin)->get(route('proforma-invoices.show', $invoice))->assertOk()->assertSee('PI-000001');
        $this->actingAs($this->admin)->get(route('proforma-invoices.print', $invoice))->assertOk();
    }

    public function test_pending_order_cannot_be_turned_into_a_pi(): void
    {
        $customer = $this->makeCustomer();
        $zipper = $this->makeProduct('manufactured');
        $this->actingAs($this->admin)->post(route('sales-orders.store'), [
            'party_id' => $customer->id,
            'order_date' => '2026-10-01',
            'items' => [['item' => "product-{$zipper->id}", 'quantity' => 10, 'unit_price' => 12]],
        ]);

        $this->actingAs($this->admin)->post(route('proforma-invoices.store', SalesOrder::first()));
        $this->assertSame(0, ProformaInvoice::count());
    }

    public function test_draft_pi_items_can_be_finalised_then_locked_on_issue(): void
    {
        $this->actingAs($this->admin)->post(route('proforma-invoices.store', $this->confirmedOrder()));
        $invoice = ProformaInvoice::with('items')->first();
        $first = $invoice->items->first();
        $direct = $invoice->items->firstWhere('fulfillment', 'purchase');

        $payload = [
            'pi_date' => '2026-10-02',
            'valid_until' => '2026-11-02',
            'items' => [
                ['item' => "product-{$first->product_id}", 'quantity' => 900, 'unit_price' => 12, 'fulfillment' => 'production'],
            ],
        ];

        $this->actingAs($this->admin)->put(route('proforma-invoices.update', $invoice), $payload)
            ->assertRedirect(route('proforma-invoices.show', $invoice));
        $invoice->refresh();
        $this->assertCount(1, $invoice->items);
        $this->assertEquals(10800, (float) $invoice->total_amount);

        // A direct-purchase product can't be planned for production.
        $this->actingAs($this->admin)->put(route('proforma-invoices.update', $invoice), [
            'pi_date' => '2026-10-02',
            'items' => [['item' => "product-{$direct->product_id}", 'quantity' => 5, 'unit_price' => 3, 'fulfillment' => 'production']],
        ])->assertSessionHasErrors('items.0.fulfillment');

        $this->actingAs($this->admin)->post(route('proforma-invoices.issue', $invoice));
        $this->assertSame('issued', $invoice->refresh()->status);

        $this->actingAs($this->admin)->put(route('proforma-invoices.update', $invoice), array_merge($payload, [
            'items' => [['item' => "product-{$first->product_id}", 'quantity' => 1, 'unit_price' => 1, 'fulfillment' => 'stock']],
        ]));
        $this->assertEquals(10800, (float) $invoice->refresh()->total_amount);
    }

    public function test_attaching_an_lc_moves_an_issued_pi_to_lc_issued(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->post(route('proforma-invoices.store', $this->confirmedOrder()));
        $invoice = ProformaInvoice::first();

        // Not before it's issued.
        $this->actingAs($this->admin)->post(route('proforma-invoices.lc', $invoice), [
            'lc_no' => 'LC-1', 'lc_expiry_date' => '2026-12-31', 'lc_file' => UploadedFile::fake()->create('lc.pdf', 100, 'application/pdf'),
        ]);
        $this->assertNull($invoice->refresh()->lc_no);

        $this->actingAs($this->admin)->post(route('proforma-invoices.issue', $invoice));

        $this->actingAs($this->admin)->post(route('proforma-invoices.lc', $invoice), ['lc_no' => 'LC-1', 'lc_expiry_date' => '2026-12-31'])
            ->assertSessionHasErrors('lc_file');

        $this->actingAs($this->admin)->post(route('proforma-invoices.lc', $invoice), [
            'lc_no' => 'DBBL-LC-2026-0042',
            'lc_bank' => 'Dutch-Bangla Bank',
            'lc_date' => '2026-10-05',
            'lc_expiry_date' => '2026-12-31',
            'lc_amount' => 13900,
            'lc_file' => UploadedFile::fake()->image('lc-scan.jpg'),
        ])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('lc_issued', $invoice->status);
        $this->assertSame('DBBL-LC-2026-0042', $invoice->lc_no);
        $this->assertSame('2026-12-31', $invoice->lc_expiry_date->toDateString());
        Storage::disk('local')->assertExists($invoice->lc_file_path);

        $this->actingAs($this->admin)->get(route('proforma-invoices.lc-file', $invoice))->assertOk();
    }

    public function test_cancelling_a_pi_returns_the_order_to_confirmed(): void
    {
        $order = $this->confirmedOrder();
        $this->actingAs($this->admin)->post(route('proforma-invoices.store', $order));
        $invoice = ProformaInvoice::first();

        $this->actingAs($this->admin)->post(route('proforma-invoices.cancel', $invoice));

        $this->assertSame('cancelled', $invoice->refresh()->status);
        $this->assertSame('confirmed', $order->refresh()->status);

        $this->actingAs($this->admin)->post(route('proforma-invoices.store', $order));
        $this->assertSame(2, ProformaInvoice::count());
    }

    public function test_sales_person_sees_their_own_pi_but_cannot_issue_it(): void
    {
        $salesPerson = $this->userWithRole('Sales Person');
        $order = $this->confirmedOrder();
        $order->update(['sales_person_id' => $salesPerson->id]);
        $this->actingAs($this->admin)->post(route('proforma-invoices.store', $order));
        $invoice = ProformaInvoice::first();

        $this->actingAs($salesPerson)->get(route('proforma-invoices.show', $invoice))->assertOk();
        $this->actingAs($salesPerson)->post(route('proforma-invoices.issue', $invoice))->assertForbidden();
        $this->actingAs($this->userWithRole('Sales Person'))->get(route('proforma-invoices.show', $invoice))->assertForbidden();
    }
}
