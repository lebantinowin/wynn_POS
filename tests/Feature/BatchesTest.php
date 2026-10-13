<?php

namespace Tests\Feature;

use App\Livewire\Inventory;
use App\Livewire\Losses;
use App\Livewire\Products;
use App\Support\DemoCatalogue;
use App\Support\SessionAnalytics;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionLosses;
use App\Support\SessionPayables;
use App\Support\SessionPurchasing;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BatchesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Shanghai')->setDate(2026, 10, 9)->setTime(12, 0));
        SessionCash::open('opening', '1000', '');
    }

    private function emptyDrink(): void
    {
        $product = DemoCatalogue::find(1);
        $product['stock'] = 0;
        DemoCatalogue::save($product);
    }

    private function delivery(int $quantity, string $expiry, string $reference): array
    {
        Livewire::test(Inventory::class)->set('optionId', 'drink-one')->set('quantity', $quantity)->set('cost', '12.35')
            ->set('supplier', 'Demo supplier')->set('reference', $reference)->set('expiry', $expiry)->call('receive')->assertHasNoErrors();
        $entries = session('demo.receiving');
        $entry = $entries[array_key_last($entries)];

        return SessionBatches::state()['batches'][$entry['batchId']];
    }

    private function balanced(): void
    {
        $state = SessionBatches::state();
        foreach (DemoCatalogue::products() as $product) {
            $total = array_sum(array_column(array_filter($state['batches'], fn (array $batch): bool => $batch['productId'] === $product['id']), 'remaining'));
            $this->assertSame($product['stock'], $total);
        }
    }

    private function reject(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function newProduct(string $expiry): array
    {
        Livewire::test(Products::class)->call('create')->set('name', 'Dated biscuits')->set('sku', 'BIS-01')->set('category', 'Snacks')
            ->set('unit', 'packet')->set('openingStock', 10)->set('baseCost', '8.35')->set('openingExpiry', $expiry)
            ->set('options', [['label' => 'Individual', 'units' => 1, 'price' => '10', 'barcode' => 'BIS-01']])->call('save')->assertHasNoErrors();

        return DemoCatalogue::find(3);
    }

    public function test_new_opening_stock_and_deliveries_have_distinct_cost_and_date_snapshots(): void
    {
        $product = $this->newProduct('2026-11-01');
        $opening = array_values(array_filter(SessionBatches::state()['batches'], fn (array $batch): bool => $batch['productId'] === 3))[0];
        $this->assertSame(10, $opening['remaining']);
        $this->assertSame(8350, $opening['totalCost']);
        $this->assertSame('2026-11-01', $opening['expiry']);
        Livewire::test(Inventory::class)->set('optionId', $product['options'][0]['id'])->set('quantity', 5)->set('cost', '9.10')
            ->set('supplier', 'Supplier')->set('expiry', '2026-12-01')->call('receive')->assertHasNoErrors();
        $this->assertSame($opening, SessionBatches::state()['batches'][$opening['id']]);
        $batches = array_values(array_filter(SessionBatches::state()['batches'], fn (array $batch): bool => $batch['productId'] === 3));
        $this->assertCount(2, $batches);
        $this->assertSame(4550, $batches[1]['totalCost']);
        Livewire::test(Products::class)->call('edit', 3)->set('baseCost', '99')->call('save')->assertHasNoErrors();
        $this->assertSame(8350, SessionBatches::state()['batches'][$opening['id']]['totalCost']);
        $this->balanced();
    }

    public function test_old_stock_is_current_unknown_baseline_without_reconstructing_opening_history(): void
    {
        $product = DemoCatalogue::find(1);
        $product['stock'] = 63;
        $product['openingExpiry'] = '2026-10-01';
        $product['openingQuantity'] = 72;
        DemoCatalogue::save($product);
        $batch = SessionBatches::state()['batches']['legacy-1'];
        $this->assertSame(63, $batch['remaining']);
        $this->assertSame('', $batch['expiry']);
        $this->assertNull($batch['totalCost']);
        $this->get('/inventory')->assertOk()->assertSee('Legacy stock');
        $this->assertEmpty(session('demo.batches', []));
        $this->balanced();
    }

    public function test_sale_splits_package_and_individual_lines_across_earliest_expiry_then_unknown(): void
    {
        $this->emptyDrink();
        $undated = $this->delivery(5, '', 'undated');
        $late = $this->delivery(5, '2026-12-01', 'late');
        $early = $this->delivery(4, '2026-10-15', 'early');
        $sale = SessionSales::complete('sale', ['drink-pack' => 1, 'drink-one' => 5], 'Cash', '210', false);
        $this->assertSame([$early['id'], $late['id']], array_column($sale['lines'][0]['batchAllocations'], 'batchId'));
        $this->assertSame([4, 2], array_column($sale['lines'][0]['batchAllocations'], 'quantity'));
        $this->assertSame([$late['id'], $undated['id']], array_column($sale['lines'][1]['batchAllocations'], 'batchId'));
        $state = SessionBatches::state();
        $this->assertSame(0, $state['batches'][$early['id']]['remaining']);
        $this->assertSame(0, $state['batches'][$late['id']]['remaining']);
        $this->assertSame(3, $state['batches'][$undated['id']]['remaining']);
        $this->assertSame(4940, $sale['lines'][0]['batchAllocations'][0]['originalTotalCost']);
        $this->assertSame($sale, SessionSales::complete('sale', [], 'invalid', '', false));
        $this->assertSame($state, SessionBatches::state());
        $this->balanced();
    }

    public function test_expired_batches_block_sales_atomically_but_expiry_today_is_sellable(): void
    {
        $product = $this->newProduct('2026-10-08');
        $before = SessionBatches::state();
        $this->reject(fn () => SessionSales::complete('blocked', ['drink-one' => 1, $product['options'][0]['id'] => 1], 'Cash', '30', false), 'cart');
        $this->assertSame($before, SessionBatches::state());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionSales::all());
        $this->assertEmpty(SessionCash::movements());
        Livewire::test(Inventory::class)->set('optionId', $product['options'][0]['id'])->set('quantity', 2)->set('cost', '8')
            ->set('supplier', 'Supplier')->set('expiry', '2026-10-09')->call('receive')->assertHasNoErrors();
        $sale = SessionSales::complete('today', [$product['options'][0]['id'] => 2], 'Cash', '20', false);
        $this->assertSame('2026-10-09', $sale['lines'][0]['batchAllocations'][0]['expiry']);
        $this->assertSame(10, SessionAnalytics::summarize(7)['expiryStock']['expired']);
        $this->balanced();
    }

    public function test_partial_returns_restore_original_batches_once_and_discard_does_not_deduct_again(): void
    {
        $this->emptyDrink();
        $early = $this->delivery(4, '2026-10-15', 'early');
        $late = $this->delivery(10, '2026-12-01', 'late');
        $sale = SessionSales::complete('sale', ['drink-pack' => 2], 'Cash', '220', false);
        $first = SessionReturns::complete('return-1', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'First pack returned');
        $this->assertSame([4, 2], array_column($first['lines'][0]['batchAllocations'], 'quantity'));
        $second = SessionReturns::complete('return-2', $sale['id'], [['quantity' => 1, 'disposition' => 'Discard']], 'Cash', true, 'Second pack damaged');
        $this->assertSame([$late['id']], array_column($second['lines'][0]['batchAllocations'], 'batchId'));
        $this->assertSame(6, $second['lines'][0]['batchAllocations'][0]['quantity']);
        $this->assertSame(4, SessionBatches::state()['batches'][$early['id']]['remaining']);
        $this->assertSame(4, SessionBatches::state()['batches'][$late['id']]['remaining']);
        $state = SessionBatches::state();
        $this->assertSame($second, SessionReturns::complete('return-2', $sale['id'], [], 'Cash', false, ''));
        $this->assertSame($state, SessionBatches::state());
        $this->assertSame($second['lines'][0]['batchAllocations'], SessionLosses::all()[0]['batchAllocations']);
        $this->assertSame($sale, SessionSales::all()[$sale['id']]);
        $this->balanced();
    }

    public function test_return_after_expiry_keeps_date_and_legacy_return_creates_unknown_batch(): void
    {
        $this->emptyDrink();
        $batch = $this->delivery(2, '2026-10-09', 'today');
        $sale = SessionSales::complete('sale', ['drink-one' => 2], 'Cash', '40', false);
        $this->travelTo(now()->addDay());
        SessionReturns::complete('return', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Returned next day');
        $this->assertSame('Expired', SessionBatches::overview()['batches'][$batch['id']]['status']);
        $this->reject(fn () => SessionSales::complete('expired', ['drink-one' => 1], 'Cash', '20', false), 'cart');
        unset($sale['lines'][0]['batchAllocations'], $sale['lines'][0]['costMethod']);
        session()->put('demo.sales', [$sale['id'] => $sale]);
        $legacy = SessionReturns::complete('old-return', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Older receipt returned');
        $id = $legacy['lines'][0]['batchAllocations'][0]['batchId'];
        $this->assertSame('Legacy return', SessionBatches::state()['batches'][$id]['source']);
        $this->assertSame('', SessionBatches::state()['batches'][$id]['expiry']);
        $this->balanced();
    }

    public function test_loss_targets_specific_batch_and_rejects_foreign_or_insufficient_batch(): void
    {
        $this->emptyDrink();
        $early = $this->delivery(2, '2026-10-15', 'early');
        $late = $this->delivery(5, '2026-12-01', 'late');
        $before = SessionBatches::state();
        $this->reject(fn () => SessionLosses::record('bad', 'drink-pack', 1, 'Damage', 'Broken stock', '', $late['id']), 'quantity');
        $this->reject(fn () => SessionLosses::record('foreign', 'noodle-one', 1, 'Damage', 'Broken stock', '', $late['id']), 'quantity');
        $this->assertSame($before, SessionBatches::state());
        SessionLosses::record('loss', 'drink-one', 3, 'Damage', 'Later stock damaged', '', $late['id']);
        $this->assertSame(2, SessionBatches::state()['batches'][$early['id']]['remaining']);
        $this->assertSame(2, SessionBatches::state()['batches'][$late['id']]['remaining']);
        $this->assertSame($late['id'], SessionLosses::all()[0]['batchAllocations'][0]['batchId']);
        $after = SessionBatches::state();
        SessionLosses::record('loss', 'drink-one', 3, 'Damage', 'Later stock damaged', '', $late['id']);
        $this->assertSame($after, SessionBatches::state());
        $this->balanced();
    }

    public function test_counts_remove_earliest_expiry_and_add_unknown_stock(): void
    {
        $this->emptyDrink();
        $early = $this->delivery(4, '2026-10-15', 'early');
        $late = $this->delivery(6, '2026-12-01', 'late');
        Livewire::test(Products::class)->call('startCount', 1)->set('physicalCount', 7)->set('countReason', 'Counted fewer bottles')->call('saveCount')->assertHasNoErrors();
        $this->assertSame(1, SessionBatches::state()['batches'][$early['id']]['remaining']);
        $this->assertSame(6, SessionBatches::state()['batches'][$late['id']]['remaining']);
        Livewire::test(Products::class)->call('startCount', 1)->set('physicalCount', 12)->set('countReason', 'Found unopened stock')->call('saveCount')->assertHasNoErrors();
        $id = session('demo.counts')[1]['batchAllocations'][0]['batchId'];
        $this->assertSame('Stock count', SessionBatches::state()['batches'][$id]['source']);
        $this->assertSame('', SessionBatches::state()['batches'][$id]['expiry']);
        $this->assertNull(SessionBatches::state()['batches'][$id]['totalCost']);
        $this->assertSame(4, SessionBatches::state()['batches'][$early['id']]['initialQuantity']);
        $this->balanced();
    }

    public function test_purchase_batch_and_supplier_invoice_commit_together_only_once(): void
    {
        SessionPurchasing::saveSupplier(null, ['name' => 'Supplier', 'contact' => '', 'phone' => '', 'email' => '', 'notes' => '']);
        SessionPurchasing::createOrder(array_key_first(SessionPurchasing::suppliers()), '', [['optionId' => 'drink-pack', 'quantity' => 2, 'cost' => '60']]);
        $order = array_key_first(SessionPurchasing::orders());
        $lines = [['quantity' => 1, 'cost' => '61.25', 'expiry' => '2026-11-01']];
        SessionPurchasing::receive($order, $lines, 'INV-1', 'delivery');
        SessionPurchasing::receive($order, $lines, 'INV-1', 'delivery');
        $invoice = array_values(SessionPayables::invoices())[0];
        $batch = SessionBatches::state()['batches'][$invoice['lines'][0]['batchId']];
        $this->assertSame(6, $batch['remaining']);
        $this->assertSame(6125, $batch['totalCost']);
        $this->assertSame('2026-11-01', $batch['expiry']);
        $this->assertCount(1, SessionPayables::invoices());
        $this->assertCount(1, session('demo.receiving'));
        $this->balanced();
    }

    public function test_overview_filters_depletion_and_movement_references(): void
    {
        $this->emptyDrink();
        $early = $this->delivery(2, '2026-10-09', 'today');
        $this->delivery(3, '2026-11-08', 'thirty days');
        $this->delivery(4, '2026-11-09', 'later');
        $sale = SessionSales::complete('sale', ['drink-one' => 2], 'Cash', '40', false);
        $this->assertSame('Depleted', SessionBatches::overview()['batches'][$early['id']]['status']);
        $this->assertSame(3, SessionAnalytics::summarize(7)['expiryStock']['soon']);
        Livewire::test(Inventory::class)->set('batchStatus', 'soon')->assertSee('thirty days')->assertDontSeeHtml('wire:key="batch-'.$early['id'].'"')
            ->set('batchStatus', 'depleted')->assertSeeHtml('wire:key="batch-'.$early['id'].'"')
            ->call('inspectBatch', $early['id'])->assertSet('batchStatus', 'all')->assertSet('batchSearch', $early['id']);
        $this->get('/inventory')->assertOk()->assertSee($sale['number'])->assertSee('Batch movement history');
        Livewire::test(Losses::class)->set('optionId', 'drink-one')->assertSee('Stock batch');
        $this->balanced();
    }

    public function test_failed_receive_and_refund_do_not_leave_partial_batch_changes(): void
    {
        $this->emptyDrink();
        $this->delivery(2, '2026-10-15', 'early');
        $before = SessionBatches::state();
        Livewire::test(Inventory::class)->set('optionId', 'drink-one')->set('quantity', 1)->set('cost', '0')
            ->set('supplier', 'Supplier')->call('receive')->assertHasErrors('cost');
        $this->assertSame($before, SessionBatches::state());
        $sale = SessionSales::complete('sale', ['drink-one' => 1], 'Maya', '', true);
        SessionCash::recordMovement('withdraw', 'out', '1000', 'Remove float');
        $before = SessionBatches::state();
        $this->reject(fn () => SessionReturns::complete('refund', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Customer returned item'), 'refund');
        $this->assertSame($before, SessionBatches::state());
        $this->assertSame(1, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionReturns::all());
        $this->balanced();
    }

    public function test_inconsistent_ledger_is_rejected_without_inventing_balances(): void
    {
        $this->emptyDrink();
        $batch = $this->delivery(2, '2026-10-15', 'early');
        $product = DemoCatalogue::find(1);
        $product['stock'] = 5;
        DemoCatalogue::save($product);
        $before = SessionBatches::state();
        $this->reject(fn () => SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false), 'batches');
        $this->assertSame($before, SessionBatches::state());
        $this->assertSame(2, SessionBatches::state()['batches'][$batch['id']]['remaining']);
        $this->assertEmpty(SessionSales::all());
    }

    public function test_equal_expiry_dates_preserve_receiving_order_and_actual_batch_costs(): void
    {
        $this->emptyDrink();
        $first = $this->delivery(2, '2026-11-01', 'first');
        $second = $this->delivery(2, '2026-11-01', 'second');
        $product = DemoCatalogue::find(1);
        $product['baseCost'] = 1000;
        DemoCatalogue::save($product);
        $sale = SessionSales::complete('sale', ['drink-one' => 3], 'Cash', '60', false);
        $this->assertSame([$first['id'], $second['id']], array_column($sale['lines'][0]['batchAllocations'], 'batchId'));
        $this->assertSame([2, 1], array_column($sale['lines'][0]['batchAllocations'], 'quantity'));
        $this->assertSame(3705, $sale['lines'][0]['cost']);
        $this->assertSame(2470, $sale['lines'][0]['batchAllocations'][0]['originalTotalCost']);
        $this->balanced();
    }

    public function test_local_midnight_expires_stock_even_when_utc_date_has_not_changed(): void
    {
        $this->emptyDrink();
        $batch = $this->delivery(2, '2026-10-09', 'today');
        $this->travelTo(now('Asia/Shanghai')->setDate(2026, 10, 10)->setTime(0, 15));
        $this->assertSame('Expired', SessionBatches::overview()['batches'][$batch['id']]['status']);
        $this->reject(fn () => SessionSales::complete('expired', ['drink-one' => 1], 'Cash', '20', false), 'cart');
        Livewire::test(Inventory::class)->set('optionId', 'drink-one')->set('cost', '10')->set('supplier', 'Supplier')
            ->set('expiry', '2026-10-09')->call('receive')->assertHasErrors('expiry');
        $this->assertSame(2, DemoCatalogue::find(1)['stock']);
        $this->balanced();
    }
}
