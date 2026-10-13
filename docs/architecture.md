# Grocery System — Architecture Plan

## Approved direction

Selected stack: Laravel backend, Livewire interface, and PostgreSQL central database. Local PostgreSQL 18.4 is installed and running on port 5432. Laravel now connects to the dedicated wynn_mart database through wynn_mart_app; all five migrations are applied, providing nine foundation tables and 30 POS/offline tables. The preview enables pdo_pgsql. The selected browser catalogue is imported into shared PostgreSQL storage; file sessions now retain interface state, not business records. Offline checkout now uses a separate cached public shell, IndexedDB sale store and authenticated sync API; Livewire handles the connected interface. Installed framework APIs, transaction rules and browser outage/recovery behavior are verified.

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

Owner authentication and scheduled local backups with isolated recovery checks are implemented and verified. The diagram includes implemented offline storage and planned hardware adapters. Physical hardware remains deferred. Use one responsive application interface with shared business rules. Designate one device as the active checkout. Other devices use synchronized data; dashboards show sync freshness. One prepared browser can transact offline within its bounded session. The mobile interface contains analytics only.

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

The central relational database is now the consolidated record for connected operation. PosStore uses Laravel Query Builder with typed core fields and foreign keys, separate option/document-line tables, and JSONB historical snapshots. A shop workspace row lock is acquired before mutable validation reads; stock, debt, cash and source events commit in one transaction. Event/document uniqueness, stock constraints and one-open-cash-session enforcement protect retries and competing requests. This version serializes shop requests and reads/upserts full collections; scalability optimizations remain future work. Each connected session shares these records and uses manual refresh for changes from another device. The initial import saves a private source archive and cannot overwrite an initialized workspace. Archives are not scheduled backups. The prepared checkout commits sale/stock/receipt and upload sequence atomically in IndexedDB; ordered uploads reconcile to the database through the same shop lock. Inventory is represented by traceable base-unit movements; package conversions are stored on sale lines. Historical sales preserve names, prices, quantities, discounts, costs, and payment details.

Use exact monetary values and database constraints for identifiers, barcodes, references, and quantities. A completed sale and its stock movements are committed together. New sale costs use actual expiry-first batch acquisition allocations, saved as exact integer-cent cost segments. Cumulative proportional shares conserve the batch total across individual/package sales; partial returns reverse original chunks and restock retains value for resale. Losses use affected batches; optional manual estimates only value unknown units and remain labeled. Product default-cost edits do not revalue stock or receipts. Historic receipts retain their saved basis; legacy returns without original cost segments restore unknown-cost stock. Inventory/Reports expose remaining acquisition value and unknown-cost coverage; missing costs withhold affected totals. Owner login is implemented and verified, and the owner confirmed successful login. Local backups, isolated recovery checks and bounded offline checkout/synchronization are complete; practical device/network acceptance is next.

## Offline sale lifecycle

1. Sign in online, open the cash session and prepare one browser with fixed catalogue/prices, sellable stock and saved credit customers. Preparation is capped at two hours, configured session lifetime and local midnight.
2. Add products and quantities using the locally available catalogue.
3. Confirm payment. GCash/Maya confirmation remains manual and may require internet in the separate payment app.
4. Save the completed sale, stock movements, and outbox event in one local transaction.
5. Generate a receipt from that saved sale. Printer errors permit reprinting without another sale.
6. Upload queued events when connected. Each event and sale has a unique identifier; duplicate retries return the existing result.
7. The server validates and applies each sale atomically, then acknowledges it.
8. Retain local receipts after acknowledgment, show failed/pending events and permit private recovery exports. Finish seals new sales, checks all uploads, then releases the counter. Recovery imports are sealed and recheck original event identifiers before release.

Prices and all connected business writes pause while the offline lease is active, preserving stock and price assumptions even when the prepared browser disconnects. POS storage rejects writes outside the scoped upload service. The initial offline scope covers one saved cart, checkout and receipts. Opening/closing cash sessions, returns, expenses, loans, repayments and management remain online; multiple held carts and broader offline management require additional work. Original sale time controls expiry and reports; late uploads inside the original valid window remain accepted.

