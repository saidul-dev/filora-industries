# Order-to-Delivery Cycle (Filora Package A)

Implementation notes for the Package A "Core Operations" scope of the
Filora Industries Ltd. feature list: Sales Order → Proforma Invoice →
Procurement / Production → Inventory → Delivery → Accounts. Builds on the
existing stock ledger ([stock-movements.md](stock-movements.md)) and the
double-entry ledger ([accounting-foundation.md](accounting-foundation.md)) —
nothing here keeps its own balance counter.

```
Sales Order (Pending) ─confirm─▶ Confirmed ─generate─▶ Proforma Invoice (Draft)
                                                        │ issue
                                                        ▼
                                  LC attached ◀── Issued ──▶ Requisition / Production Order
                                                        │      (first one ⇒ In Procurement/Production)
Requisition ─approve─▶ Purchase Order ─receive─▶ Goods Receipt (stock in @ Factory/Office, Dr Inventory)
Production Order ─issue─▶ BOM materials out ─complete─▶ finished goods in (@ production site)
Delivery (stock out @ any site, Dr AR / Cr Revenue, Dr COGS / Cr Inventory) ⇒ Partially Delivered / Delivered
Collection against the PI (Dr Cash/Bank / Cr AR) ─▶ PI shows collected / due ─▶ Close
```

## Roles & permissions

`RolePermissionSeeder` adds four permission modules and two roles:

