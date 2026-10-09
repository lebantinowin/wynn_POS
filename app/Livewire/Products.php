<?php

namespace App\Livewire;

use App\Support\DemoCatalogue;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Products extends Component
{
    public string $search = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $sku = '';

    public string $category = '';

    public string $unit = '';

    public mixed $openingStock = 0;

    public mixed $reorder = 0;

    public mixed $baseCost = '';

    public bool $trackExpiry = false;

    public string $openingExpiry = '';

    public array $options = [];

    #[Locked]
    public ?int $countProductId = null;

    public mixed $physicalCount = 0;

    public string $countReason = '';

    public string $message = '';

    public function create(): void
    {
        $this->resetErrorBag();
        $this->reset('editingId', 'name', 'sku', 'category', 'unit', 'openingStock', 'reorder', 'baseCost', 'trackExpiry', 'openingExpiry', 'message');
        $this->options = [['label' => 'Individual', 'units' => 1, 'price' => '', 'barcode' => '']];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $product = DemoCatalogue::find($id);
        if (! $product) {
            return;
        }
        $this->resetErrorBag();
        $this->editingId = $id;
        foreach (['name', 'sku', 'category', 'unit'] as $key) {
            $this->$key = $product[$key];
        }
        $this->reorder = $product['reorder'] ?? 0;
        $this->trackExpiry = $product['trackExpiry'] ?? false;
        $this->openingExpiry = $product['openingExpiry'] ?? '';
        $this->openingStock = $product['stock'];
        $this->baseCost = isset($product['baseCost']) ? number_format($product['baseCost'] / 100, 2, '.', '') : '';
        $this->options = array_map(fn ($o) => ['label' => $o['label'], 'units' => $o['units'], 'price' => number_format($o['price'] / 100, 2, '.', ''), 'barcode' => $o['barcode']], $product['options']);
        $this->showForm = true;
        $this->message = '';
    }

    public function addOption(): void
    {
        if (count($this->options) < 20) {
            $this->options[] = ['label' => '', 'units' => 1, 'price' => '', 'barcode' => ''];
        }
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $this->name = trim($this->name);
        $this->sku = strtoupper(trim($this->sku));
        $this->category = trim($this->category);
        $this->unit = trim($this->unit);
        $this->openingExpiry = trim($this->openingExpiry);
        if (is_string($this->baseCost)) {
            $this->baseCost = trim($this->baseCost);
        }
        foreach ($this->options as &$option) {
            $option['label'] = trim((string) ($option['label'] ?? ''));
            $option['barcode'] = trim((string) ($option['barcode'] ?? ''));
        }
        unset($option);
        $this->validate([
            'name' => 'required|string|max:120', 'sku' => 'required|string|max:50',
            'category' => 'required|string|max:80', 'unit' => 'required|string|max:30',
            'openingStock' => 'required|integer|min:0|max:100000000', 'reorder' => 'required|integer|min:0|max:100000000',
            'baseCost' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'openingExpiry' => 'nullable|date_format:Y-m-d',
            'options' => 'required|array|min:1|max:20', 'options.*.label' => 'required|string|max:60',
            'options.*.units' => 'required|integer|min:1|max:100000',
            'options.*.price' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'options.*.barcode' => 'nullable|string|max:100',
        ]);
        $existing = $this->editingId === null ? null : DemoCatalogue::find($this->editingId);
        if ($this->editingId !== null && ! $existing) {
            $this->addError('name', 'Product no longer available.');

            return;
        }
        if ($existing && count($this->options) < count($existing['options'])) {
            $this->addError('options', 'Existing sale options must be retained.');

            return;
        }
        if (! $existing) {
            if ($this->openingExpiry !== '' && (int) $this->openingStock === 0) {
                $this->addError('openingExpiry', 'Enter an opening stock quantity for this expiry date.');
            }
            if ($this->trackExpiry && (int) $this->openingStock > 0 && $this->openingExpiry === '') {
                $this->addError('openingExpiry', 'Enter the expiry date of your opening stock.');
            }
            if ($this->openingExpiry !== '') {
                $this->trackExpiry = true;
            }
        }
        $barcodes = [];
        $labels = [];
        $baseUnitPresent = false;
        foreach ($this->options as $index => $option) {
            $units = (int) $option['units'];
            if ($units === 1) {
                $baseUnitPresent = true;
            }
            $label = strtolower($option['label']);
            if (in_array($label, $labels, true)) {
                $this->addError("options.$index.label", 'Use a distinct label for each sale option.');
            }
            $labels[] = $label;
            $code = $option['barcode'];
            if ($code !== '' && in_array($code, $barcodes, true)) {
                $this->addError("options.$index.barcode", 'Barcode already used in this product.');
            }
            if ($code !== '') {
                $barcodes[] = $code;
            }
            if (self::minorUnits($option['price']) === 0) {
                $this->addError("options.$index.price", 'Selling price must be greater than zero.');
            }
            if (isset($existing['options'][$index]) && $units !== $existing['options'][$index]['units']) {
                $this->addError("options.$index.units", 'Existing conversion is fixed. Add a new option instead.');
            }
            foreach (DemoCatalogue::products() as $product) {
                if ($product['id'] === $this->editingId) {
                    continue;
                }
                if ($product['sku'] === $this->sku) {
                    $this->addError('sku', 'SKU already belongs to another product.');
                }
                foreach ($product['options'] as $other) {
                    if ($code !== '' && $code === $other['barcode']) {
                        $this->addError("options.$index.barcode", 'Barcode already belongs to another product.');
                    }
                }
            }
        }
        if (! $baseUnitPresent) {
            $this->addError('options', 'Include an individual sale option with 1 base unit.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $id = $existing['id'] ?? max(array_column(DemoCatalogue::products(), 'id')) + 1;
        $saleOptions = [];
        foreach ($this->options as $index => $option) {
            $saleOptions[] = [
                'id' => $existing['options'][$index]['id'] ?? (string) Str::uuid(), 'label' => $option['label'],
                'units' => (int) $option['units'], 'price' => self::minorUnits($option['price']), 'barcode' => $option['barcode'],
            ];
        }
        DemoCatalogue::save(['id' => $id, 'name' => $this->name, 'sku' => $this->sku, 'category' => $this->category,
            'unit' => $this->unit, 'stock' => $existing['stock'] ?? (int) $this->openingStock,
            'reorder' => (int) $this->reorder, 'baseCost' => $this->baseCost === '' || $this->baseCost === null ? null : self::minorUnits((string) $this->baseCost),
            'openingExpiry' => $existing ? ($existing['openingExpiry'] ?? null) : ($this->openingExpiry ?: null),
            'openingQuantity' => $existing ? ($existing['openingQuantity'] ?? null) : (int) $this->openingStock,
            'trackExpiry' => $this->trackExpiry, 'options' => $saleOptions]);
        $this->showForm = false;
        $this->message = 'Product saved in this browser session. It is available in sample checkout.';
    }

    private static function minorUnits(string $price): int
    {
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function startCount(int $id): void
    {
        $product = DemoCatalogue::find($id);
        if (! $product) {
            return;
        }
        $this->resetErrorBag();
        $this->countProductId = $id;
        $this->physicalCount = $product['stock'];
        $this->countReason = '';
    }

    public function saveCount(): void
    {
        $this->countReason = trim($this->countReason);
        $this->validate(['physicalCount' => 'required|integer|min:0|max:100000000', 'countReason' => 'required|string|min:3|max:300']);
        $product = $this->countProductId === null ? null : DemoCatalogue::find($this->countProductId);
        if (! $product) {
            return;
        }
        $before = $product['stock'];
        $product['stock'] = (int) $this->physicalCount;
        DemoCatalogue::save($product);
        DemoCatalogue::recordCount(['productId' => $product['id'], 'name' => $product['name'], 'before' => $before,
            'after' => $product['stock'], 'difference' => $product['stock'] - $before,
            'reason' => $this->countReason, 'actor' => 'Owner preview', 'time' => now()->toIso8601String()]);
        $this->countProductId = null;
        $this->message = 'Physical count recorded in this browser session.';
    }

    public function cancelCount(): void
    {
        $this->countProductId = null;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.products', [
            'products' => array_filter(DemoCatalogue::products(), fn ($p) => str_contains(strtolower($p['name'].' '.$p['sku'].' '.$p['category']), strtolower($this->search))),
            'countProduct' => $this->countProductId === null ? null : DemoCatalogue::find($this->countProductId),
            'editingProduct' => $this->editingId === null ? null : DemoCatalogue::find($this->editingId),
            'history' => array_reverse(session()->get('demo.counts', [])),
        ]);
    }
}
