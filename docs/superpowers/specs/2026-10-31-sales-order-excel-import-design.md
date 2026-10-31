# Sales Order Excel Import — Design

Date: 2026-10-31
Status: Draft — awaiting approval

## Intent

Filora's customers (garment factories) send thread orders as an Excel sheet
in a fixed layout (sample: Mosharaf Apparels Studio Ltd., order
`MASL : 1839-1859`). Today someone has to re-type every line into a Sales
Order. We want to upload that Excel, check it on a preview screen, and get a
pending Sales Order with every line — and the manual Sales Order form must
carry exactly the same per-line information, so an imported order and a
typed one look the same.

The sample sheet:

- **Header block** — Customer Name, Customer Address, Buyer Name (the end
  brand, e.g. `BHPC & HENRY COTTON`), Order Number, Booking Date, Delivery
  Start Date, Delivery Close Date, the "From" contact (name, designation,
  email, phone), a CC contact, and a "Product:" banner (`T-SHIRT/POLO-SHIRT`).
- **Line table** (header row starts with `Sl. No#`) — Style, Item
  Description, Color, Booking Quantity (in Cone), Length/Cone (in Meter),
  Price/Cone (in USD), Total Price (in USD), Remarks.

## Decisions made with the user

1. **Customer identification — auto-match, confirm on preview, remember
   aliases.** After upload a preview screen shows the matched customer:
   - Excel "Customer Name" is matched against customer Parties by
     normalised name (case, punctuation, whitespace, `Ltd`/`Limited`/`Ltd.`
     treated as equal) and against saved aliases.
   - On a match the customer is pre-selected; otherwise the user picks one
     from the customer dropdown, **or** creates a new Party in one click
     from the Excel header (name, address, email, phone, contact person +
     designation from the "From" block; `is_customer = true`,
     `is_company = true`).
   - When the user picks a customer manually, the Excel spelling is saved
     as that Party's alias so the next import matches automatically.
2. **Buyer Name is not a Party.** It is a new free-text field on the Sales
   Order (`buyer_name`). The existing form label "Buyer (Customer)" for
   `party_id` becomes "Customer", to avoid confusion.
3. **Order Number → `buyer_reference`** (existing field).
4. **Color is information only** — a free-text field per line. No variants,
   no per-color stock, no per-color price.
5. **Sales Orders and Proforma Invoices are always in USD.** No currency
   field. Everything else (purchases, expenses, ledger, collections) stays
   in the company-settings currency (BDT). Company settings are not changed.
6. **USD → BDT happens at delivery.** The Delivery form gets a required
   "USD rate" field (BDT per 1 USD). The receivable / sales-revenue ledger
   lines are posted in BDT (`USD amount × rate`). Collections are entered in
   BDT as today, so they settle the receivable directly.

## Calls I made (user can override)

- **PI carries the new line fields too.** Style, Color, Length/Cone and
  Remarks are copied from Sales Order lines to PI lines and shown on the PI
  screen, PI edit form and PI print. Reason: the same product repeats on
  many lines; without style/color the PI lines are indistinguishable.
- **Products are matched the same way as customers.** Excel "Item
  Description" is matched against product name and saved product aliases.
  Unmatched descriptions are resolved on the preview — pick an existing
  product (alias saved) or create one in one click (name = description,
  type `manufactured`, unit `Cone` — created if missing, category
  `Sewing Thread` — created if missing, selling price 0).
- **Aliases live in one polymorphic table** `import_aliases`
  (`aliasable_type`, `aliasable_id`, `alias` (original), `normalized`;
  unique on `aliasable_type + normalized`). Re-pointing an alias to a
  different record replaces it.
- **Dates:** Booking Date → `order_date`; Delivery Close Date → existing
  `delivery_date`; new nullable `delivery_start_date`. Dates are read
  whether the cell is a real Excel date or text in `d/m/Y` (`5/10/2026` =
  5 Oct 2026).
- **The "Product:" banner** (`T-SHIRT/POLO-SHIRT`) and the CC contact go
  into the order `note` as text lines — no new columns.
- **Duplicate check:** if a Sales Order for the same customer already has
  the same `buyer_reference`, the preview shows a warning; it does not
  block.
- **One order per file, first worksheet.** `.xlsx` and `.xls` accepted,
  max 5 MB.
- **PI "due" becomes BDT-based.** `ProformaInvoice::dueAmount()` today is
  `total_amount (USD) − collections (BDT)`, which is meaningless once the
  PI is USD. It becomes `sum of its deliveries' BDT sale amounts −
  collections`, i.e. what has actually been billed in BDT minus what was
  received. The collection form's prefill keeps using it.

## Data changes

