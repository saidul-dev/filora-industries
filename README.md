# Business ERP — Product Documentation (v0.1 Draft)

**Product type:** Modular, horizontal Business ERP for small and mid-range companies in Bangladesh
**Strategy:** Broad core platform first → observe client demand → deepen the highest-demand vertical → client-specific needs delivered as connected mini-projects (plugins), never inside the core
**Author:** Draft prepared for internal planning and review

---

## 0. `base-version-1.0.0` branch scope note

This branch is trimmed down to a **generic base** meant to seed any vertical
(property management, hospital management, resort management, agency
management, restaurant management, etc.) — not just the trading/retail
business described in the original vision below. It keeps only the
cross-industry-common modules:

- **Auth/Access:** Users, Roles & Permissions, Sites (multi-branch)
- **Company Website:** Home/About/Media/Career/Contact + Contact Messages inbox
- **HRM:** Departments, Designations, Employees, Attendance, Leave, Salary, Payroll
- **Project/Task Management**
- **Accounting foundation:** Bank Accounts, Payments, Collections, Expenses, Income, Fund Transfers, Capital Transactions, Day Book, Trial Balance, Balance Sheet, P&L, Due Report, Parties (contacts)
- **Inventory:** Categories, Brands, Units, Attributes, Products/Variants, Stock movements/adjustments/transfers, Stock report

Removed for being retail/trading/e-commerce-specific (not generic across
verticals): **Sales, Purchases, Purchase Requisitions, Sale Quotations, POS,
Courier/Delivery, Online Shop/Cart/Checkout/Campaign, Product Reviews,
Payment-gateway/Steadfast integrations.** Sections 1–8 below describe the
*original* trading-ERP product vision this codebase started from — read them
as historical context for the modules that remain, not as a live spec for
this branch.

### Filora Industries — Package A (Core Operations)

On top of that base, this codebase now carries the Package A order-to-delivery
cycle for Filora Industries Ltd. (garments accessories manufacturer):
**Sales Orders → Proforma Invoices (with LC) → Inventory Requisitions →
Purchase Orders & Goods Receipt → BOM & Production Orders → Deliveries →
Collections**, with Sales Person / Accounts / Factory Manager roles, product
types (manufactured / direct purchase / raw material), Factory/Office
stock by location and every step posted to stock and Accounts. See
[docs/order-to-delivery.md](docs/order-to-delivery.md).

---

## 1. Product Vision & Positioning

A single ERP platform where a trading house, a small factory, a wholesaler, or a retail chain can each run their business — enabling only the modules they need. Instead of building "Garments ERP" or "Pharmacy ERP" from day one, the platform ships with universal business logic (buy → make/stock → sell → collect → report) and grows vertical depth in whichever industry pulls hardest.

**Positioning statement:** "Mid-range price, enterprise-grade core, custom-fit through modules — not through rewriting code."

### 1.1 Core strategic rules

1. **One codebase.** Every client runs the same core. No client ever gets a forked copy of the core.
2. **Module activation, not module deletion.** Features a client doesn't need are switched off per tenant (feature flags), not removed. "Jader jei feature dorkar nai, tara ta dekhbe na."
3. **Promotion rule for custom requests:** when a client asks for something new —
   - If ≥ 3 clients would plausibly use it → build it inside the core as a standard feature.
   - If it is truly one-client-specific → build it as a separate mini-project (plugin/microservice) that talks to the core only through the public API. It must never touch core tables directly.
4. **Vertical focus is earned, not guessed.** After 10–15 paying clients, measure which industry generates the most demand and revenue, then invest disproportionately there (vertical templates, industry reports, compliance packs).

### 1.2 Target client profile (initial)

| Attribute | Range |
|---|---|
| Company size | 10 – 150 employees |
| Business type | Product-centric: trading, manufacturing, wholesale, distribution, retail |
| Current tooling | Excel, paper khata, Tally/loose accounting software |
| Budget | BDT 3,000 – 25,000 / month subscription + one-time setup |
| Decision maker | Owner / MD directly |

---

## 2. Architecture Principles

### 2.1 Modular monolith first, services later

Start as a modular monolith: one deployable application, but internally split into strict module boundaries (Sourcing, Sales, Inventory, Accounts, etc.). Each module owns its own tables and exposes internal interfaces. This gives plugin-readiness without microservice operational cost on day one.

### 2.2 Multi-tenancy

