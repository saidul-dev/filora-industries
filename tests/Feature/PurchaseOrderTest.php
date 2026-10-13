<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\Purchase;
use App\Models\PurchaseRequisition;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProformaInvoices;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use BuildsProformaInvoices;
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private Site $factory;

    private Site $office;

    private Product $label;

    private Product $tape;

    private ProformaInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
        $this->factory = $this->makeSite('Factory', 'FAC-01');
        $this->office = $this->makeSite('Head Office', 'HO-01');

        $this->label = $this->makeProduct('direct_purchase');
        $this->tape = $this->makeProduct('raw_material');

        $this->invoice = $this->issuedPi($this->admin, $this->makeCustomer(), [
            ['product' => $this->label, 'quantity' => 400, 'unit_price' => 3, 'fulfillment' => 'purchase'],
        ]);
    }

    private function approvedRequisition(): PurchaseRequisition
    {
        $this->actingAs($this->admin)->post(route('purchase-requisitions.store'), [
            'proforma_invoice_id' => $this->invoice->id,
            'site_id' => $this->office->id,
            'request_date' => now()->toDateString(),
            'items' => [
                ['item' => "product-{$this->label->id}", 'quantity' => 400, 'unit_cost' => 1.5],
                ['item' => "product-{$this->tape->id}", 'quantity' => 100, 'unit_cost' => 2],
            ],
        ]);

        $requisition = PurchaseRequisition::latest('id')->first();
        $this->actingAs($this->admin)->post(route('purchase-requisitions.approve', $requisition));

        return $requisition->refresh();
    }

    private function placeOrder(PurchaseRequisition $requisition, array $vendor): Purchase
    {
        $this->actingAs($this->admin)->post(route('purchases.store'), array_merge([
            'purchase_requisition_id' => $requisition->id,
            'site_id' => $this->office->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item' => "product-{$this->label->id}", 'quantity' => 400, 'unit_cost' => 1.5],
                ['item' => "product-{$this->tape->id}", 'quantity' => 100, 'unit_cost' => 2],
            ],
        ], $vendor))->assertSessionHasNoErrors();

        return Purchase::latest('id')->first();
    }

    public function test_purchase_order_is_placed_from_an_approved_requisition_only(): void
    {
        $supplier = $this->makeSupplier();

        $this->actingAs($this->admin)->post(route('purchase-requisitions.store'), [
            'proforma_invoice_id' => $this->invoice->id,
            'site_id' => $this->office->id,
            'request_date' => now()->toDateString(),
            'items' => [['item' => "product-{$this->label->id}", 'quantity' => 400]],
        ]);
        $pending = PurchaseRequisition::first();

        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'purchase_requisition_id' => $pending->id,
            'vendor_type' => 'supplier',
            'party_id' => $supplier->id,
            'site_id' => $this->office->id,
            'order_date' => now()->toDateString(),
            'items' => [['item' => "product-{$this->label->id}", 'quantity' => 400, 'unit_cost' => 1.5]],
        ])->assertSessionHasErrors('purchase_requisition_id');
        $this->assertSame(0, Purchase::count());

        $purchase = $this->placeOrder($this->approvedRequisition(), ['vendor_type' => 'supplier', 'party_id' => $supplier->id]);

        $this->assertSame('PO-000001', $purchase->purchase_no);
        $this->assertSame($this->invoice->id, $purchase->proforma_invoice_id);
        $this->assertEquals(800, (float) $purchase->total_amount);
        $this->assertSame('ordered', $purchase->requisition->status);

        // Placing the order alone touches neither stock nor the ledger.
        $this->assertEquals(0, $this->balance($this->label, $this->office));
        $this->assertSame(0, LedgerTransaction::where('type', 'purchase')->count());

        $this->actingAs($this->admin)->get(route('purchases.show', $purchase))->assertOk()->assertSee('PO-000001');
        $this->actingAs($this->admin)->get(route('purchases.print', $purchase))->assertOk();
    }

    public function test_goods_receipt_adds_stock_at_the_receiving_site_and_posts_payable(): void
    {
        $supplier = $this->makeSupplier();
        $purchase = $this->placeOrder($this->approvedRequisition(), ['vendor_type' => 'supplier', 'party_id' => $supplier->id]);
        $labelLine = $purchase->items->firstWhere('product_id', $this->label->id);
        $tapeLine = $purchase->items->firstWhere('product_id', $this->tape->id);

        $this->actingAs($this->admin)->get(route('purchases.receive', $purchase))->assertOk()->assertSee($this->tape->name);

        // Partial receipt: labels at the office, tape at the factory.
        $this->actingAs($this->userWithRole('Factory Manager'))->post(route('purchases.receive.store', $purchase), [
            'site_id' => $this->factory->id,
            'received_date' => now()->toDateString(),
            'items' => [$tapeLine->id => ['quantity' => 100], $labelLine->id => ['quantity' => 0]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('partial', $purchase->refresh()->status);
        $this->assertEquals(100, $this->balance($this->tape, $this->factory));
        $this->assertEquals(0, $this->balance($this->tape, $this->office));

        $this->actingAs($this->admin)->post(route('purchases.receive.store', $purchase), [
            'site_id' => $this->office->id,
            'received_date' => now()->toDateString(),
            'items' => [$labelLine->id => ['quantity' => 500]],
        ])->assertSessionHasErrors("items.{$labelLine->id}.quantity");

        $this->actingAs($this->admin)->post(route('purchases.receive.store', $purchase), [
            'site_id' => $this->office->id,
            'received_date' => now()->toDateString(),
            'items' => [$labelLine->id => ['quantity' => 400]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('received', $purchase->refresh()->status);
        $this->assertEquals(400, $this->balance($this->label, $this->office));
        $this->assertCount(2, $purchase->receipts);

        // Purchase cost recorded in Accounts: Dr Inventory / Cr Payable (supplier).
        $this->assertEquals(800, LedgerAccount::where('code', 'inventory')->first()->balance());
        $this->assertEquals(800, $supplier->payableBalance());
        $this->assertEquals(1.5, (float) $this->label->refresh()->estimated_cost);
    }

    public function test_one_time_vendor_is_paid_from_cash_on_receipt(): void
    {
        $purchase = $this->placeOrder($this->approvedRequisition(), [
            'vendor_type' => 'one_time',
            'vendor_name' => 'Islampur Trims Market',
            'vendor_phone' => '01711000000',
        ]);
        $this->assertNull($purchase->party_id);
        $this->assertSame('Islampur Trims Market', $purchase->vendorName());

        $items = $purchase->items->mapWithKeys(fn ($item) => [$item->id => ['quantity' => $item->quantity]])->all();

        $this->actingAs($this->admin)->post(route('purchases.receive.store', $purchase), [
            'site_id' => $this->office->id,
            'received_date' => now()->toDateString(),
            'items' => $items,
        ])->assertSessionHasErrors('paid_from_account_id');

        $cash = LedgerAccount::where('code', 'cash_in_hand')->first();
        $this->actingAs($this->admin)->post(route('purchases.receive.store', $purchase), [
            'site_id' => $this->office->id,
            'received_date' => now()->toDateString(),
            'paid_from_account_id' => $cash->id,
            'items' => $items,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(-800, $cash->balance());
        $this->assertEquals(800, LedgerAccount::where('code', 'inventory')->first()->balance());
    }

    public function test_cancelling_an_unreceived_order_reopens_the_requisition(): void
    {
        $requisition = $this->approvedRequisition();
        $purchase = $this->placeOrder($requisition, ['vendor_type' => 'supplier', 'party_id' => $this->makeSupplier()->id]);

        $this->actingAs($this->userWithRole('Factory Manager'))->post(route('purchases.cancel', $purchase))->assertForbidden();

        $this->actingAs($this->admin)->post(route('purchases.cancel', $purchase));

        $this->assertSame('cancelled', $purchase->refresh()->status);
        $this->assertSame('approved', $requisition->refresh()->status);
    }
}
