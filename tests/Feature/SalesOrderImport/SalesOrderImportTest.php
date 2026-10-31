<?php

namespace Tests\Feature\SalesOrderImport;

use App\Http\Controllers\Admin\SalesOrderImportController;
use App\Models\ImportAlias;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\BuildsOrderWorkbooks;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class SalesOrderImportTest extends TestCase
{
    use BuildsOrderWorkbooks;
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    private User $admin;

    private const SPUN = '100% Spun Polyester, 50/2';

    private const FILAMENT = '100% Filament Polyester, 150D';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        $this->admin = $this->admin();
    }

    private function lines(): array
    {
        return [
            ['style' => 'TS472302', 'description' => self::SPUN, 'color' => '0160', 'quantity' => 65, 'price' => 0.2186, 'total' => 14.21],
            ['style' => 'TS472302', 'description' => self::SPUN, 'color' => '0298', 'quantity' => 51, 'price' => 0.2192, 'total' => 11.18, 'remarks' => 'Rush'],
            ['style' => 'TS472304', 'description' => self::FILAMENT, 'color' => '0001', 'quantity' => 77, 'price' => 0.2210, 'total' => 17.02],
        ];
    }

    /** Uploads a sheet and returns the preview URL it redirects to. */
    private function upload(array $header = []): string
    {
        $path = $this->makeOrderWorkbook($this->lines(), $header);

        $response = $this->actingAs($this->admin)->post(route('sales-orders.import.upload'), [
            'file' => new UploadedFile($path, 'order.xlsx', null, null, true),
        ]);
        $response->assertRedirect();

        return $response->headers->get('Location');
    }

    private function token(string $previewUrl): string
    {
        return basename(parse_url($previewUrl, PHP_URL_PATH));
    }

    private function storePayload(Party $customer, array $products, array $overrides = []): array
    {
        return array_merge([
            'party_id' => $customer->id,
            'order_date' => '2026-09-22',
            'delivery_start_date' => '2026-09-30',
            'delivery_date' => '2026-10-05',
            'buyer_name' => 'BHPC & HENRY COTTON',
            'buyer_reference' => 'MASL : 1839-1859',
            'note' => 'Product: T-SHIRT/POLO-SHIRT',
            'products' => collect($products)->mapWithKeys(fn (Product $product, string $description) => [
                SalesOrderImportController::productKey($description) => $product->id,
            ])->all(),
        ], $overrides);
    }

    public function test_upload_redirects_to_preview_with_matched_customer(): void
    {
        $customer = $this->makeCustomer('Mosharaf Apparels Studio Limited');
        $this->makeProduct('manufactured', ['name' => self::SPUN]);

        $preview = $this->upload();

        $this->actingAs($this->admin)->get($preview)->assertOk()
            ->assertSee('Mosharaf Apparels Studio Ltd.')
            ->assertSee('value="'.$customer->id.'" selected', false)
            ->assertSee('TS472304')
            ->assertSee('0.2186')
            ->assertSee('BHPC &amp; HENRY COTTON', false)
            ->assertSee('Not matched'); // the filament thread has no product yet
    }

    public function test_store_creates_pending_order_with_all_lines_and_raw_prices(): void
    {
        $customer = $this->makeCustomer('Mosharaf Apparels Studio Ltd.');
        $spun = $this->makeProduct('manufactured', ['name' => self::SPUN]);
        $filament = $this->makeProduct('manufactured', ['name' => self::FILAMENT]);
        $token = $this->token($this->upload());

        $this->actingAs($this->admin)->post(route('sales-orders.import.store', $token),
            $this->storePayload($customer, [self::SPUN => $spun, self::FILAMENT => $filament])
        )->assertSessionHasNoErrors();

        $order = SalesOrder::with(['items' => fn ($q) => $q->orderBy('id')])->first();
        $this->assertSame('SO-000001', $order->order_no);
        $this->assertSame('pending', $order->status);
        $this->assertSame($customer->id, $order->party_id);
        $this->assertSame('BHPC & HENRY COTTON', $order->buyer_name);
        $this->assertSame('MASL : 1839-1859', $order->buyer_reference);
        $this->assertSame('2026-10-05', $order->delivery_date->toDateString());
        $this->assertCount(3, $order->items);
        $this->assertSame([$spun->id, $spun->id, $filament->id], $order->items->pluck('product_id')->all());
        $this->assertSame('0.2186', $order->items[0]->unit_price);
        $this->assertSame('0298', $order->items[1]->color);
        $this->assertSame('Rush', $order->items[1]->remarks);
        $this->assertEquals(2000, (float) $order->items[2]->length_per_unit);
        $this->assertEquals(42.41, (float) $order->total_amount); // 14.21 + 11.18 + 17.02, as on the sheet

        // The upload is single-use.
        $this->actingAs($this->admin)->post(route('sales-orders.import.store', $token),
            $this->storePayload($customer, [self::SPUN => $spun, self::FILAMENT => $filament]));
        $this->assertSame(1, SalesOrder::count());
    }

    public function test_manual_customer_pick_saves_alias_and_next_import_matches(): void
    {
        $customer = $this->makeCustomer('MASL Group');
        $spun = $this->makeProduct('manufactured', ['name' => 'Spun 50/2 thread']);
        $filament = $this->makeProduct('manufactured', ['name' => self::FILAMENT]);

        $this->actingAs($this->admin)->post(route('sales-orders.import.store', $this->token($this->upload())),
            $this->storePayload($customer, [self::SPUN => $spun, self::FILAMENT => $filament])
        )->assertSessionHasNoErrors();

        $this->assertTrue(ImportAlias::where('normalized', ImportAlias::normalize('Mosharaf Apparels Studio Ltd.'))->first()->aliasable->is($customer));
        $this->assertTrue(ImportAlias::where('normalized', ImportAlias::normalize(self::SPUN))->first()->aliasable->is($spun));
        // An exact name match needs no alias.
        $this->assertSame(2, ImportAlias::count());

        $this->actingAs($this->admin)->get($this->upload())->assertOk()
            ->assertSee('value="'.$customer->id.'" selected', false)
            ->assertSee('value="'.$spun->id.'" selected', false);
    }

    public function test_create_customer_from_sheet(): void
    {
        $preview = $this->upload();

        $this->actingAs($this->admin)->post(route('sales-orders.import.customer', $this->token($preview)))
            ->assertRedirect($preview);

        $party = Party::where('name', 'Mosharaf Apparels Studio Ltd.')->first();
        $this->assertTrue($party->is_customer);
        $this->assertTrue($party->is_company);
        $this->assertSame('Baniarchala, Bhabanipur, Gazipur sadar', $party->address);
        $this->assertSame('shoriful@mosharafgroup.com', $party->email);
        $this->assertSame('+8801810-007040.', $party->phone);
        $this->assertSame('SHORIFUL ISLAM', $party->contact_person);
        $this->assertSame('ASST. MANAGER (M&M)', $party->designation);

        $this->actingAs($this->admin)->get($preview)->assertSee('value="'.$party->id.'" selected', false);
    }

    public function test_create_product_from_description(): void
    {
        $preview = $this->upload();

        $this->actingAs($this->admin)->post(route('sales-orders.import.product', $this->token($preview)), [
            'description' => self::FILAMENT,
        ])->assertRedirect($preview);

        $product = Product::where('name', self::FILAMENT)->first();
        $this->assertSame('manufactured', $product->product_type);
        $this->assertSame('Cone', $product->stockUnit->short_name);
        $this->assertSame('Sewing Thread', $product->category->name);
        $this->assertStringStartsWith('THR-', $product->sku);

        // Only descriptions on the sheet can be created.
        $this->actingAs($this->admin)->post(route('sales-orders.import.product', $this->token($preview)), [
            'description' => 'Something else',
        ])->assertSessionHasErrors('description');
    }

    public function test_store_rejects_unresolved_product(): void
    {
        $customer = $this->makeCustomer('Mosharaf Apparels Studio Ltd.');
        $spun = $this->makeProduct('manufactured', ['name' => self::SPUN]);

        $this->actingAs($this->admin)->post(route('sales-orders.import.store', $this->token($this->upload())),
            $this->storePayload($customer, [self::SPUN => $spun])
        )->assertSessionHasErrors('products.'.SalesOrderImportController::productKey(self::FILAMENT));

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_expired_token_redirects_to_upload(): void
    {
        $this->actingAs($this->admin)->get(route('sales-orders.import.preview', 'not-a-real-token'))
            ->assertRedirect(route('sales-orders.import'))
            ->assertSessionHas('error');

        $this->actingAs($this->admin)->post(route('sales-orders.import.store', 'not-a-real-token'), [])
            ->assertRedirect(route('sales-orders.import'));
    }

    public function test_bad_file_returns_to_upload_with_error(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        file_put_contents($path, 'not a workbook');

        $this->actingAs($this->admin)->post(route('sales-orders.import.upload'), [
            'file' => new UploadedFile($path, 'order.xlsx', null, null, true),
        ])->assertSessionHasErrors('file');
    }

    public function test_duplicate_order_number_shows_warning(): void
    {
        $customer = $this->makeCustomer('Mosharaf Apparels Studio Ltd.');
        $spun = $this->makeProduct('manufactured', ['name' => self::SPUN]);
        $filament = $this->makeProduct('manufactured', ['name' => self::FILAMENT]);
        $this->actingAs($this->admin)->post(route('sales-orders.import.store', $this->token($this->upload())),
            $this->storePayload($customer, [self::SPUN => $spun, self::FILAMENT => $filament]));

        $this->actingAs($this->admin)->get($this->upload())->assertOk()
            ->assertSee('already has an order with this order number')
            ->assertSee('SO-000001');
    }

    public function test_user_without_sales_create_gets_403(): void
    {
        $viewer = $this->userWithRole('Accountant');

        $this->actingAs($viewer)->get(route('sales-orders.import'))->assertForbidden();
    }
}
