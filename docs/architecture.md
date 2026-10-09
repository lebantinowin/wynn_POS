# Grocery System — Architecture Plan

## Approved direction

Selected stack: Laravel backend, Livewire interface, and MySQL central database. MySQL installation, connection configuration, and migrations are deferred until a later stage. Initial screen development can use explicit sample data without claiming database persistence. Offline checkout needs a separate client-side durable store and sync layer; Livewire handles the connected interface. Framework versions and the offline implementation will be verified before coding.

Current screen designs are approved and may be revised later. One owner-operated shop, one active checkout counter, individual/package sales, full desktop/tablet functionality, and mobile analytics only. No staff management or RBAC in the initial release.

## System structure

Confirmed devices: Windows desktop and Android tablet. Proposed primary checkout is the Windows desktop. Android provides full functionality, with only one designated checkout active at a time. Device OS is resolved; primary checkout designation and hardware compatibility remain to be confirmed.

```text
Desktop / tablet application                 Mobile analytics
  Checkout + management                       Reports + filters
  Local catalogue + durable sale store              |
  Receipt preview + hardware adapters              |
            |                                      |
            +---- Authenticated application API ---+
                              |
                   Central relational database
                   Reporting + scheduled backups
```

Use one responsive application interface with shared business rules. Designate one device as the active checkout. Other devices use synchronized data; dashboards show sync freshness. The active checkout can transact offline after initial setup. The mobile interface contains analytics only.

## Application modules

1. Owner authentication, device registration, sidebar, settings.
2. Products, sale options, barcodes, price records.
3. Checkout, held carts, payments, receipts, cash sessions.
4. Inventory movements, receiving, expiry batches, physical counts, waste.
5. Suppliers, purchasing, invoices, supplier payments.
6. Returns, refunds, expenses and reconciliation.
7. Reporting, exports, mobile analytics.
8. Sync, activity history, backups and recovery.

Build these in one application/backend initially. Keep module boundaries clear without creating separate services for a single shop.

## Data and financial integrity

The central relational database is the consolidated record. The active checkout maintains a durable local store and an upload queue. Inventory is represented by traceable base-unit movements; package conversions are stored on sale lines. Historical sales preserve names, prices, quantities, discounts, costs, and payment details.

Use exact monetary values and database constraints for identifiers, barcodes, references, and quantities. A completed sale and its stock movements are committed together. Cost allocation must be selected before profit reporting is implemented. Missing costs are flagged in reports.

## Offline sale lifecycle

1. Download catalogue/settings and establish an authenticated checkout device while online.
2. Add products and quantities using the locally available catalogue.
3. Confirm payment. GCash/Maya confirmation remains manual and may require internet in the separate payment app.
4. Save the completed sale, stock movements, and outbox event in one local transaction.
5. Generate a receipt from that saved sale. Printer errors permit reprinting without another sale.
6. Upload queued events when connected. Each event and sale has a unique identifier; duplicate retries return the existing result.
7. The server validates and applies each sale atomically, then acknowledges it.
8. Retain local records until acknowledgment. Show failed/pending events visibly and provide recovery rather than silently dropping them.

Prices downloaded later affect future sales only. Owner edits to products, prices, receiving, and stock counts require connectivity in the initial design, reducing conflicting inventory changes. Offline scope initially covers checkout, locally held carts, receipts, and checkout cash-session events; broader offline management requires additional design.

Stock displayed on other devices may be stale while checkout is offline. No system can supply unsynchronized sales to remote analytics. Manual stock counts must warn about outstanding checkout transactions and be reconciled after those transactions synchronize.

## Hardware preparation

- Scanner input routes through the same product-code lookup as manual entry.
- Receipt rendering is separate from physical printing so previews work without hardware.
- Choose the print adapter after confirming desktop/tablet OS, printer model, connection, and app environment.
- Browser-only printing, direct thermal printing, and tablet support must be validated on the selected devices before promising a specific printing flow.
- A browser/PWA versus installed application decision remains open. Durable storage, reload recovery, installation, and printer compatibility are acceptance gates.

## Protection and recovery

Owner login and device authentication remain necessary even without RBAC. Validate all write requests on the server, protect credentials, and maintain activity history. Define automatic database backups and test restoration. Cloud backup covers synchronized records only; pending offline sales need a local recovery/export strategy. Set backup frequency, retention, and acceptable data-loss/recovery windows with the owner before launch.

## Implementation milestones

| Milestone | Deliverable | Required evidence |
|---|---|---|
| A | Runnable shell, owner login, sidebar, responsive layouts | Desktop/tablet navigation; mobile analytics-only presentation |
| B | Product catalogue and individual/package options | Example package conversions, unique barcode checks |
| C | Checkout, recorded payments, saved receipts, cash sessions | Search/quantity sale, payment totals, reprint without duplicate sale |
| D | Offline checkout and sync | Reload recovery, interrupted upload retry, one server sale per identifier |
| E | Stock, purchasing, returns, expenses | Traceable movements and financial examples |
| F | Analytics and mobile reports | Totals reconciled to source records, missing-cost and freshness indicators |
| G | Hardware, backup restore, owner pilot | Actual device tests and recovered data |

Offline data structures are designed before checkout implementation; milestone D completes verification and synchronization behavior before live use.

## Technology selection gates

Confirm desktop/tablet OS and primary checkout device first. Then select and verify the frontend, backend, relational database, local storage, hosting, and printer approach against current official documentation. Laravel, Livewire, and MySQL are selected; installation and version verification are pending. MySQL setup is deferred. Currency/tax/receipt rules, insufficient-stock behavior, costing, and backup objectives remain open implementation decisions. The current HTML wireframe is a design artifact, not a working POS.

