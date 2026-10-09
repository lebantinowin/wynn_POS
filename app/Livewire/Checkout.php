<?php

namespace App\Livewire;

use App\Support\DemoCatalogue;
use Livewire\Component;

class Checkout extends Component
{
    public string $search = '';
    public array $cart = [];
    public string $method = 'Cash';
    public string $received = '';
    public bool $confirmed = false;
    public ?array $receipt = null;

    public function add(string $id): void
    {
        $option = DemoCatalogue::option($id);
        if (!$option) return;
        $quantity = ($this->cart[$id] ?? 0) + 1;
        $this->setQuantity($id, $quantity);
        $this->receipt = null;
    }

    public function setQuantity(string $id, mixed $quantity): void
    {
        $this->resetErrorBag();
        if (!DemoCatalogue::option($id) || filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 1 || (int) $quantity > 9999) {
            $this->addError('cart', 'Enter a whole quantity between 1 and 9999.');
            return;
        }
        $this->cart[$id] = (int) $quantity;
        $this->receipt = null;
    }

    public function remove(string $id): void { unset($this->cart[$id]); $this->receipt = null; }

    public function scan(): void
    {
        foreach (DemoCatalogue::products() as $product) foreach ($product['options'] as $option) {
            if ($option['barcode'] === trim($this->search)) { $this->add($option['id']); $this->search = ''; return; }
        }
        $this->addError('search', 'No matching barcode. Search by product name or SKU.');
    }

    public function lines(): array
    {
        $lines = [];
        foreach ($this->cart as $id => $quantity) {
            $option = DemoCatalogue::option($id);
            if ($option && is_int($quantity) && $quantity > 0 && $quantity <= 9999) $lines[] = $option + ['quantity' => $quantity, 'total' => $option['price'] * $quantity, 'baseQuantity' => $option['units'] * $quantity];
        }
        return $lines;
    }

    public function preview(): void
    {
        $this->resetErrorBag();
        $this->validate(['method' => 'required|in:Cash,GCash,Maya']);
        $lines = $this->lines();
        if (!$lines) { $this->addError('cart', 'Add a product first.'); return; }
        $total = array_sum(array_column($lines, 'total'));
        if ($this->method === 'Cash' && (!is_numeric($this->received) || round((float) $this->received * 100) < $total)) {
            $this->addError('received', 'Cash received must cover the total.'); return;
        }
        if ($this->method !== 'Cash' && !$this->confirmed) { $this->addError('confirmed', 'Confirm payment in the payment app first.'); return; }
        $this->receipt = ['lines' => $lines, 'total' => $total, 'method' => $this->method, 'change' => $this->method === 'Cash' ? (int) round((float) $this->received * 100) - $total : 0];
    }

    public function render()
    {
        $products = array_filter(DemoCatalogue::products(), fn ($p) => $this->search === '' || str_contains(strtolower($p['name'].' '.$p['sku'].' '.implode(' ', array_column($p['options'], 'barcode'))), strtolower($this->search)));
        $lines = $this->lines();
        return view('livewire.checkout', ['products' => $products, 'lines' => $lines, 'total' => array_sum(array_column($lines, 'total'))]);
    }
}