| Module | Actions | Gates |
|---|---|---|
| `sales` | view, create, edit, delete, approve, deliver | Sales Orders, PIs (approve = confirm SO, generate/issue/cancel/close PI, attach LC, see every Sales Person's orders), deliveries (deliver) |
| `requisitions` | view, create, edit, delete, approve | Inventory Requisitions |
| `purchases` | view, create, edit, delete, receive | Purchase Orders, Goods Receipt (receive) |
| `production` | view, create, edit, delete, approve | BOM (edit), Production Orders (create = issue, edit = start, approve = complete, delete = cancel) |

| Role | What it gets |
|---|---|
| Admin | everything |
| Sales Person | `sales.view/create/edit/delete`, parties view/create, inventory view. Sees **only the Sales Orders/PIs where they are the responsible Sales Person** (`User::seesOnlyOwnSales()`, `SalesOrder::scopeVisibleTo`, `ProformaInvoice::scopeVisibleTo`) |
| Accountant ("Accounts") | accounts.*, `sales.view`, `requisitions.view`, `purchases.view/create/edit`, inventory view — purchase cost entries, collections, valuation |
| Factory Manager | production.*, requisitions view/create/edit/delete, `purchases.view/receive`, `sales.view`, inventory view/create |

Demo users: `sales@businesserp.test`, `factory@businesserp.test` (password
`password`), alongside the existing admin/owner/manager/hr/accountant.

## Products & BOM

- `products.product_type` — `manufactured` / `direct_purchase` /
  `raw_material` (`Product::TYPES`). Shown and filterable on the product
  list; set on the product form.
- `bom_items` — one row per material consumed per **one stock unit** of a
  manufactured product (`Production → Bill of Materials`,
  `BomController`). Only simple (non-variant) products can be materials;
  a BOM applies to every variant of its product.

## Sales Order (`sales_orders`, `SalesOrderController`)

Pending → Confirmed → PI Generated (or Cancelled). Freely editable while
Pending (quantities, add/remove lines); `sales.approve` confirms. No stock
or ledger effect.

## Proforma Invoice (`proforma_invoices`, `ProformaInvoiceController`)

Generated from a confirmed SO as a **Draft**: lines copied, each with a
planned `fulfillment` — `stock` if enough is on hand across all sites,
else `production` (manufactured) or `purchase`. The draft can be edited
(the PI is the *final* list to deliver), then **Issued** (locked).

Statuses (`ProformaInvoice::STATUSES`): Draft, Issued, LC Issued, In
Procurement/Production (set by the first requisition/production order),
Ready for Delivery (manual), Partially Delivered / Delivered (set by
deliveries), Closed (manual, once delivered), Cancelled (only before
anything happened against it — hands the SO back to Confirmed).

**LC**: number, bank, date, expiry, amount and a PDF/image stored on the
**private** `local` disk (`lc-documents/`), served only through
`proforma-invoices.lc-file`. Attaching to an Issued PI moves it to LC
Issued; a PI already further along keeps its status.

Requisitions, Purchase Orders, Production Orders and Deliveries can only be
created under a PI in `ProformaInvoice::ACTIVE_STATUSES`.

## Inventory Requisition (`purchase_requisitions`, `PurchaseRequisitionController`)

Raised under a PI for a receiving site. Picking the PI pre-fills it from
`PiFulfillmentPlanner::purchaseSuggestions()`: purchase lines' undelivered
quantity + production lines' BOM materials for what no Production Order
covers yet, **net of stock on hand (all sites) and of open requisitions
under the same PI**. Pending (editable) → Approved / Rejected (with
reason) → Ordered.

## Purchase Order & Goods Receipt (`purchases`, `purchase_receipts`, `PurchaseController`)

Only from an approved requisition (one live PO per requisition; cancelling
an untouched PO reopens it). Vendor is either a registered supplier
(`party_id`) or a **one-time vendor** (name/phone only).

Each Goods Receipt (GRN, partial receipts allowed) records the receiving
site and posts:

- one `purchase` stock movement per line at that site (feeds the average cost);
- one `purchase` ledger voucher: **Dr Inventory / Cr Accounts Payable
  (supplier)** or **Cr the cash/bank account** a one-time vendor was paid
  from. This is the "purchase cost recorded in Accounts".

## Production Order (`production_orders`, `ProductionOrderController`)

Always under a PI line for a manufactured product, at a production site
(defaults to the Factory). Quantity is capped at
`ProformaInvoiceItem::quantityToProduce()`.

- **Issue**: BOM snapshotted into `production_order_materials`; every
  material must be on hand at the site, then one `production_consumption`
  movement per material is posted (at its current average cost).
- **Start**: Not Started → In Progress.
- **Complete**: enter the produced quantity (≤ planned); one `production`
  movement brings the finished goods in at the site, unit cost =
  material cost ÷ produced quantity.
- **Cancel** (open orders only): materials come back as
  `production_return` movements **without a unit cost**, so the materials'
  average cost isn't skewed.

No ledger posting — value moves between raw material and finished goods
inside the same Inventory account.

## Delivery (`deliveries`, `DeliveryController`)

Against a live PI, from one site per challan (several challans per PI are
fine, so stock, purchased and produced goods from Factory and Office can
all be delivered). Quantity ≤ the line's remaining and ≤ stock at the
site. Each challan posts one `sale` movement per line and one `sale`
ledger voucher:

- **Dr Accounts Receivable (buyer) / Cr Sales Revenue** at the PI price
  net of the PI discount (pro rata);
- **Dr Cost of Goods Sold / Cr Inventory** at average cost.

Then the PI becomes Partially Delivered or Delivered. Printable challan.

## Collections against a PI

`collections.proforma_invoice_id` (nullable). Recording a Collection from
the PI page (or picking the PI on the Collection form) still posts **Dr
Cash/Bank / Cr Accounts Receivable**; the PI shows total, delivered value,
collected and due. Collections before delivery are an advance (negative
receivable) until the delivery's receivable offsets them.

## Inventory views

- **Stock by Location** (`stock.locations`) — every raw material /
  finished good with quantity per site side by side, consolidated total,
  average cost and value, plus value per location.
- Product stock history links each movement to its GRN, Production Order
  or Delivery Challan.
- The dashboard's Order-to-Delivery Pipeline tiles show the open work per
  stage for the user's permissions.

## Not in Package A

Stage-by-stage production tracking, HRM/Payroll and Asset Management are
Package B. (The base branch already ships an HRM module; Package A does not
change it.)