| Table | Change |
|---|---|
| `sales_orders` | add `buyer_name` (string, nullable), `delivery_start_date` (date, nullable) |
| `sales_order_items` | add `style`, `color` (string, nullable), `length_per_unit` (decimal 14,2, nullable — meters per cone), `remarks` (string, nullable); `unit_price` → decimal(14,4) |
| `proforma_invoice_items` | same four fields; `unit_price` → decimal(14,4) |
| `deliveries` | add `usd_rate` (decimal 14,4); `sale_amount` now holds BDT; add `sale_amount_usd` (decimal 14,2). Existing rows: `usd_rate = 1`, `sale_amount_usd = sale_amount` (the system is still in development with no live data, so no real-data conversion is needed) |
| `import_aliases` | new (see above) |

Subtotals/totals stay decimal(14,2), computed as `round(qty × unit_price, 2)`
per line as today.

**Why 4-decimal prices:** the sample shows `$0.22` on every line but totals
of 14.21 for 65 cones, 11.18 for 51 — the real price is ~0.2186; Excel only
displays 2 decimals. The importer must read the cell's raw value, and the
columns must keep 4 decimals so our totals match the customer's sheet.

## Behaviour

### Import flow (`sales.create` permission)

1. **Upload** — "Import from Excel" button on the Sales Orders index (and
   create page) → upload form.
2. **Parse** — PhpSpreadsheet (new dependency `phpoffice/phpspreadsheet`).
   - Header values are found by label, not fixed cell address: scan the
     top rows for cells whose text matches a known label (`Customer Name`,
     `Customer Address`, `Buyer Name`, `Order Number`, `Booking Date`,
     `Delivery Start Date`, `Delivery Close Date`, `From`, `CC`, `email`,
     `Contact`, `Product:`) and take the next non-empty cell(s) to the
     right (the "From" block spans several rows below the label).
   - Line table: find the row whose first non-empty cell is `Sl. No#`;
     map columns by header text (tolerant of line breaks/spacing). Read
     rows below until the first row with no Style and no Item Description.
   - Numeric cells use the raw calculated value, not formatted text.
   - Per line, compare `round(qty × price, 2)` with the sheet's Total
     Price; mismatch > 0.01 → warning on that line.
   - Unparseable file / no line table → back to upload with a clear error.
3. **Preview** — parsed data is stored server-side (session or a temp file
   keyed by a token; builder's choice) and shown:
   - Customer panel: Excel name, match status, customer select, "Create
     new customer from this sheet" button.
   - Product panel: one row per distinct Item Description with match
     status, product select, "Create product" button.
   - Header fields (editable): order date, delivery start/close, buyer
     name, buyer reference, note, sales person (same rules as the form).
   - Lines table read-only, with warnings.
   - "Create Sales Order" is disabled until customer and every
     description are resolved.
4. **Create** — in one transaction: save aliases for manual picks, create
   the pending Sales Order and its items through the same code path as
   the manual form (same numbering `SO-000123`, same totals), redirect to
   the order's show page. From there it behaves exactly like a manual
   order (edit while pending, confirm, PI…).

### Manual Sales Order form

- Each line gets Style, Color, Length/Cone, Remarks inputs next to
  product / quantity / price. Price input accepts 4 decimals.
- The `distinct` rule on `items.*.item` is removed from Sales Order **and**
  PI validation — the same product may appear on many lines.
- Header gets Buyer Name and Delivery Start Date.
- Amounts on Sales Order and PI screens/prints are labelled USD (`$`);
  PI print "In Words" always uses "US Dollar", not the company currency.

### Delivery

- Form: required `usd_rate`, prefilled with the most recent delivery's
  rate (blank if none).
- `sale_amount_usd` = what `sale_amount` is computed as today;
  `sale_amount` = `round(sale_amount_usd × usd_rate, 2)` and that BDT
  figure goes to the ledger. `delivery_items.unit_price` stays USD.
- Delivery show/print shows USD line prices plus rate and BDT total.

## Non-goals

- No multi-currency in purchases, ledger or settings.
- No per-color stock, variants or pricing.
- No bulk import of several orders/files at once.
- No other Excel layouts; only this customer format (label-based parsing
  gives some tolerance, nothing more).
- No exchange-rate history table or automatic rate fetching.

## Anti-goals (would be a failure even if it "works")

- Imported order total differing from the sheet's total.
- An import that silently creates customers/products without the user
  seeing it on the preview.
- USD figures reaching the BDT ledger unconverted.

## Left to the builder

- Session vs temp-file storage for the preview payload.
- Exact UI layout of the preview (follow existing admin styling).
- Whether parsing lives in a service class (suggested
  `App\Services\SalesOrderImport\…`) — follow existing `app/Services`.
- The parser is built from the screenshot of the sample sheet; the real
  customer file was not available. Verify against a real file before
  delivery to the client.
- Tests: feature tests in `tests/Feature` in the style of
  `SalesOrderTest` / `DeliveryTest`, generating the fixture workbook in
  the test with PhpSpreadsheet; cover matching, alias saving, raw-price
  precision, duplicate products on lines, delivery rate conversion and PI
  due amount.
- Update `docs/order-to-delivery.md` for the USD/rate rules.
