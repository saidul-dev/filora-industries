# Sales Order Excel Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import a customer's thread-order Excel into a pending Sales Order via a preview screen, carry Style/Color/Length/Remarks per line through Sales Order and PI, keep sales in USD and convert to BDT at delivery.

**Architecture:** Schema + model changes first, then the manual Sales Order / PI line fields, then the delivery USD rate. The import is a parser service (sheet → plain array), a matcher service (names → Party/Product via normalised name or `import_aliases`), and a controller with upload → preview → store, reusing SalesOrderController's item-creation path.

**Tech Stack:** Laravel 12, PHP 8.2, SQLite, Blade + Alpine.js (Vite build committed to `public/build`), PHPUnit feature tests, new dependency `phpoffice/phpspreadsheet`.

**Spec:** `docs/superpowers/specs/2026-10-31-sales-order-excel-import-design.md`

## Global Constraints

- Sales Orders and PIs are always USD; no currency field; company settings untouched.
- Line `unit_price` stores 4 decimals; line subtotal = `round(qty × unit_price, 2)`; order totals decimal(14,2).
- Color is free text only — no variants, stock or price by color.
- Same product may appear on many lines (no `distinct` rule) on SO and PI.
- Delivery ledger amounts are BDT = `round(USD amount × usd_rate, 2)`.
- Import permission: `sales.create`. Upload `.xlsx`/`.xls`, max 5 MB, first sheet, one order per file.
- Nothing is created (party, product, alias, order) without the user seeing it on the preview.
- After any JS/Blade-class change run `npm run build` and commit `public/build` (shared-hosting deploys use committed assets).

## Review Focus

1. Price cells formatted to 2 decimals but holding more (0.2186) — import must store the raw value; total 14.21 for 65 cones.
2. Dates as real Excel serials vs text `5/10/2026` — must both read as 5 Oct 2026 (d/m/Y), never 10 May.
3. Trailing rows after the table (blank rows, a grand-total row with no Style/Description) — must stop, not import a junk line.
4. Same description spelled with extra spaces/case (`100%  spun polyester, 50/2`) — must match the same product.
5. Preview submitted after the session payload expired / token tampered — must redirect to upload with an error, not 500.

---

### Task 1: Schema, models and aliases

**Files:**
- Create: `database/migrations/2026_11_01_000001_add_import_fields_to_sales_documents.php`
- Create: `database/migrations/2026_11_01_000002_create_import_aliases_table.php`
- Create: `app/Models/ImportAlias.php`
- Modify: `app/Models/SalesOrder.php`, `SalesOrderItem.php`, `ProformaInvoiceItem.php`, `Delivery.php`, `Party.php`, `Product.php`
- Test: `tests/Feature/ImportAliasTest.php`

**Interfaces:**
- Produces: columns per spec "Data changes" table — `sales_orders.buyer_name`, `sales_orders.delivery_start_date`; on both `sales_order_items` and `proforma_invoice_items`: `style`, `color`, `length_per_unit` (decimal 14,2), `remarks`, `unit_price` decimal(14,4); `deliveries.usd_rate` decimal(14,4) default 1, `deliveries.sale_amount_usd` decimal(14,2) default 0 (backfilled = `sale_amount`).
- Produces: `import_aliases(id, aliasable_type, aliasable_id, alias, normalized, timestamps)`, unique `(aliasable_type, normalized)`.
- Produces: `ImportAlias::normalize(string $name): string` — lowercase, `limited`→`ltd`, strip everything except letters/digits/`%`/`/`, collapse whitespace, trim.
- Produces: `ImportAlias::remember(Model $record, string $alias): void` — upsert on `(type, normalized)` so re-pointing replaces.
- Produces: `Party::aliases()` and `Product::aliases()` — `morphMany(ImportAlias::class, 'aliasable')`.
- Models: new fields in `$fillable`; item `unit_price` cast `decimal:4`; `length_per_unit` `decimal:2`; `delivery_start_date` `date`; `usd_rate` `decimal:4`, `sale_amount_usd` `decimal:2`.

- [ ] **Step 1: Write failing tests** `test_normalize_treats_limited_punctuation_and_case_alike` (`normalize('Mosharaf Apparels Studio Limited')` === `normalize('MOSHARAF  apparels studio ltd.')`; `normalize('100%  Spun Polyester, 50/2')` === `normalize('100% spun polyester 50/2')`), `test_remember_repoints_an_existing_alias` (remember party A then party B for same spelling → one row, points to B), `test_item_price_keeps_four_decimals` (create SO item with `unit_price` 0.2186 → refreshed value `'0.2186'`).
- [ ] **Step 2:** `php artisan test --filter=ImportAliasTest` → FAIL.
- [ ] **Step 3:** Write migrations (use `->change()` for `unit_price`; backfill `sale_amount_usd` in the migration) and model changes.
- [ ] **Step 4:** `php artisan test` → all PASS (whole suite, migrations touch shared tables).
- [ ] **Step 5:** Commit `feat: add import fields, USD rate columns and import aliases`.