- One database schema per tenant (or row-level tenancy with `tenant_id` on every table — decide by team experience; schema-per-tenant is safer for data isolation and per-client backup).
- Tenant-level **feature flag registry**: `tenant_features(tenant_id, module_key, enabled, plan_level)`.
- UI navigation, permissions, and API endpoints all respect the flag registry — a disabled module is invisible, not just blocked.

### 2.3 Extension / mini-project architecture

- Core exposes a **versioned REST/GraphQL API** + **webhooks** (events: `sale.created`, `stock.low`, `invoice.paid`, `employee.checked_in`, etc.).
- A client-specific mini-project is a separate small app (own repo, own deploy) that authenticates with a tenant-scoped API key and consumes the API/webhooks.
- Mini-projects may add their own UI as an embedded panel (iframe/module federation) inside the ERP shell.
- **Hard rule:** mini-projects never write to core tables directly. If a mini-project needs new core data, that is a signal the core API needs a new endpoint — raise it as a core feature request.

### 2.4 Cross-cutting requirements (apply to every module)

- **Role-based access control (RBAC):** roles → permissions per module per action (view/create/edit/delete/approve). Owner, Manager, Accountant, Store-keeper, Sales, HR are default roles.
- **Audit log:** every create/update/delete records who, when, old value, new value. Non-negotiable for financial trust.
- **Approval workflows:** configurable "maker–checker" on purchases, payments, salary runs above a threshold.
- **Bangla + English UI**, BDT-first with optional multi-currency later.
- **Offline-tolerant web app / PWA** for shop-floor and field usage where internet is unstable.
- **Attachment support** everywhere (photos of chalan, voucher, NID, etc.).

---

## 3. Module Specifications

### Module 1 — Product Sourcing (Purchase + Production)

Two sourcing paths share one pipeline:

**A. Direct Purchase (trading flow):** buy salable products → into inventory → sell.
**B. Production (manufacturing flow):** buy raw materials → consume in production → output finished goods → into inventory → sell. Raw materials may also be sold directly (dual-use items).

**Features**
- Supplier price quotation & comparison
- Purchase Requisition → Purchase Order → Goods Receipt (GRN) → Purchase Invoice → Supplier Payment (each step optional/configurable — a small shop can jump straight to "Purchase Entry")
- Item types: `raw_material`, `finished_good`, `dual_use`, `service_item`, `asset`
- **Bill of Materials (BOM):** recipe per finished product (materials + quantities + wastage % + labor/overhead cost)
- Production Order: planned qty → material issue → production stages (configurable) → finished goods receipt → actual vs planned cost variance
- Batch/lot number and expiry date tracking (optional per item)
- Landed cost: freight, duty, transport apportioned onto item cost
- Purchase returns (debit note)

**Key entities:** Supplier, Item, PurchaseOrder, GRN, BOM, ProductionOrder, StockBatch

**Config switches:** trading-only mode (hides all production UI), production stages on/off, approval threshold.

---

### Module 2 — Sales (Wholesale + Individual/Retail)

**Features**
- Two selling modes on one engine:
  - **Wholesale:** customer-specific price lists, credit limit, credit days, bulk quantity tiers, delivery scheduling, partial delivery + partial payment
  - **Retail/Individual:** fast POS-style entry, walk-in customer, instant payment, receipt print (thermal 58/80mm)
- Quotation → Sales Order → Delivery Chalan → Invoice → Payment (steps skippable per tenant)
- Discount engine: percentage, flat, item-level, invoice-level, scheme (buy X get Y)
- Sales returns (credit note) with restock decision
- Due/baki tracking per customer with aging (0–30, 31–60, 60+ days)
- Automatic SMS/WhatsApp: invoice link, due reminder, payment confirmation
- Salesperson assignment + commission rules (flat, %, slab)
- VAT handling: VAT-inclusive/exclusive pricing, **NBR Mushak-6.3 compliant invoice format** (critical for BD mid-range clients)

**Key entities:** Customer, PriceList, SalesOrder, Invoice, CreditNote, CommissionRule

---

### Module 3 — Inventory (Purchase + Production + Sale)

The spine of the system — every purchase, production, sale, delivery, and return posts a stock movement here.

