# Grocery POS and Management System Blueprint

## Confirmed scope

One grocery shop and one active checkout counter. Packaged goods can be sold individually or in packages. Desktop and tablet have full functionality; mobile has analytics only. Checkout works offline. Hardware is not yet purchased, so initial operation uses product search, quantity entry, and receipt preview. Scanner and receipt-printer support remain part of the system.

Cash, GCash, and Maya are supported. GCash/Maya QR codes are printed from their apps and displayed at the counter. Operators confirm payment in the payment app, then record it in the POS; there is no automatic payment verification.

## Screen blueprint

### 1. Checkout

- Header: shop, operator, open shift, online/offline state, pending sync count.
- Search: product name, SKU, or barcode; scanner input uses the same lookup.
- Results: product name, available stock, and sale options (individual/package).
- Cart: product and sale option, unit price, editable quantity, line total, remove action.
- Summary: subtotal, discount, total.
- Payment: Cash / GCash / Maya. Cash displays amount received and change. GCash/Maya requires an explicit operator confirmation after checking the payment app; optional transaction reference.
- Actions: hold/resume cart, cancel cart, complete sale, receipt preview/print.
- Desktop uses side-by-side search and cart. Tablet adapts for touch and smaller widths.
- Unknown barcode offers search or product setup; never silently creates a product.
- Completing a sale saves its details and stock movements together. Printing failure does not lose or repeat the sale.

### 2. Products

- Searchable list with category, prices, stock, active status, and low-stock indicator.
- Product form: name, SKU, category, base unit, reorder threshold, tracking options.
- Sale options: label, number of base units, selling price, optional barcode(s).
- Prices and package sizes must be positive. Barcodes are unique across sale options.
- Referenced sale options cannot have their conversion factor changed; create a new option and retire the old one.

### 3. Inventory

- Current stock in base units, equivalent packages, stock value, low-stock list.
- Receive stock using an individual/package quantity and acquisition cost.
- Optional batch expiry dates; expiry tracking enabled for relevant products.
- Stock count: counted amount, expected amount, discrepancy, reason, owner.
- Damage/expiry/waste entry with quantity, reason, and cost.
- Movement history links changes to sales, receiving, returns, or adjustments.

### 4. Purchasing and suppliers

- Supplier directory and contact details.
- Purchase orders with requested sale unit/package, quantity, and expected cost.
- Partial/full delivery receiving; record actual quantities and actual costs.
- Supplier invoices, payments, outstanding balances, and due dates.
- Suggestions based on reorder thresholds initially; sales-based suggestions later.

### 5. Sales and returns

- Search receipts by number/date/payment method.
- View original item, package, price, cost, discount, operator, and payment snapshots.
- Reprint marked COPY.
- Return quantities cannot exceed unreturned quantities from the original sale.
- Refund records method, reason, and whether returned goods are sellable.
- Sellable returns restore stock; damaged returns are recorded separately.

### 6. Shifts, cash, and expenses

- Open shift with opening cash; one active shift at the designated counter.
- Record cash in/out with a reason.
- Close shift with counted cash, expected cash, difference, and notes.
- Separate GCash/Maya totals for reconciliation against their apps.
- Record operating expenses with date, category, amount, and payment source.
- Expenses paid from the drawer generate linked cash movements to avoid double counting.

### 7. Analytics

- Overview: net sales, transaction count, average basket, gross profit, recorded expenses, and recorded net profit.
- Trends: hourly/daily/monthly sales and comparison periods.
- Products: best/slow sellers, individual/package sales, equivalent base units, margins, returns, discounts.
- Inventory: stock value, low stock, turnover, no-sales products, expiry exposure, waste and count discrepancies.
- Purchasing: supplier spending, cost changes, balances, delivery history.
- Payments: Cash/GCash/Maya totals, refunds, shift differences.
- Filters: date range, category, product, operator, payment method as applicable.
- Drill-down from totals to underlying transactions; export reports.
- Show last successful sync and pending transaction warning. Mobile shows this module only.
- Forecasts require enough history; estimated values are clearly labeled.

### 8. Settings and access

- Shop details, receipt header/footer, numbering, currency, timezone, and tax configuration.
- Owner-operated system: full access for the business owners. No staff roles or role-based access control (RBAC) in the initial version. Account/device authentication still protects business data.
- Mobile presents analytics and reports only; desktop and tablet provide full owner access. This is a device interface scope, not a staff permission system.
- Scanner/printer configuration and test functions once hardware is available.
- Backup status, recovery, audit history, device designation.

## Product and package model

Inventory uses one base unit per product. A sale option specifies a fixed conversion to that base unit.

Example: Drink (base unit: bottle)

| Sale option | Base units per option | Quantity sold | Stock deduction |
|---|---:|---:|---:|
| Individual | 1 | 2 | 2 bottles |
| Pack of 6 | 6 | 2 | 12 bottles |
| Case of 24 | 24 | 3 | 72 bottles |

Each option has its own price and optional barcode. Scanning the same option increments that cart line. Receiving three cases adds 72 base units. Receipt text preserves the purchased option, e.g. Drink — Pack of 6 × 2.

