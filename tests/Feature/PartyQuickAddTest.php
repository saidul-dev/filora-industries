<?php

namespace Tests\Feature;

use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class PartyQuickAddTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
    }

    public function test_quick_add_creates_an_active_customer_and_returns_it(): void
    {
        $response = $this->actingAs($this->admin())->postJson(route('parties.quick-store'), [
            'role' => 'customer',
            'name' => 'Rapid Knit Ltd.',
            'phone' => '01711000111',
            'contact_person' => 'Mr. Karim',
        ]);

        $party = Party::where('phone', '01711000111')->firstOrFail();
        $response->assertCreated()->assertExactJson(['id' => (string) $party->id, 'name' => 'Rapid Knit Ltd.']);
        $this->assertTrue($party->is_customer);
        $this->assertFalse($party->is_supplier);
        $this->assertTrue($party->status);
        $this->assertSame('Mr. Karim', $party->contact_person);
    }

    public function test_quick_add_creates_a_supplier(): void
    {
        $this->actingAs($this->admin())->postJson(route('parties.quick-store'), [
            'role' => 'supplier',
            'name' => 'Yarn House',
            'phone' => '01811000222',
        ])->assertCreated();

        $party = Party::where('phone', '01811000222')->firstOrFail();
        $this->assertTrue($party->is_supplier);
        $this->assertFalse($party->is_customer);
    }

    public function test_quick_add_rejects_a_phone_already_in_use(): void
    {
        $existing = $this->makeCustomer('Old Buyer');

        $this->actingAs($this->admin())->postJson(route('parties.quick-store'), [
            'role' => 'customer',
            'name' => 'New Buyer',
            'phone' => $existing->phone,
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertSame(1, Party::count());
    }

    public function test_quick_add_requires_party_create_permission(): void
    {
        $this->actingAs($this->userWithRole('HR'))->postJson(route('parties.quick-store'), [
            'role' => 'customer',
            'name' => 'Nope',
            'phone' => '01911000333',
        ])->assertForbidden();
    }

    public function test_sales_order_form_shows_the_quick_add_button(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('sales-orders.create'))
            ->assertOk()->assertSee('New Customer');
    }
}
