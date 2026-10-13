<?php

namespace Tests\Feature;

use App\Livewire\Products;
use App\Livewire\Sales;
use App\Support\BatchCost;
use App\Support\DemoCatalogue;
use App\Support\PosStore;
use App\Support\SessionAnalytics;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionLosses;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BatchCostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SessionCash::open('opening', '1000', '');
    }

    private function stock(array $quantitiesAndCosts): array
    {
        $products = DemoCatalogue::defaults();
        foreach ($products as &$product) {
            $product['stock'] = 0;
            $product['baseCost'] = 9999;
        }
        unset($product);
        $state = ['batches' => [], 'products' => [1, 2], 'movements' => []];
        $ids = [];
        foreach ($quantitiesAndCosts as $index => [$quantity, $cost]) {
            $ids[] = SessionBatches::add($state, $products[0], $quantity, 'Delivery', 'delivery-'.$index,
                'Batch '.$index, now('Asia/Shanghai')->addDays($index + 1)->toDateString(), $cost);
            $products[0]['stock'] += $quantity;
        }
        PosStore::put(['demo.products' => $products] + SessionBatches::changes($state));

        return $ids;
    }

    private function refund(array $sale, int $quantity, string $event, string $disposition = 'Restock'): array
    {
        return SessionReturns::complete($event, $sale['id'], [['quantity' => $quantity, 'disposition' => $disposition]],
            'Cash', true, 'Returned stock');
    }

    public function test_multiple_batch_package_returns_reverse_the_actual_chunks_instead_of_an_average(): void
    {
        [$first, $second] = $this->stock([[3, 100], [9, 1800]]);
        $sale = SessionSales::complete('sale', ['drink-pack' => 2], 'Cash', '220', false);
        $this->assertSame(1900, $sale['lines'][0]['cost']);
        $this->assertSame([100, 1800], array_column($sale['lines'][0]['batchAllocations'], 'cost'));
        $firstReturn = $this->refund($sale, 1, 'first-return');
        $secondReturn = $this->refund($sale, 1, 'second-return', 'Discard');
        $this->assertSame(700, $firstReturn['lines'][0]['cost']);
        $this->assertSame(1200, $secondReturn['lines'][0]['cost']);
        $this->assertSame(1200, SessionLosses::all()[0]['totalCost']);
        $this->assertSame(100, SessionBatches::overview()['batches'][$first]['remainingCost']);
        $this->assertSame(600, SessionBatches::overview()['batches'][$second]['remainingCost']);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(0, $summary['grossProfit']);
        $this->assertSame(-1200, $summary['operatingProfit']);
        $this->assertSame($sale, SessionSales::all()[$sale['id']]);
        $this->assertSame($secondReturn, $this->refund($sale, 1, 'second-return', 'Discard'));
        $resale = SessionSales::complete('resale', ['drink-pack' => 1], 'Cash', '110', false);
        $this->assertSame(700, $resale['lines'][0]['cost']);
        $this->assertSame(0, SessionBatches::overview()['totals']['knownCost']);
    }

    public function test_fractional_cents_are_conserved_across_sales_returns_and_resales(): void
    {
        [$batch] = $this->stock([[3, 100]]);
        $sale = SessionSales::complete('sale', ['drink-one' => 3], 'Cash', '60', false);
        $costs = [];
        for ($index = 0; $index < 3; $index++) {
            $return = $this->refund($sale, 1, 'return-'.$index);
            $costs[] = $return['lines'][0]['cost'];
        }
        $this->assertSame([33, 33, 34], $costs);
        $this->assertSame(100, SessionBatches::overview()['batches'][$batch]['remainingCost']);
        $this->assertSame(0, SessionAnalytics::summarize(7)['knownSalesCost']);
        $resaleCosts = [];
        for ($index = 0; $index < 3; $index++) {
            $resaleCosts[] = SessionSales::complete('resale-'.$index, ['drink-one' => 1], 'Cash', '20', false)['lines'][0]['cost'];
        }
        $this->assertSame($costs, $resaleCosts);
        $this->assertSame(0, SessionBatches::overview()['batches'][$batch]['remainingCost']);
    }

    public function test_allocation_rounding_is_independent_of_checkout_line_grouping(): void
    {
        $this->stock([[5, 3]]);
        $costs = [];
        for ($index = 0; $index < 5; $index++) {
            $costs[] = SessionSales::complete('sale-'.$index, ['drink-one' => 1], 'Cash', '20', false)['lines'][0]['cost'];
        }
        $this->assertSame([0, 1, 0, 1, 1], $costs);
        $this->assertSame(3, array_sum($costs));
        $this->assertSame(1, BatchCost::total([['basisCost' => 3, 'basisQuantity' => 5, 'offset' => 0, 'quantity' => 2]]));
        $this->assertSame(99999998000000, BatchCost::portion(99999999000000, 100000000, 99999999));
    }

    public function test_preview_and_product_price_cost_edits_do_not_revalue_remaining_batches(): void
    {
        [$batch] = $this->stock([[6, 6125]]);
        $before = SessionBatches::state();
        $draft = SessionSales::draft(['drink-one' => 1], 'Cash', '20', false);
        $this->assertSame(1020, $draft['lines'][0]['cost']);
        $this->assertSame($before, SessionBatches::state());
        $this->assertEmpty(SessionSales::all());
        Livewire::test(Products::class)->call('edit', 1)->set('baseCost', '1')->call('save')->assertHasNoErrors();
        $sale = SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false);
        $this->assertSame(1020, $sale['lines'][0]['cost']);
        $this->assertSame(5105, SessionBatches::overview()['batches'][$batch]['remainingCost']);
        $this->assertSame(6125, SessionBatches::state()['batches'][$batch]['totalCost']);
        Livewire::test(Sales::class)->call('viewReceipt', $sale['id'])->assertSee('Saved acquisition costs')->assertSee('10.20');
    }

    public function test_unknown_and_free_batch_costs_are_distinct_and_known_partial_returns_work(): void
    {
        $this->stock([[2, 101], [2, null]]);
        $sale = SessionSales::complete('mixed', ['drink-one' => 4], 'Cash', '80', false);
        $this->assertNull($sale['lines'][0]['cost']);
        $this->assertSame(101, $sale['lines'][0]['knownCost']);
        $this->assertSame(2, $sale['lines'][0]['unknownUnits']);
        $this->assertNull(SessionAnalytics::summarize(7)['grossProfit']);
        $known = $this->refund($sale, 2, 'known');
        $this->assertSame(101, $known['lines'][0]['cost']);
        $unknown = $this->refund($sale, 2, 'unknown', 'Discard');
        $this->assertNull($unknown['lines'][0]['cost']);
        $this->assertNull(SessionLosses::all()[0]['totalCost']);
        $this->assertNull(SessionAnalytics::summarize(7)['operatingProfit']);
        $this->assertSame(0, BatchCost::total([['basisCost' => 0, 'basisQuantity' => 2, 'quantity' => 2, 'offset' => 0]]));
    }

    public function test_losses_use_recorded_cost_and_manual_entry_only_values_unknown_units(): void
    {
        [$known, $unknown] = $this->stock([[2, 101], [4, null]]);
        SessionLosses::record('known-loss', 'drink-one', 1, 'Damage', 'Damaged unit', '999', $known);
        $this->assertSame(50, SessionLosses::all()[0]['totalCost']);
        SessionLosses::record('unknown-loss', 'drink-one', 2, 'Damage', 'Damaged unknown units', '3', $unknown);
        $this->assertSame(600, SessionLosses::all()[1]['totalCost']);
        $this->assertStringContainsString('manual estimate', SessionLosses::all()[1]['costBasis']);
        SessionLosses::record('mixed-loss', 'drink-one', 3, 'Damage', 'Remaining stock lost', '3');
        $this->assertSame(651, SessionLosses::all()[2]['totalCost']);
        $this->assertSame(1301, SessionAnalytics::summarize(7)['lossCost']);
        $this->assertSame(0, DemoCatalogue::find(1)['stock']);
        $this->assertSame(0, SessionBatches::overview()['totals']['knownCost']);
    }

    public function test_uncosted_mixed_loss_discloses_known_portion_and_withholds_operating_profit(): void
    {
        $this->stock([[2, 101], [4, null]]);
        SessionLosses::record('mixed', 'drink-pack', 1, 'Damage', 'Whole pack damaged', '');
        $this->assertNull(SessionLosses::all()[0]['totalCost']);
        $this->assertSame(101, SessionLosses::all()[0]['knownCost']);
        $this->assertSame(101, SessionAnalytics::summarize(7)['lossCost']);
        $this->assertNull(SessionAnalytics::summarize(7)['operatingProfit']);
    }

    public function test_legacy_receipt_costs_are_not_recalculated_and_unknown_returns_stay_unknown(): void
    {
        [$batch] = $this->stock([[3, 100]]);
        $sale = SessionSales::complete('sale', ['drink-one' => 3], 'Cash', '60', false);
        unset($sale['lines'][0]['costMethod']);
        foreach ($sale['lines'][0]['batchAllocations'] as &$allocation) {
            unset($allocation['costSegments'], $allocation['cost']);
        }
        unset($allocation);
        $sale['lines'][0]['cost'] = 301;
        PosStore::put('demo.sales', [$sale['id'] => $sale]);
        $first = $this->refund($sale, 1, 'first');
        $rest = $this->refund($sale, 2, 'rest');
        $this->assertSame(100, $first['lines'][0]['cost']);
        $this->assertSame(201, $rest['lines'][0]['cost']);
        $this->assertSame($sale, SessionSales::all()[$sale['id']]);
        $this->assertNull(SessionBatches::overview()['batches'][$batch]['remainingCost']);
        $this->assertSame(3, SessionBatches::overview()['totals']['unknownCostUnits']);
    }

    public function test_counts_preserve_cost_of_remaining_stock_and_increases_are_unknown(): void
    {
        [$batch] = $this->stock([[3, 100]]);
        Livewire::test(Products::class)->call('startCount', 1)->set('physicalCount', 2)->set('countReason', 'Physical count')->call('saveCount')->assertHasNoErrors();
        $this->assertSame(67, SessionBatches::overview()['batches'][$batch]['remainingCost']);
        Livewire::test(Products::class)->call('startCount', 1)->set('physicalCount', 3)->set('countReason', 'Found extra stock')->call('saveCount')->assertHasNoErrors();
        $this->assertSame(67, SessionBatches::overview()['totals']['knownCost']);
        $this->assertSame(1, SessionBatches::overview()['totals']['unknownCostUnits']);
        $sale = SessionSales::complete('sale', ['drink-one' => 3], 'Cash', '60', false);
        $this->assertNull($sale['lines'][0]['cost']);
        $this->assertSame(67, $sale['lines'][0]['knownCost']);
    }

    public function test_missing_new_sale_allocations_cannot_be_returned_at_an_invented_zero_cost(): void
    {
        $this->stock([[3, 100]]);
        $sale = SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false);
        unset($sale['lines'][0]['batchAllocations']);
        PosStore::put('demo.sales', [$sale['id'] => $sale]);
        $before = SessionBatches::state();
        try {
            $this->refund($sale, 1, 'return');
            $this->fail('A missing allocation must not be treated as free stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('return', $exception->errors());
        }
        $this->assertSame($before, SessionBatches::state());
        $this->assertEmpty(SessionReturns::all());
    }

    public function test_corrupt_cost_quantities_cannot_leave_partial_sales_or_stock_changes(): void
    {
        [$batch] = $this->stock([[3, 100]]);
        $state = SessionBatches::state();
        $state['batches'][$batch]['costSegments'] = [['basisCost' => 100, 'basisQuantity' => 3, 'quantity' => 2, 'offset' => 0]];
        PosStore::put(SessionBatches::changes($state));
        try {
            SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false);
            $this->fail('Corrupt cost quantities must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('batches', $exception->errors());
        }
        $this->assertSame(3, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionSales::all());
        $this->assertEmpty(SessionCash::movements());

    }
}