Stock displayed on other devices may be stale while checkout is offline. No system can supply unsynchronized sales to remote analytics. Manual stock counts and other mutations are blocked until those transactions synchronize and the original browser finishes. There is no lost-device force-release workflow yet.

## Hardware preparation

- Scanner input routes through the same product-code lookup as manual entry.
- Receipt rendering is separate from physical printing so previews work without hardware.
- Choose the print adapter after confirming desktop/tablet OS, printer model, connection, and app environment.
- Browser-only printing, direct thermal printing, and tablet support must be validated on the selected devices before promising a specific printing flow.
- A browser/PWA versus installed application decision remains open. Durable storage, reload recovery, installation, and printer compatibility are acceptance gates.

## Protection and recovery

Owner login now protects all shop pages and Livewire writes without RBAC. One reserved owner account is created only through a local hidden-input setup command; no public registration or default credentials exist. Session rotation, idle expiry, CSRF, generic login errors, account/IP and IP throttling, sign-out and local password recovery are verified. Recovery revokes existing device sessions on their next request. The owner confirmed successful account activation and login. Device registration/authentication and network/HTTPS deployment remain pending. Validate all write requests on the server, protect credentials, and maintain activity history.

Local PostgreSQL backups use a read-only repeatable-read exported snapshot and custom-format pg_dump archive of the configured application schema, including the owner account. Saved table/row fingerprints and archive SHA-256 checksums verify recovery independently of later shop changes. Archives/metadata are outside public/signed file routes in Git-ignored storage/app/backups. A nonblocking file lock prevents overlap; metadata replacement is atomic, incomplete files are cleaned up, and stale running attempts become failed on the next operation. Retention removes only registered expired successful archives after a new backup succeeds.

Settings offers manual backup, history, pause/frequency/retention controls and recovery checks. Daily/14-day defaults are active. A limited interactive Windows task checks hourly and at sign-in; it runs while this computer is on and the account is signed in. Database secrets stay in subprocess environments, and required Windows system variables are supplied explicitly for web and scheduled processes. Recovery starts a separate temporary localhost PostgreSQL instance, restores the saved archive, compares all records, then stops and removes it. It needs no administrator credentials or additional database privileges and does not overwrite the live database. The actual shop check matched all 37 tables and 17 saved records.

Archives are unencrypted local copies. Off-device storage, configuration/application-key and uploaded-file recovery, a controlled production restore procedure and acceptable data-loss/recovery windows remain to be settled before launch. Pending offline sales have separate JSON recovery exports taken in one readonly IndexedDB transaction. Restore requires online owner authentication, no active local session/pending queue, consistent sequences/stock, and seals further sales while original events are rechecked. A database archive cannot contain unsynchronized records; a stale export cannot recover events absent from it. Keep the browser profile/site data and private copies until server confirmation. Storage permission may be declined. Explicit logout locks cached checkout across tabs; server authentication/CSRF is required for sync. Passwords/tokens are not persisted locally, but remote revocation is undetectable while disconnected. Only public shell/assets are cached. HTTPS/localhost and supporting browsers are required; actual Android HTTPS/network deployment remains pending.

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

Milestone D software checks pass: 11 feature tests, including PostgreSQL retry persistence, eight Node checks and isolated browser outage/reload/recovery/sync scenarios. The full suite passes 172 tests/1664 assertions. Actual-device and owner acceptance still precede live use.

## Technology selection gates

Confirm desktop/tablet OS and primary checkout device first. Then select and verify the frontend, backend, relational database, local storage, hosting, and printer approach against current official documentation. Laravel, Livewire, and PostgreSQL are selected. Local PostgreSQL 18.4 and its running service have been verified; PHP can load pdo_pgsql. Application database foundation setup and connection verification are complete. POS tables, Query Builder storage, transactional writes and selected-browser import are implemented and covered by isolated PostgreSQL tests. Owner authentication is implemented and verified, and the owner confirmed successful login. Android/network setup, off-device disaster recovery, lost-checkout recovery and practical device acceptance remain pending. Currency/tax/receipt policies and disaster-recovery objectives remain open business decisions. Insufficient/expired stock blocking and exact batch costing are implemented. The current HTML wireframe is a design artifact, not a working POS.