### Task 2: Manual Sales Order line fields

**Files:**
- Modify: `app/Http/Controllers/Admin/SalesOrderController.php` (validateOrder, createItems, store/update header, edit item rows)
- Modify: `resources/views/admin/sales-orders/_form.blade.php`, `show.blade.php`, `index.blade.php` (labels)
- Modify: `resources/js/app.js` (`saleCart`)
- Test: `tests/Feature/SalesOrderTest.php`

**Interfaces:**
- Produces: `SalesOrderController::createItems(SalesOrder $order, array $rows): void` where each row = `['product' => Product, 'variant' => ?ProductVariant, 'quantity' => float, 'unit_price' => float, 'subtotal' => float, 'style' => ?string, 'color' => ?string, 'length_per_unit' => ?float, 'remarks' => ?string]` — Task 7 builds rows of this shape. Make it `public` or move it to a trait `App\Http\Controllers\Concerns\WritesSalesOrders` with `createSalesOrder(array $header, array $rows): SalesOrder` (header keys = SalesOrder fillable minus totals/order_no; it computes totals, numbering `SO-` + 6-digit id, `status = pending`, `created_by = Auth::id()`). Prefer the trait; store() calls it too.
- Validation adds: `buyer_name` nullable string max 150, `delivery_start_date` nullable date, `items.*.style|color` nullable string max 100, `items.*.length_per_unit` nullable numeric min 0, `items.*.remarks` nullable string max 255, `items.*.unit_price` numeric min 0 (no 2-dp limit); `distinct` removed.
- Form: label "Buyer (Customer)" → "Customer"; new inputs Buyer Name (brand), Delivery Start Date; "Requested Delivery Date" → "Delivery Close Date"; per line Style, Color, Length/Cone (m), Remarks; unit price `step="0.0001"`; amounts headed "USD".
- `saleCart`: allow the same option to be added repeatedly — drop the "chosen" filter, give each line a `uid` (incrementing counter) used as `:key` and in `removeItem(uid)`; new line fields default `''`; edit/old() rehydration passes the four fields through.

- [ ] **Step 1: Write failing tests** `test_same_product_can_appear_on_several_lines_with_style_and_color` (two lines same product, colors `0160`/`0298`, style `TS472302`, length 2000, remarks → 2 items saved with those values), `test_unit_price_keeps_four_decimals_and_totals_round_per_line` (65 × 0.2186 → subtotal 14.21), `test_buyer_name_and_delivery_start_date_are_saved`.
- [ ] **Step 2:** `php artisan test --filter=SalesOrderTest` → new tests FAIL.
- [ ] **Step 3:** Implement controller/trait, views, JS.
- [ ] **Step 4:** `php artisan test --filter=SalesOrderTest` → PASS; `npm run build`; open create form in browser, add the same product twice, save, see both lines on show page.
- [ ] **Step 5:** Commit (include `public/build`) `feat: style, color, length and remarks on sales order lines`.

### Task 3: Proforma Invoice carries the line fields, USD labels

**Files:**
- Modify: `app/Http/Controllers/Admin/ProformaInvoiceController.php` (store copy, edit rows, validation, update)
- Modify: `resources/views/admin/proforma-invoices/{show,edit/_form,print}.blade.php`, `resources/js/app.js` (`piCart`)
- Test: `tests/Feature/ProformaInvoiceTest.php`

**Interfaces:**
- Consumes: Task 1 PI item columns.
- PI store copies `style, color, length_per_unit, remarks` from each SO item; PI edit validates them like Task 2 and drops `distinct`; `piCart` gets the same uid/repeat change as `saleCart`.
- Print: line columns Style, Item, Color, Qty, Length/Cone, Price (USD), Total (USD), Remarks; "In Words" uses `AmountInWords::convert($total, 'US Dollar')` regardless of company currency.

- [ ] **Step 1: Write failing tests** `test_pi_copies_style_color_length_and_remarks_from_sales_order` and `test_pi_print_amount_in_words_is_us_dollar` (company currency BDT; print contains `US Dollar`, not `Taka`).
- [ ] **Step 2:** `php artisan test --filter=ProformaInvoiceTest` → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `php artisan test` → PASS; `npm run build`.
- [ ] **Step 5:** Commit `feat: carry sales order line details onto the proforma invoice`.

### Task 4: Delivery USD rate and BDT ledger; PI due in BDT

