<?php

namespace Tests\Feature\SalesOrderImport;

use App\Models\ImportAlias;
use App\Services\SalesOrderImport\RecordMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class RecordMatcherTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
    }

    public function test_matches_customer_by_normalised_name(): void
    {
        $customer = $this->makeCustomer('Mosharaf Apparels Studio Limited');

        $this->assertTrue((new RecordMatcher)->customer('MOSHARAF APPARELS STUDIO LTD.')->is($customer));
    }

    public function test_matches_customer_by_alias(): void
    {
        $customer = $this->makeCustomer('MASL Group');
        ImportAlias::remember($customer, 'Mosharaf Apparels Studio Ltd.');

        $this->assertTrue((new RecordMatcher)->customer('mosharaf apparels studio ltd')->is($customer));
    }

    public function test_ignores_non_customer_parties(): void
    {
        $this->makeSupplier('Mosharaf Apparels Studio Ltd.');

        $this->assertNull((new RecordMatcher)->customer('Mosharaf Apparels Studio Ltd.'));
    }

    public function test_matches_product_with_spacing_and_case_differences(): void
    {
        $thread = $this->makeProduct('manufactured', ['name' => '100% Spun Polyester, 50/2']);
        $this->makeProduct('raw_material', ['name' => '100% Filament Polyester, 150D']);

        $matcher = new RecordMatcher;
        $this->assertTrue($matcher->product('100%  spun polyester 50/2')->is($thread));
        $this->assertNull($matcher->product('100% Filament Polyester, 150D')); // raw materials can't be ordered
    }

    public function test_returns_null_when_nothing_matches(): void
    {
        $this->makeCustomer('Someone Else Ltd.');
        $this->makeProduct('manufactured', ['name' => 'Zipper']);

        $this->assertNull((new RecordMatcher)->customer('Mosharaf Apparels Studio Ltd.'));
        $this->assertNull((new RecordMatcher)->product('100% Spun Polyester, 50/2'));
    }
}
