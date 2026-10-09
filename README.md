# Application foundation

Laravel 13 and Livewire 4 with a database-free sample catalogue. MySQL is the planned database; it has not been set up and no migrations have run. Sessions/cache use files for the preview. No Node build is required for the current plain CSS interface.

## Run locally

Run `./run-preview.ps1` from PowerShell, then open http://127.0.0.1:8000. PHP with mbstring and fileinfo is required. Composer dependencies are installed and locked in composer.lock.

## Working preview behavior

Interface styling uses locally hosted Inter (license included in public/fonts), white/slate surfaces, and muted green accents. Navigation has consistent line icons, current-page indication, and a tablet drawer with backdrop/Escape dismissal. Purchasing forms are opened on demand. Desktop, tablet and mobile layouts were visually inspected; purchasing and checkout regression checks passed after the redesign.

Sidebar navigation, responsive layouts, sample catalogue, name/SKU search, sample barcode lookup on Enter, repeated scan increments, individual/package quantities, item removal, cash coverage validation, manual GCash/Maya confirmation, and receipt preview. Prices are illustrative; currency/tax configuration is pending.

Catalogue creation/editing, package options, reorder thresholds, an expiry-tracking preference, and manual stock counts now use temporary Laravel session storage. They are shared with checkout in the same browser session, survive ordinary page navigation/reloads while that session remains valid, and are not shared across devices or backed up. Stock counts require a reason and keep before/after quantities and differences in session history. Existing package conversions are fixed. Prices accept at most two decimal places and barcodes/SKUs must be unique. Expiry batch receiving remains unimplemented.

No completed transactions or payment records are persisted. Owner authentication, actual sale completion, MySQL persistence, offline storage/sync, printer integration, and live analytics are future milestones. Mobile analytics-only behavior is currently a responsive layout rule; device policy is not enforced by the backend yet. Unimplemented modules display explicit placeholders.

## Checks

Purchasing supports supplier creation/editing and multi-line purchase orders in temporary session storage. Orders retain product/package and expected-cost snapshots. Partial/full receiving records actual costs and expiry, updates base-unit stock and delivery history, and tracks remaining order quantities. All delivery lines are validated before committing session changes. Delivery event identifiers prevent repeated submissions from adding stock twice. No orders are emailed or otherwise sent to suppliers; supplier balances/payments remain pending. Laravel Boost was added as a development dependency under repository guidance.

Inventory now supports session delivery receiving by individual/package option, whole quantities, purchase cost per selected option, supplier name, optional invoice reference and expiry date. Deliveries update base-unit stock and keep before/after balances and total cost in session history. Expiry-tracked products require a valid non-expired date. This does not implement batch depletion, profit costing, supplier invoices/balances or payment. Current suite: 13 tests, 96 assertions passed.

`php -d extension=mbstring -d extension=fileinfo vendor/bin/phpunit`

Tests cover rendering without a database, package/base-unit quantities, receipt totals/change, repeated scan behavior, invalid quantities, and payment confirmation. Use the project PHP extension flags when running Artisan on the current Windows installation.

Product setup checks also cover creating a product and scanning its package in checkout, exact price storage, duplicate identifiers, invalid package sizes, preserving stock during edits, and reasoned manual counts. Current result: 10 tests and 70 assertions passed.

Setup references: [Laravel installation](https://laravel.com/docs/13.x/installation), [Livewire installation](https://livewire.laravel.com/docs/4.x/installation).