**Files:**
- Modify: `app/Http/Controllers/Admin/DeliveryController.php` (create prefill, store), `app/Models/ProformaInvoice.php` (`dueAmount`, new `billedAmount`)
- Modify: `resources/views/admin/deliveries/{create,show,print}.blade.php`
- Test: `tests/Feature/DeliveryTest.php`, `tests/Feature/PiCollectionTest.php`

**Interfaces:**
- Validation: `usd_rate` required numeric `gt:0`. Create view prefill: `Delivery::latest('id')->value('usd_rate')` or blank.
- Store: `sale_amount_usd` = today's computed `$saleAmount`; `sale_amount` = `round($saleAmountUsd * usd_rate, 2)`; ledger receivable/revenue use `sale_amount`. COGS unchanged. Narration appends `@ {rate}`.
- `ProformaInvoice::billedAmount(): float` = sum of its deliveries' `sale_amount` (BDT). `dueAmount()` = `round(billedAmount() - collectedAmount(), 2)`.
- Show/print: USD line prices, then "USD Total", "Rate", "BDT Total".

- [ ] **Step 1: Write failing tests** `test_delivery_posts_receivable_in_bdt_using_usd_rate` (PI line 100 × 2.5 USD, rate 122 → `sale_amount_usd` 250, `sale_amount` 30500, ledger AR debit 30500), `test_delivery_requires_a_positive_usd_rate`, and update `PiCollectionTest` due assertion to the BDT definition (due = delivered BDT − collected).
- [ ] **Step 2:** run both test classes → FAIL.
- [ ] **Step 3:** Implement; fix any other existing delivery tests by adding `usd_rate` 1 to their payloads (values then unchanged).
- [ ] **Step 4:** `php artisan test` → PASS.
- [ ] **Step 5:** Commit `feat: convert deliveries to BDT with a USD rate`.

### Task 5: Excel parser

**Files:**
- Run: `composer require phpoffice/phpspreadsheet`
- Create: `app/Services/SalesOrderImport/SheetParser.php`, `app/Services/SalesOrderImport/SheetParseException.php`
- Test: `tests/Feature/SalesOrderImport/SheetParserTest.php`, helper `tests/Feature/Concerns/BuildsOrderWorkbooks.php`

**Interfaces:**
- Produces: `SheetParser::parse(string $path): array` returning
  `['header' => ['customer_name','customer_address','buyer_name','order_number','booking_date' (Y-m-d|null),'delivery_start_date','delivery_close_date','product_banner','from' => ['name','designation','company','address'],'email','contact','cc' (string)], 'lines' => [['sl','style','description','color','quantity'(float),'length_per_unit'(?float),'unit_price'(float raw),'sheet_total'(?float),'remarks'(?string),'warning'(?string)]]]`.
- Throws `SheetParseException` (user-facing message) when no `Sl. No` header row or zero lines.
- Rules: labels matched case-insensitive ignoring trailing `:`/spaces; value = first non-empty cell right of the label on the same row; "From"/"CC" blocks = that value plus following rows' same column until a blank or the next label. `Product:` banner value may share the cell (`Product:  T-SHIRT/POLO-SHIRT`) — take text after the colon. Table columns located by header text containing `style`, `item description`, `color`, `booking`, `length`, `price`, `total`, `remarks`. Use `getCalculatedValue()` for numbers. Dates: numeric → `ExcelDate::excelToDateTimeObject`; text → `d/m/Y` via `Carbon::createFromFormat` (also accept `d-m-Y`, `d.m.Y`). Color kept as text (`0160` must not become `160` — if the cell is numeric, use the formatted value). Stop at first row with empty style and description. Warning when `abs(round(qty×price,2) − sheet_total) > 0.01`.
- `BuildsOrderWorkbooks::makeOrderWorkbook(array $lines, array $headerOverrides = []): string` writes an `.xlsx` mirroring the screenshot layout (title rows 1–3, header labels in col A with values in col B from row ~6, right-side labels in col I/J, table header row with `Sl. No#`, then lines, then 2 blank rows and a totals row with blank style/description) to a temp path.

- [ ] **Step 1: Write failing tests**: `test_reads_header_fields` (customer name, buyer `BHPC & HENRY COTTON`, order `MASL : 1839-1859`, booking `2026-09-22`, close date text `5/10/2026` → `2026-10-05`), `test_reads_lines_with_raw_price_and_text_color` (price cell 0.2186 formatted `$0.00` → 0.2186; color `0160` stays `0160`), `test_stops_before_totals_row`, `test_flags_total_mismatch`, `test_rejects_sheet_without_line_table`.
- [ ] **Step 2:** `php artisan test --filter=SheetParserTest` → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** → PASS.
- [ ] **Step 5:** Commit (incl. composer files) `feat: parse customer order sheets`.

### Task 6: Customer and product matching

**Files:**
- Create: `app/Services/SalesOrderImport/RecordMatcher.php`
- Test: `tests/Feature/SalesOrderImport/RecordMatcherTest.php`

