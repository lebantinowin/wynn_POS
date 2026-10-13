<?php

namespace App\Livewire;

use App\Support\DemoCatalogue;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionSales;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Checkout extends Component
{
    public string $search = '';

    public array $cart = [];

    public string $method = 'Cash';

    public string $received = '';

    public string $customerId = '';

    public string $dueDate = '';

    public bool $confirmed = false;

    #[Locked]
    public ?array $receipt = null;

    #[Locked]
    public string $saleEventId = '';

    #[Locked]
    public ?string $cashSessionId = null;

    public string $message = '';

    public function mount(): void
    {
        $this->saleEventId = (string) Str::uuid();
        $this->cashSessionId = SessionCash::active()['id'] ?? null;
    }

    public function updatedMethod(): void
    {
        $this->confirmed = false;
        $this->receipt = null;
    }

    public function updatedReceived(): void
    {
        $this->receipt = null;
    }

    public function updatedConfirmed(): void
    {
        $this->receipt = null;
    }

    public function updatedCustomerId(): void
    {
        $this->receipt = null;
    }

    public function updatedDueDate(): void
    {
        $this->receipt = null;
    }

    public function add(string $id): void
    {
        $option = DemoCatalogue::option($id);
        if (! $option) {
            return;
        }
        $quantity = ($this->cart[$id] ?? 0) + 1;
        $this->setQuantity($id, $quantity);
        $this->receipt = null;
    }

    public function setQuantity(string $id, mixed $quantity): void
    {
        $this->resetErrorBag();
        if (! DemoCatalogue::option($id) || filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 1 || (int) $quantity > 9999) {
            $this->addError('cart', 'Enter a whole quantity between 1 and 9999.');

            return;
        }
        $this->cart[$id] = (int) $quantity;
        $this->receipt = null;
        $this->confirmed = false;
        $this->message = '';
    }

    public function remove(string $id): void
    {
        unset($this->cart[$id]);
        $this->receipt = null;
        $this->confirmed = false;
        $this->message = '';
    }

    public function scan(): void
    {
        foreach (DemoCatalogue::products() as $product) {
            foreach ($product['options'] as $option) {
                if ($option['barcode'] === trim($this->search)) {
                    $this->add($option['id']);
                    $this->search = '';

                    return;
                }
            }
        }
        $this->addError('search', 'No matching barcode. Search by product name or SKU.');
    }

    public function lines(): array
    {
        $lines = [];
        foreach ($this->cart as $id => $quantity) {
            $option = DemoCatalogue::option($id);
            if ($option && is_int($quantity) && $quantity > 0 && $quantity <= 9999) {
                $lines[] = $option + ['quantity' => $quantity, 'total' => $option['price'] * $quantity, 'baseQuantity' => $option['units'] * $quantity];
            }
        }

        return $lines;
    }

    public function preview(): void
    {
        $this->resetErrorBag();
        $this->receipt = null;
        $this->receipt = SessionSales::draft($this->cart, $this->method, $this->received, $this->confirmed, $this->customerId, $this->dueDate);
    }

    public function complete(): void
    {
        $this->resetErrorBag();
        $this->receipt = null;
        $sale = SessionSales::complete($this->saleEventId, $this->cart, $this->method, $this->received, $this->confirmed, $this->customerId, $this->dueDate, $this->cashSessionId);
        $this->reset('cart', 'search', 'received', 'confirmed', 'customerId', 'dueDate');
        $this->saleEventId = (string) Str::uuid();
        $this->receipt = $sale;
        $this->message = "Sale {$sale['number']} completed. Stock and batch costs updated.";
    }

    public function render(): View
    {
        $products = array_filter(DemoCatalogue::products(), fn ($p) => $this->search === '' || str_contains(strtolower($p['name'].' '.$p['sku'].' '.implode(' ', array_column($p['options'], 'barcode'))), strtolower($this->search)));
        $lines = $this->lines();

        return view('livewire.checkout', ['products' => $products, 'lines' => $lines, 'total' => array_sum(array_column($lines, 'total')),
            'customers' => SessionCredit::customers(), 'cashSession' => SessionCash::active(), 'batchStock' => SessionBatches::overview()['products']]);
    }
}
