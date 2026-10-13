<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProformaInvoices;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class PiCollectionTest extends TestCase
{
    use BuildsProformaInvoices;
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private Party $customer;

    private ProformaInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
        $this->customer = $this->makeCustomer();

        $this->invoice = $this->issuedPi($this->admin, $this->customer, [
            ['product' => $this->makeProduct('direct_purchase'), 'quantity' => 100, 'unit_price' => 50, 'fulfillment' => 'purchase'],
        ]);
    }

    public function test_accounts_collects_payment_against_a_pi(): void
    {
        $accountant = $this->userWithRole('Accountant');
        $cash = LedgerAccount::where('code', 'cash_in_hand')->first();

        $this->actingAs($accountant)->get(route('collections.create', ['proforma_invoice_id' => $this->invoice->id]))
            ->assertOk()->assertSee($this->invoice->pi_no)->assertSee($this->customer->name);

        $this->actingAs($accountant)->post(route('collections.store'), [
            'party_id' => $this->customer->id,
            'proforma_invoice_id' => $this->invoice->id,
            'ledger_account_id' => $cash->id,
            'amount' => 2000,
            'collection_date' => now()->toDateString(),
            'reference_no' => 'LC-PROCEEDS-1',
        ])->assertRedirect(route('proforma-invoices.show', $this->invoice));

        $collection = Collection::first();
        $this->assertSame($this->invoice->id, $collection->proforma_invoice_id);
        $this->assertEquals(2000, $this->invoice->collectedAmount());
        $this->assertEquals(3000, $this->invoice->dueAmount());
        $this->assertEquals(2000, $cash->balance());
        $this->assertEquals(-2000, $this->customer->receivableBalance()); // an advance until goods are delivered

        $this->actingAs($accountant)->get(route('proforma-invoices.show', $this->invoice))
            ->assertOk()->assertSee($collection->collection_no);
        $this->actingAs($accountant)->get(route('collections.show', $collection))
            ->assertOk()->assertSee($this->invoice->pi_no);
    }

    public function test_pi_must_belong_to_the_customer(): void
    {
        $other = $this->makeCustomer('Another Buyer Ltd.');

        $this->actingAs($this->admin)->post(route('collections.store'), [
            'party_id' => $other->id,
            'proforma_invoice_id' => $this->invoice->id,
            'ledger_account_id' => LedgerAccount::where('code', 'cash_in_hand')->value('id'),
            'amount' => 100,
            'collection_date' => now()->toDateString(),
        ])->assertSessionHasErrors('proforma_invoice_id');

        $this->assertSame(0, Collection::count());
    }
}
