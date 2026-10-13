<?php

namespace App\Livewire;

use App\Support\DemoCatalogue;
use App\Support\PosStore;
use App\Support\SessionBatches;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Inventory extends Component
{
    public string $optionId = '';

    public mixed $quantity = 1;

    public string $cost = '';

    public string $supplier = '';

    public string $reference = '';

    public string $expiry = '';

    public string $message = '';

    public string $batchSearch = '';

    public string $batchStatus = 'active';

    #[Locked]
    public string $eventId = '';

    public function mount(): void
    {
        $this->eventId = (string) Str::uuid();
    }

    public function inspectBatch(string $id): void
    {
        if (isset(SessionBatches::overview()['batches'][$id])) {
            $this->batchSearch = $id;
            $this->batchStatus = 'all';
            $this->dispatch('inventory-batch-selected');
        }
    }

    public function receive(): void
    {
        if (PosStore::needsTransaction()) {
            PosStore::atomic(fn () => $this->receive());

            return;
        }

        foreach (PosStore::get('demo.receiving', []) as $entry) {
            if (($entry['eventId'] ?? null) === $this->eventId) {
                return;
            }
        }
        $this->supplier = trim($this->supplier);
        $this->reference = trim($this->reference);
        $this->validate([
            'optionId' => 'required|string', 'quantity' => 'required|integer|min:1|max:100000',
            'cost' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'supplier' => 'required|string|max:120', 'reference' => 'nullable|string|max:100',
            'expiry' => 'nullable|date_format:Y-m-d|after_or_equal:'.now('Asia/Shanghai')->toDateString(),
        ]);
        $option = DemoCatalogue::option($this->optionId);
        if (! $option) {
            $this->addError('optionId', 'Select an available sale option.');

            return;
        }
        $product = DemoCatalogue::find($option['productId']);
        if (($product['trackExpiry'] ?? false) && $this->expiry === '') {
            $this->addError('expiry', 'Enter an expiry date for this product.');

            return;
        }
        [$whole, $fraction] = array_pad(explode('.', $this->cost, 2), 2, '');
        $minorCost = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($minorCost < 1) {
            $this->addError('cost', 'Purchase cost must be greater than zero.');

            return;
        }
        $baseQuantity = (int) $this->quantity * $option['units'];
        if ($product['stock'] + $baseQuantity > 100000000) {
            $this->addError('quantity', 'Resulting stock exceeds the supported limit.');

            return;
        }
        $before = $product['stock'];
        $batches = SessionBatches::state();
        $product['stock'] += $baseQuantity;
        $history = PosStore::get('demo.receiving', []);
        $entry = ['id' => (string) Str::uuid(), 'eventId' => $this->eventId, 'productId' => $product['id'], 'name' => $product['name'],
            'option' => $option['label'], 'unitsPerOption' => $option['units'], 'quantity' => (int) $this->quantity,
            'baseQuantity' => $baseQuantity, 'before' => $before, 'after' => $product['stock'],
            'costPerOption' => $minorCost, 'totalCost' => $minorCost * (int) $this->quantity,
            'supplier' => $this->supplier, 'reference' => $this->reference, 'expiry' => $this->expiry,
            'actor' => 'Owner', 'time' => now()->toIso8601String()];
        $entry['batchId'] = SessionBatches::add($batches, $product, $baseQuantity, 'Delivery', $entry['id'],
            $entry['reference'] ?: $this->supplier, $this->expiry, $entry['totalCost']);
        $history[] = $entry;
        $products = DemoCatalogue::products();
        $products[array_search($product['id'], array_column($products, 'id'), true)] = $product;
        SessionBatches::assertBalanced($batches, $products);
        PosStore::put(['demo.products' => $products, 'demo.receiving' => $history] + SessionBatches::changes($batches));
        $this->eventId = (string) Str::uuid();
        $this->reset('optionId', 'quantity', 'cost', 'supplier', 'reference', 'expiry');
        $this->message = "Received $baseQuantity base units. Stock updated.";
    }

    public function render(): View
    {
        $overview = SessionBatches::overview();
        $batches = array_filter($overview['batches'], function (array $batch): bool {
            $matches = str_contains(mb_strtolower($batch['name'].' '.$batch['reference'].' '.$batch['id']), mb_strtolower(trim($this->batchSearch)));

            return $matches && match ($this->batchStatus) {
                'expired' => $batch['status'] === 'Expired', 'soon' => $batch['status'] === 'Due within 30 days',
                'unknown' => $batch['status'] === 'Expiry unknown', 'depleted' => $batch['remaining'] === 0,
                'all' => true, default => $batch['remaining'] > 0,
            };
        });

        return view('livewire.inventory', ['products' => DemoCatalogue::products(),
            'selected' => DemoCatalogue::option($this->optionId),
            'history' => array_reverse(PosStore::get('demo.receiving', [])), 'batches' => $batches, 'overview' => $overview,
            'batchMovements' => array_reverse($overview['movements'])]);
    }
}
