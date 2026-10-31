<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\WritesSalesOrders;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ImportAlias;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Services\SalesOrderImport\RecordMatcher;
use App\Services\SalesOrderImport\SheetParseException;
use App\Services\SalesOrderImport\SheetParser;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A customer's order sheet (Excel) → a pending Sales Order, via a preview
 * where the user confirms which customer and products the sheet's names
 * mean. Nothing is created until the user asks: the parsed sheet waits in
 * the session under a one-time token. Manual picks are remembered as
 * ImportAliases so the next sheet matches on its own.
 */
class SalesOrderImportController extends Controller implements HasMiddleware
{
    use WritesSalesOrders;

    private const SESSION_KEY = 'sales_order_import';

    public static function middleware(): array
    {
        return [new Middleware('permission:sales.create')];
    }

    /** Preview/store form key for one distinct Item Description. */
    public static function productKey(string $description): string
    {
        return md5(ImportAlias::normalize($description));
    }

    public function create()
    {
        return view('admin.sales-orders.import.upload');
    }

    public function upload(Request $request, SheetParser $parser)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        try {
            $parsed = $parser->parse($request->file('file')->getRealPath());
        } catch (SheetParseException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $token = (string) Str::uuid();
        $request->session()->put(self::SESSION_KEY.'.'.$token, $parsed + [
            'file_name' => $request->file('file')->getClientOriginalName(),
        ]);

        return redirect()->route('sales-orders.import.preview', $token);
    }

    public function preview(Request $request, string $token, RecordMatcher $matcher)
    {
        $sheet = $this->sheet($request, $token);

        if (! $sheet) {
            return $this->expired();
        }

        $header = $sheet['header'];
        $customer = $header['customer_name'] ? $matcher->customer($header['customer_name']) : null;

        $products = collect($sheet['lines'])
            ->unique(fn ($line) => self::productKey($line['description'] ?? ''))
            ->map(fn ($line) => (object) [
                'key' => self::productKey($line['description'] ?? ''),
                'description' => $line['description'],
                'match' => $line['description'] ? $matcher->product($line['description']) : null,
            ])->values();

        $duplicate = $customer && $header['order_number']
            ? SalesOrder::where('party_id', $customer->id)->where('buyer_reference', $header['order_number'])
                ->where('status', '!=', 'cancelled')->first()
            : null;

        $note = collect([
            $header['product_banner'] ? "Product: {$header['product_banner']}" : null,
            $header['cc'] ? "CC: {$header['cc']}" : null,
        ])->filter()->implode("\n");

        return view('admin.sales-orders.import.preview', [
            'token' => $token,
            'sheet' => $sheet,
            'header' => $header,
            'customer' => $customer,
            'products' => $products,
            'duplicate' => $duplicate,
            'note' => $note,
            'customers' => Party::where('is_customer', true)->where('status', true)->orderBy('name')->get(['id', 'name']),
            'productOptions' => Product::where('status', true)->where('has_variants', false)
                ->whereIn('product_type', ['manufactured', 'direct_purchase'])->orderBy('name')->get(['id', 'name', 'sku']),
            'salesPeople' => $this->salesPersonOptions(),
        ]);
    }

    /** One click: a customer Party from the sheet's header block. */
    public function createCustomer(Request $request, string $token)
    {
        $sheet = $this->sheet($request, $token);

        if (! $sheet) {
            return $this->expired();
        }

        $header = $sheet['header'];

        if (! $header['customer_name']) {
            return back()->with('error', 'The sheet has no Customer Name to create a customer from.');
        }

        if (! $header['contact']) {
            return back()->with('error', 'The sheet has no contact number, which every party needs — add the customer under Parties, then pick it here.');
        }

        if ($existing = Party::where('phone', $header['contact'])->first()) {
            return back()->with('error', "{$existing->name} already uses the phone {$header['contact']} — pick that party from the list instead.");
        }

        Party::create([
            'name' => $header['customer_name'],
            'address' => $header['customer_address'],
            'email' => $header['email'],
            'phone' => $header['contact'],
            'contact_person' => $header['from']['name'],
            'designation' => $header['from']['designation'],
            'is_customer' => true,
            'is_company' => true,
            'status' => true,
        ]);

        return redirect()->route('sales-orders.import.preview', $token)
            ->with('success', "Customer {$header['customer_name']} created.");
    }

