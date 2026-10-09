<?php

namespace App\Livewire;

use App\Support\DemoCatalogue;
use Illuminate\Support\Str;
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

    public function receive(): void
    {
        $this->supplier = trim($this->supplier);
        $this->reference = trim($this->reference);
        $this->validate([
            'optionId' => 'required|string', 'quantity' => 'required|integer|min:1|max:100000',
            'cost' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'supplier' => 'required|string|max:120', 'reference' => 'nullable|string|max:100',
            'expiry' => 'nullable|date_format:Y-m-d|after_or_equal:today',
        ]);
        $option = DemoCatalogue::option($this->optionId);
        if (!$option) { $this->addError('optionId', 'Select an available sale option.'); return; }
        $product = DemoCatalogue::find($option['productId']);
        if (($product['trackExpiry'] ?? false) && $this->expiry === '') { $this->addError('expiry', 'Enter an expiry date for this product.'); return; }
        [$whole, $fraction] = array_pad(explode('.', $this->cost, 2), 2, '');
        $minorCost = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($minorCost < 1) { $this->addError('cost', 'Purchase cost must be greater than zero.'); return; }
        $baseQuantity = (int) $this->quantity * $option['units'];
        if ($product['stock'] + $baseQuantity > 100000000) { $this->addError('quantity', 'Resulting stock exceeds the supported limit.'); return; }
        $before = $product['stock'];
        $product['stock'] += $baseQuantity;
        DemoCatalogue::save($product);
        $history = session()->get('demo.receiving', []);
        $history[] = ['id' => (string) Str::uuid(), 'productId' => $product['id'], 'name' => $product['name'],
            'option' => $option['label'], 'unitsPerOption' => $option['units'], 'quantity' => (int) $this->quantity,
            'baseQuantity' => $baseQuantity, 'before' => $before, 'after' => $product['stock'],
            'costPerOption' => $minorCost, 'totalCost' => $minorCost * (int) $this->quantity,
            'supplier' => $this->supplier, 'reference' => $this->reference, 'expiry' => $this->expiry,
            'actor' => 'Owner preview', 'time' => now()->toIso8601String()];
        session()->put('demo.receiving', $history);
        $this->reset('optionId', 'quantity', 'cost', 'supplier', 'reference', 'expiry');
        $this->message = "Received $baseQuantity base units. Stock updated in this browser session.";
    }

    public function render()
    {
        return view('livewire.inventory', ['products' => DemoCatalogue::products(),
            'selected' => DemoCatalogue::option($this->optionId),
            'history' => array_reverse(session()->get('demo.receiving', []))]);
    }
}