**Interfaces:**
- Consumes: `ImportAlias::normalize`, `aliases()` relations.
- Produces: `RecordMatcher::customer(string $name): ?Party` (active customers; normalised name equality first, then alias); `RecordMatcher::product(string $description): ?Product` (active, `has_variants = false`, types `manufactured|direct_purchase`; same order).

- [ ] **Step 1: Write failing tests** `test_matches_customer_by_normalised_name`, `test_matches_customer_by_alias`, `test_ignores_non_customer_parties`, `test_matches_product_with_spacing_and_case_differences`, `test_returns_null_when_nothing_matches`.
- [ ] **Step 2:** → FAIL. **Step 3:** implement (load candidates and compare normalised names in PHP; data is small). **Step 4:** → PASS.
- [ ] **Step 5:** Commit `feat: match imported customer and product names`.

### Task 7: Import upload → preview → create

**Files:**
- Create: `app/Http/Controllers/Admin/SalesOrderImportController.php`
- Create: `resources/views/admin/sales-orders/import/{upload,preview}.blade.php`
- Modify: `routes/web.php` (next to sales-orders routes), `resources/views/admin/sales-orders/index.blade.php` + `create.blade.php` (Import button)
- Test: `tests/Feature/SalesOrderImport/SalesOrderImportTest.php`

**Interfaces:**
- Consumes: `SheetParser::parse`, `RecordMatcher`, `ImportAlias::remember`, `WritesSalesOrders::createSalesOrder` (Task 2).
- Routes (middleware `permission:sales.create`): `GET sales-orders/import` → `create` (name `sales-orders.import`); `POST sales-orders/import` → `upload` (`sales-orders.import.upload`); `GET sales-orders/import/{token}` → `preview` (`sales-orders.import.preview`); `POST sales-orders/import/{token}/customer` → `createCustomer`; `POST sales-orders/import/{token}/product` → `createProduct` (field `description`); `POST sales-orders/import/{token}` → `store` (`sales-orders.import.store`). Register before `Route::resource('sales-orders', …)` so `import` isn't taken as an id.
- Payload: parsed array stored in session under `sales_order_import.{token}` (token = `Str::uuid()`); missing/unknown token → redirect to `sales-orders.import` with error "This import has expired — upload the file again."
- `createCustomer`: Party from header (name, address, email, phone = contact, contact_person = from.name, designation = from.designation, `is_customer`, `is_company`, `status` true); redirect back to preview (matcher now finds it).
- `createProduct`: Product (name = description, sku `THR-` + next id padded 4, `product_type` manufactured, unit `Cone` firstOrCreate (`short_name` `Cone`) used for stock/sale/purchase units with conversion 1, category `Sewing Thread` firstOrCreate, prices 0, status true); redirect back.
- `store` validation: `party_id` required customer; `products` array keyed by description md5 → product id, required for every distinct description; header fields as Task 2 (`order_date` required). For a picked party/product whose normalised name ≠ the sheet text, call `ImportAlias::remember`. Note = `Product: {banner}` + newline + `CC: {cc}` (omit empty). Discount 0. Lines → Task 2 row shape. Forget the session key; redirect to `sales-orders.show` with success "Imported {n} line(s) from Excel."
- Preview shows: duplicate warning when a SalesOrder exists with same `party_id` + `buyer_reference`; per-line warnings; submit disabled until customer + all products chosen (Alpine), server re-validates.

- [ ] **Step 1: Write failing tests** `test_upload_redirects_to_preview_with_matched_customer`, `test_store_creates_pending_order_with_all_lines_and_raw_prices` (3 lines incl. a repeated product; total equals sum of sheet totals), `test_manual_customer_pick_saves_alias_and_next_import_matches`, `test_create_customer_from_sheet`, `test_create_product_from_description`, `test_store_rejects_unresolved_product`, `test_expired_token_redirects_to_upload`, `test_duplicate_order_number_shows_warning`, `test_user_without_sales_create_gets_403`.
- [ ] **Step 2:** → FAIL. **Step 3:** implement. **Step 4:** `php artisan test` → all PASS; `npm run build`; in the browser upload a workbook generated by `BuildsOrderWorkbooks` (save one to scratchpad via tinker), resolve, create, check the order page.
- [ ] **Step 5:** Commit `feat: import sales orders from customer Excel`.

### Task 8: Docs and wrap-up

**Files:** Modify `docs/order-to-delivery.md` (USD on SO/PI, rate at delivery, BDT ledger, PI due definition, Excel import section).

- [ ] **Step 1:** Update docs. **Step 2:** `php artisan test` → PASS; `npm run build` clean. **Step 3:** Commit `docs: describe USD orders, delivery rate and Excel import`.