**Features**
- Multi-warehouse / multi-branch stock (even if v1 ships with one, model it from day one — retrofitting multi-warehouse is very painful)
- Stock ledger: every movement immutable (type: purchase-in, production-in, production-consume, sale-out, transfer, adjustment, return)
- Valuation methods: Weighted Average (default), FIFO (later)
- Units of measure + conversion (kg ↔ bag, pcs ↔ dozen ↔ carton)
- Reorder level + low-stock alerts (dashboard + SMS/WhatsApp to owner)
- Stock transfer between warehouses with in-transit state
- Physical stock count / audit mode with variance adjustment + approval
- Barcode/QR generation and scan support (works with cheap USB scanners)
- Batch/expiry-wise stock view; near-expiry alert

**Key entities:** Warehouse, StockLedgerEntry, StockTransfer, StockAdjustment, UOMConversion

---

### Module 4 — Customer + Supplier + Employee Management (Contacts)

One unified "Party" model with roles — the same party can be both customer and supplier.

**Features**
- Profile: name, phone(s), NID/BIN/TIN, address(es), photo, documents
- Customer: credit limit, credit days, price list, assigned salesperson, area/route
- Supplier: payment terms, bank details, item catalog, lead time, rating
- Employee: links to HRM (Module 8) — designation, department, joining date, documents
- 360° party view: total business, current due, last transaction, timeline of all interactions
- Notes & follow-up reminders (lightweight CRM: "call Karim bhai Sunday about the due")
- Import from Excel/CSV (crucial for onboarding — clients arrive with Excel lists)

**Key entities:** Party, PartyRole, Address, Document, FollowUp

---

### Module 5 — Accounts (Banking + Non-banking)

