<?php

namespace Tests\Feature;

use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class PartyBalanceListTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    public function test_party_list_shows_current_ledger_balances(): void
    {
        $this->seedBasics();

        // Opening balances post to the ledger on create, like any later sale or bill.
        Party::create(['name' => 'Owing Buyer', 'phone' => '01700000011', 'is_customer' => true, 'status' => true, 'opening_balance' => 1500, 'opening_balance_type' => 'due']);
        Party::create(['name' => 'Advance Buyer', 'phone' => '01700000012', 'is_customer' => true, 'status' => true, 'opening_balance' => 200, 'opening_balance_type' => 'advance']);
        Party::create(['name' => 'Billing Supplier', 'phone' => '01800000013', 'is_supplier' => true, 'status' => true, 'opening_balance' => 750.5, 'opening_balance_type' => 'due']);
        Party::create(['name' => 'Quiet Buyer', 'phone' => '01700000014', 'is_customer' => true, 'status' => true]);

        $this->actingAs($this->admin())->get(route('parties.index'))->assertOk()
            ->assertSeeInOrder(['Advance Buyer', '200.00', 'Advance received'])
            ->assertSeeInOrder(['Billing Supplier', '750.50', 'Payable'])
            ->assertSeeInOrder(['Owing Buyer', '1,500.00', 'Receivable'])
            ->assertSeeInOrder(['Quiet Buyer', '0.00', 'Settled']);
    }
}
