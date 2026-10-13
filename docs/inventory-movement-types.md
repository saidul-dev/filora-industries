# Inventory Movement Types

Reference list for the Product stock in/out feature (site-wise). Each movement
is tied to a Site — transfers are a single transaction with a `from_site_id`
and `to_site_id`, producing one OUT ledger entry at the source and one IN
ledger entry at the destination.

## IN (increases stock)

| Type | Notes |
|---|---|
| Initial stock | Opening balance when a product/site is first stocked |
| Purchase | Received from a supplier (Sourcing module) |
| Production (finished goods) | Output of a manufacturing/production run |
| Transfer receive | Destination leg of a site-to-site transfer |
| Adjustment addition | Stock count correction — found extra stock |
| Sales Return | Customer returns previously sold goods |
| Production return | Raw materials put back when a Production Order is cancelled before completion — posted without a unit cost so the average cost is untouched |

## OUT (decreases stock)

| Type | Notes |
|---|---|
| Sale | Sold to a customer |
| Transfer sent | Source leg of a site-to-site transfer |
| Adjustment deduction | Stock count correction — shortage found |
| Purchase Return | Returned to supplier |
| Production consumption | Raw material issued/consumed for a production run (pairs with Production IN) |
| Damage/Expiry write-off | Kept separate from generic adjustment for loss/wastage reporting — relevant given `track_expiry` on Product |
| Sample/Free issue | Given away for marketing/promotion, not a sale |
| Internal consumption | Used internally (e.g. office/self-use), not a sale |

## Where each type is posted

See [order-to-delivery.md](order-to-delivery.md) for the Package A flow:
`purchase` by Goods Receipts (`PurchaseReceipt`), `production_consumption` /
`production` / `production_return` by Production Orders (`ProductionOrder`)
— the consumption and the output are separate movements under the same
order, posted at issue and at completion respectively — and `sale` by
Delivery Challans (`Delivery`).

## Open questions for implementation

- Batch/serial/expiry tracking (`track_batch`, `track_expiry`, `track_serial`
  on Product) — do movement records need to carry batch/serial/expiry data?