Packages can be opened and sold individually. Stock is tracked in base units: receiving a pack of six adds six units; selling one individual deducts one unit; selling a full pack deducts six. Opening a package does not change the total stock. The system counts these movements automatically. Staff can also enter a physical stock count or an manual adjustment; the system records the difference, reason, owner, and time. Manual corrections create stock movements rather than deleting history. Separate counts of intact sealed packages are outside the initial scope.

## Core data structure

| Record | Essential fields |
|---|---|
| Product | ID, SKU, name, category, base unit, reorder level, active flag |
| Sale option | ID, product ID, label, conversion factor, selling price, active flag |
| Barcode | Code, sale option ID |
| Inventory batch | ID, product ID, received date, expiry date, remaining base units, acquisition cost |
| Stock movement | ID, product/batch, signed base quantity, reason, source record, user, timestamp |
| Sale | UUID, receipt number, device, shift, operator, timestamps, status, totals |
| Sale line | Product/option IDs plus name, conversion, price, discount, base quantity, cost snapshots |
| Payment | Sale ID, method, amount, operator confirmation, optional external reference |
| Return/refund | Original sale/lines, quantities, reason, disposition, refund method/amount, owner |
| Shift | Device, operator, opening/closing timestamps, opening cash, counted cash, discrepancy |
| Cash movement | Shift, amount, direction, reason, linked expense/refund where applicable |
| Supplier | ID, name, contact details |
| Purchase order | Supplier, status, lines, requested quantities/costs |
| Receiving | Supplier/order, received lines, base quantities, costs, batches |
| Supplier invoice/payment | Supplier, invoice balance, amounts, dates, references |
| Expense | Date, category, amount, payment source, notes |
| Owner account | Identity, authentication, active status |
| Audit event | Actor, action, record, previous/new values where appropriate, timestamp |
| Sync outbox | Event UUID, device, payload, state, retry/error metadata |

Completed financial records retain snapshots; later product edits never rewrite historical receipts or profit. Monetary values use exact decimal/minor-unit storage, not floating-point arithmetic.

## Reporting definitions

- Net sales: completed sales less discounts and returned sales, excluding applicable taxes.
- Cost of goods sold: cost allocated to sold units, adjusted for returns.
- Gross profit: net sales minus cost of goods sold.
- Recorded net profit: gross profit minus recorded operating expenses and relevant inventory losses, with each loss counted once.
- Average basket: net sales divided by completed transaction count, with the return treatment defined consistently.
- Inventory value: remaining stock at acquisition cost, never selling price.

Cost allocation method must be selected before implementation. Batch-based FIFO is a proposed starting point; expiry picking can use earliest-expiry-first independently. Profit reports identify missing costs instead of treating them as zero.

## Offline and receipt design

The active checkout device maintains a local product catalogue and durable transaction store. Sales, payments, stock movements, and sync events are committed atomically. Every transaction has a UUID, so retries cannot create duplicates. Local receipts use a device-specific sequence suitable for later synchronization.

After reconnection, transactions upload through an idempotent API. Product/price changes apply to future sales; historical snapshots stay intact. Other devices show the last synchronized position. Initial setup and catalogue download require connectivity. Offline access requires an authorized device and previously established credentials.

Receipt preview is available before hardware purchase. Physical printing uses an adapter selected after confirming desktop/tablet OS, printer interface, and supported browser/app environment. Receipt fields: shop details, number, timestamp, operator, item/option, quantity, unit price, discounts, total, payment, cash received/change, and configured tax details. Reprints are marked COPY. Printing failure offers retry without recreating the transaction.

## Build sequence and acceptance criteria

1. Confirm product/package assumptions, device operating systems, currency/tax settings, owner access, and cost allocation. Approve checkout/product screen wireframes.
2. Build catalogue and checkout: scan-ready lookup, manual search, package quantities, payments, receipt preview, and shifts.
3. Implement offline storage and sync alongside checkout before live use; verify reload recovery and duplicate prevention.
4. Add receiving, batches, costs, suppliers, stock counts, waste, returns, expenses, and reconciliation.
5. Build traceable analytics and responsive mobile-only reporting; verify report totals against transaction examples.
6. Integrate actual scanner/printer when purchased; pilot the complete workflow and recovery process.

Acceptance scenarios include individual/package deductions, repeated scans, offline sales across reloads, interrupted sync retries, manually confirmed QR payments, partial returns, printing failure/reprint, expiry losses, shift cash differences, owner authentication, and restoring a backup.

## Decisions remaining before coding

- Desktop/tablet operating systems and which device is the active checkout.
- Currency, timezone, tax treatment, and receipt requirements.
- Discount/refund rules and whether sales may proceed with insufficient stock.
- Starting inventory/import format and supplier balances.
- Cost allocation policy and backup/recovery objectives.

This blueprint defines behavior; technology selection and any jurisdiction-specific receipt requirements need verification before implementation.

## Navigation layout

Desktop uses a persistent collapsible sidebar: Dashboard, Checkout, Products, Inventory, Purchasing & Suppliers, Sales & Returns, Shifts & Expenses, Reports, Settings. Tablet uses a collapsible drawer to preserve checkout space. Mobile navigation contains analytics and reports only. Owners have full access; staff management and RBAC are outside the initial scope. Shift opening/closing remains useful for cash reconciliation even without employees.