    /** One click: a manufactured thread product named after a sheet description. */
    public function createProduct(Request $request, string $token)
    {
        $sheet = $this->sheet($request, $token);

        if (! $sheet) {
            return $this->expired();
        }

        $request->validate(['description' => ['required', 'string']]);

        $description = collect($sheet['lines'])->pluck('description')
            ->first(fn ($d) => $d && self::productKey($d) === self::productKey($request->input('description')));

        if (! $description) {
            throw ValidationException::withMessages(['description' => 'That description is not on this sheet.']);
        }

        DB::transaction(function () use ($description) {
            $cone = Unit::firstOrCreate(['short_name' => 'Cone'], ['name' => 'Cone', 'status' => true]);
            $category = Category::firstOrCreate(['name' => 'Sewing Thread']);

            $product = Product::create([
                'name' => $description,
                'sku' => 'PENDING-'.Str::random(8),
                'category_id' => $category->id,
                'product_type' => 'manufactured',
                'stock_unit_id' => $cone->id,
                'purchase_unit_id' => $cone->id,
                'purchase_unit_conversion' => 1,
                'sale_unit_id' => $cone->id,
                'sale_unit_conversion' => 1,
                'estimated_cost' => 0,
                'selling_price' => 0,
                'reorder_level' => 0,
                'status' => true,
            ]);
            $product->update(['sku' => 'THR-'.str_pad($product->id, 4, '0', STR_PAD_LEFT)]);
        });

        return redirect()->route('sales-orders.import.preview', $token)
            ->with('success', "Product {$description} created.");
    }

    public function store(Request $request, string $token)
    {
        $sheet = $this->sheet($request, $token);

        if (! $sheet) {
            return $this->expired();
        }

        $lines = $sheet['lines'];
        $descriptions = collect($lines)->pluck('description')->filter()
            ->unique(fn ($d) => self::productKey($d))
            ->mapWithKeys(fn ($d) => [self::productKey($d) => $d]);

        $rules = [
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'sales_person_id' => ['nullable', 'integer', 'exists:users,id'],
            'order_date' => ['required', 'date'],
            'delivery_start_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'buyer_reference' => ['nullable', 'string', 'max:100'],
            'buyer_name' => ['nullable', 'string', 'max:150'],
            'delivery_terms' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
        foreach ($descriptions->keys() as $key) {
            $rules["products.{$key}"] = ['required', 'integer'];
        }

        $validated = $request->validate($rules, [
            'products.*.required' => 'Pick or create a product for every item description.',
        ]);

        $party = Party::where('is_customer', true)->find($validated['party_id']);

        if (! $party) {
            throw ValidationException::withMessages(['party_id' => 'Pick a party marked as a Customer.']);
        }

        $products = [];
        foreach ($descriptions as $key => $description) {
            $product = Product::where('status', true)->where('has_variants', false)
                ->whereIn('product_type', ['manufactured', 'direct_purchase'])
                ->find($validated['products'][$key]);

            if (! $product) {
                throw ValidationException::withMessages(["products.{$key}" => "Pick a valid product for \"{$description}\"."]);
            }

            $products[$key] = $product;
        }

        $rows = collect($lines)->map(fn ($line) => [
            'product' => $products[self::productKey($line['description'] ?? '')],
            'variant' => null,
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'subtotal' => round($line['quantity'] * $line['unit_price'], 2),
            'style' => $line['style'],
            'color' => $line['color'],
            'length_per_unit' => $line['length_per_unit'],
            'remarks' => $line['remarks'],
        ])->all();

        $order = DB::transaction(function () use ($validated, $rows, $party, $products, $descriptions, $sheet) {
            $this->rememberPick($party, $sheet['header']['customer_name']);
            foreach ($products as $key => $product) {
                $this->rememberPick($product, $descriptions[$key]);
            }

            return $this->createSalesOrder([
                'party_id' => $party->id,
                'sales_person_id' => $this->resolveSalesPerson($validated),
                'order_date' => $validated['order_date'],
                'delivery_start_date' => $validated['delivery_start_date'] ?? null,
                'delivery_date' => $validated['delivery_date'] ?? null,
                'buyer_reference' => $validated['buyer_reference'] ?? null,
                'buyer_name' => $validated['buyer_name'] ?? null,
                'delivery_terms' => $validated['delivery_terms'] ?? null,
                'note' => $validated['note'] ?? null,
            ], $rows);
        });

        $request->session()->forget(self::SESSION_KEY.'.'.$token);

        return redirect()->route('sales-orders.show', $order)
            ->with('success', "Sales Order {$order->order_no} imported from Excel — ".count($rows).' line(s).');
    }

    /** A record picked for a sheet name that doesn't already match it by name. */
    private function rememberPick($record, ?string $sheetName): void
    {
        if ($sheetName && ImportAlias::normalize($record->name) !== ImportAlias::normalize($sheetName)) {
            ImportAlias::remember($record, $sheetName);
        }
    }

    private function sheet(Request $request, string $token): ?array
    {
        return Str::isUuid($token) ? $request->session()->get(self::SESSION_KEY.'.'.$token) : null;
    }

    private function expired()
    {
        return redirect()->route('sales-orders.import')
            ->with('error', 'This import has expired — upload the file again.');
    }
}