**Features**
- Chart of accounts (pre-built BD-friendly template; editable)
- Double-entry under the hood; simple voucher UI on top (Receive, Payment, Journal, Contra)
- Cash accounts + Bank accounts + **MFS accounts (bKash/Nagad/Rocket as first-class account types)** — this is the "non-banking" reality of BD
- Auto-posting: every invoice, purchase, salary run, and expense creates ledger entries automatically — the accountant approves, doesn't retype
- Expense management with categories + attachment of voucher photo
- Cheque register: post-dated cheque tracking, maturity alerts, bounce handling
- Bank reconciliation (manual matching v1; statement import later)
- Receivables/Payables dashboards with aging
- Financial reports: Trial Balance, Profit & Loss, Balance Sheet, Cash Flow, Day Book
- VAT/AIT ledgers and period summary (Mushak support roadmap)
- Loan tracking (bank loan, owner's capital injection/drawing)

**Key entities:** Account, Voucher, LedgerEntry, Cheque, ExpenseCategory

---

### Module 6 — Delivery (Two-way: Receive + Send)

**Features**
- **Outbound (send):** delivery chalan from sales order → assign to own driver/employee OR third-party courier (Pathao/RedX/Steadfast/Sundarban — manual entry v1, API integration as demand-driven core upgrade) → status pipeline: pending → out for delivery → delivered / failed / returned
- **Inbound (receive):** expected receipts from purchase orders and customer returns → gate entry → GRN link
- Delivery challan print with vehicle no., driver, receiver signature field
- COD (cash on delivery) reconciliation: courier collected vs deposited
- Delivery cost tracking per shipment (feeds landed cost / selling expense)
- Customer notification SMS with tracking status
- Failed-delivery reason codes + auto restock on return

**Key entities:** DeliveryOrder, Shipment, CourierAccount, CODSettlement

---

### Module 7 — Task Management (Employees + Temporary Workers)

**Hierarchy:** Project → Milestone → Task → Sub-task → Time log

**Features**
- Assign tasks to employees **or temporary/contract humans** (a lightweight "worker" party that is not on payroll — e.g., seasonal loaders, freelance designers)
- Task board (Kanban: To-do / Doing / Review / Done) + list + calendar views
- Time tracking: manual entry + start/stop timer; per-task hours roll up to milestone and project
- Task ↔ business object linking: attach a task to a Production Order, Delivery, or Customer ("follow up on Rahim Traders' due" as a task)
- Recurring tasks (daily generator check, weekly stock count)
- Temporary-worker payment: hours/units logged → payment voucher pushed to Accounts (kaj-shesh, taka-shodh flow)
- Project cost view: time cost + expense entries tagged to project

**Key entities:** Project, Milestone, Task, TimeLog, WorkerPayment

---

### Module 8 — HRM (Attendance + Performance + Salary + Payment + Incentive)

**Features**
- Employee master (links to Party): designation, department, grade, shift, salary structure
- **Attendance:** mobile check-in with GPS + selfie (field staff), web check-in, manual register, and biometric device import (ZKTeco CSV/API — very common in BD factories); late/absent/leave auto-flagging
- Leave management: leave types, balances, application → approval
- Shift & roster management (basic)
- **Payroll:** salary structure (basic + house rent + medical + conveyance — BD convention), overtime rules, deduction rules (absence, advance, loan installment), bonus (festival bonus — 2x/year BD standard)
- Salary run: month-end batch → review → approve → payment vouchers auto-posted to Accounts → payslip PDF + SMS
- Employee advance/loan register with auto installment deduction
- **Incentive engine:** rule-based — sales commission (from Module 2), production piece-rate (from Module 1), attendance bonus; all flow into the salary run
- **Performance:** KPI scorecard per role (sales target %, attendance %, task completion % from Module 7), monthly rating, simple appraisal notes

**Key entities:** EmployeeProfile, AttendanceLog, LeaveRequest, SalaryStructure, PayrollRun, IncentiveRule, KPIScore

---

### Module 9 — Reports & Settings

**Reports (initial set)**
- Owner's Daily Digest (auto SMS/WhatsApp/app notification): today's sales, collection, expense, new due, low stock — one line each
- Sales: by day/month, by item, by customer, by salesperson, by area; profit margin per item/invoice
- Purchase: by supplier, by item, price trend of key raw materials
- Inventory: stock summary, valuation, movement, dead stock (no movement 90+ days), near-expiry
- Receivable/Payable aging
- Financials: P&L, Balance Sheet, Cash Flow, Day Book
- HR: attendance summary, salary sheet, incentive report
- Production: cost per unit actual vs BOM, wastage report
- Every report: filter, export to Excel/PDF, save filter presets, schedule via email

**Settings**
- Company profile, branches, financial year, VAT registration info
- Module on/off per tenant (admin-controlled feature flags)
- Roles & permissions editor
- Numbering series (invoice prefix etc.), print templates (logo, header, terms)
- SMS/WhatsApp gateway config, email config
- Data import/export tools, backup download
- Approval workflow thresholds

---

### Module 10 — AI Assistant (Data Q&A)

Natural-language question answering over the tenant's own data.

**Features**
- Chat interface (Bangla + English + Banglish): "গত মাসে কোন প্রোডাক্টে লস হইছে?", "Rahim Traders er total baki koto?", "ei week e best selling item ki?"
- Under the hood: NL → safe, read-only, tenant-scoped query layer (semantic layer over reporting views — the model never gets raw SQL write access)
- Answer with number + small chart + "see full report" link
- Proactive insights (phase 2): anomaly alerts — "Cement bikri last 3 week dhore porche", "Supplier X er dam 12% berechhe 2 mash e"
- Owner's voice note query (phase 3)

**Guardrails:** read-only; tenant-isolated; every AI answer shows the underlying figures so the owner can verify; no financial advice, only their own data.

---

## 4. Module Dependency Map

```
Contacts (M4) ──────────────┐
                            ▼
Sourcing (M1) ──► Inventory (M3) ◄── Sales (M2)
     │                 │                 │
     │                 ▼                 │
     │           Delivery (M6)           │
     │                                   │
     └────────────► Accounts (M5) ◄──────┘
                        ▲
        HRM (M8) ───────┘
        Tasks (M7) ──► HRM incentives / Accounts payments
        Reports (M9) ◄── all modules
        AI (M10) ◄── reporting layer of M9
```

**Minimum viable combinations (bundles):**
- **Trading bundle:** M4 + M1(purchase only) + M2 + M3 + M5 + M9
- **Manufacturing bundle:** Trading bundle + M1(production) + M8
- **Distribution bundle:** Trading bundle + M6
- **Service-lite bundle (limited):** M4 + M7 + M5 + M8 + M9 — usable but not the core strength (see §6)

---

## 5. Build Roadmap (suggested phases)

| Phase | Duration (small team of 3–5 devs) | Scope |
|---|---|---|
| **Phase 1 — Sellable core** | 4–6 months | M4 Contacts, M2 Sales (retail+wholesale basics), M3 Inventory (single warehouse), M1 Purchase (direct only), M5 Accounts (cash/bank/MFS, auto-posting, dues), M9 core reports + Owner's Daily Digest, RBAC, feature flags |
| **Phase 2 — Depth** | +3–4 months | M1 Production/BOM, M3 multi-warehouse + barcode, M6 Delivery, M8 Attendance + Payroll |
| **Phase 3 — Stickiness** | +3 months | M7 Task Management, M8 incentives/performance, approval workflows, report scheduler |
| **Phase 4 — Differentiator** | +2–3 months | M10 AI assistant, proactive insights, public API + webhook platform for mini-projects |

**Warning:** all 10 modules ≈ 12–16 months minimum before "complete." Sell Phase 1 as the product; do not wait for Phase 4 to get clients. First 3–5 clients should be onboarded on Phase 1 and their demands should reshape Phases 2–4.

---

## 6. Coverage Review — "Can every type of business run on this?"

Honest assessment:

### 6.1 Excellent fit (core is designed for these) — ~build confidence 90%
- Trading & import businesses
- Wholesalers & distributors
- Small–mid manufacturers (food, plastic, furniture, packaging, light engineering, garments accessories)
- Retail shops & mini-chains
- Agro traders / processors

### 6.2 Workable fit (runs, with some friction) — ~70%
- **Service companies with projects** (agencies, IT firms, event management): M7 + M5 + M8 carry them, but they will ask for client billing by hours, proposal/contract flow — feasible via M7 extension.
- **Construction/contracting:** projects + materials + labor map roughly to M7 + M1 + M8, but real construction needs work-order/BOQ depth → good vertical candidate later.

### 6.3 Poor fit today (needs a dedicated vertical module — do NOT promise these in v1)
- **Restaurants:** need table/KOT/kitchen printing, recipe-level instant costing, waiter apps
- **Pharmacies:** DGDA compliance, prescription, strict batch/expiry retail POS speed
- **Education (schools/coaching):** batches, fees cycles, results, guardians
- **Hospitals/diagnostics:** appointments, lab reports, compliance
- **Real estate:** unit inventory, installment schedules, registration workflow
- **Pure e-commerce:** marketplace sync (Daraz), storefront, courier API depth — partially covered by M2+M6 but not competitive vs dedicated tools
- **NGO/microfinance:** donor reporting, loan portfolio — different DNA entirely

**Conclusion of review:** the module list covers the *universal commercial spine* (buy–stock–sell–collect–pay–people). That spine genuinely serves the largest slice of BD mid-range demand (trading/manufacturing/distribution). "All business types" is not true and should not be the marketing claim — the honest and stronger claim is: **"any product-based business, any size, only the modules you need."** Verticals in 6.3 become future expansion packs once one of them shows pull.

### 6.4 Gaps found in the original 10-module list (recommended additions)
1. **RBAC + audit log** — was implicit; must be explicit and early (owners won't put money data in without it)
2. **VAT/Mushak compliance** — a real buying trigger for BD mid-range companies
3. **MFS (bKash/Nagad) as account type** — "non-banking" formalized
4. **Multi-branch/warehouse data model from day one** — even if UI comes later
5. **Excel import everywhere** — onboarding friction is the #1 silent killer
6. **Notification engine (SMS/WhatsApp)** as a shared service — many modules need it
7. **Print/invoice template engine** — every client will ask "amader logo ta boshan"

---

## 7. Custom Request Handling Policy (formalizing your idea)

```
Client request arrives
        │
        ▼
Is it generic? (would ≥3 current/target clients use it?)
        │
   ┌────┴────┐
  YES        NO
   │          │
   ▼          ▼
Core backlog  Mini-project (separate repo, tenant-scoped API key,
(feature      billed as custom development + monthly maintenance,
 flagged,     communicates ONLY via public API/webhooks,
 all tenants  own deploy — core release cycle stays clean)
 benefit)
```

- Every mini-project gets a **fixed-scope contract + separate monthly support fee** — this protects margins.
- Review mini-projects quarterly: if 3+ tenants ended up with similar mini-projects, that feature graduates into the core (and those clients get migrated, mini-project retired).

---

## 8. Open Decisions (to settle before development)

1. Tech stack (suggested: one mainstream stack the team already knows well beats "best" stack)
2. Schema-per-tenant vs row-level tenancy
3. Pricing model: per-module? per-user? bundle tiers? (suggested: 3 bundles — Basic / Business / Factory — plus per-extra-user fee)
4. First target vertical hypothesis for marketing (even a broad product needs a focused first message)
5. Hosting: local cloud/VPS in BD vs international (latency + payment + client trust considerations)
6. Data ownership & exit policy (clients will ask: "amar data ki ami niye jete parbo?") — answer must be yes, full export
