<?php

namespace App\Livewire;

use App\Support\DemoCatalogue;
use App\Support\SessionPurchasing;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Purchasing extends Component
{
    #[Locked]
    public ?string $supplierEditingId = null;

    public string $name = '';

    public string $contact = '';

    public string $phone = '';

    public string $email = '';

    public string $supplierNotes = '';

    public string $supplierId = '';

    public string $orderNotes = '';

    public array $lines = [['optionId' => '', 'quantity' => 1, 'cost' => '']];

    #[Locked]
    public ?string $receivingOrderId = null;

    #[Locked]
    public string $receivingEventId = '';

    public array $deliveries = [];

    public string $reference = '';

    public string $message = '';

    public function saveSupplier(): void
    {
        SessionPurchasing::saveSupplier($this->supplierEditingId, ['name' => $this->name, 'contact' => $this->contact,
            'phone' => $this->phone, 'email' => $this->email, 'notes' => $this->supplierNotes]);
        $this->cancelSupplierEdit();
        $this->message = 'Supplier saved in this browser session.';
    }

    public function editSupplier(string $id): void
    {
        $supplier = SessionPurchasing::suppliers()[$id] ?? null;
        if (! $supplier) {
            return;
        }
        $this->resetErrorBag();
        $this->supplierEditingId = $id;
        foreach (['name', 'contact', 'phone', 'email'] as $key) {
            $this->$key = $supplier[$key];
        }
        $this->supplierNotes = $supplier['notes'];
    }

    public function cancelSupplierEdit(): void
    {
        $this->reset('supplierEditingId', 'name', 'contact', 'phone', 'email', 'supplierNotes');
        $this->resetErrorBag();
    }

    public function addLine(): void
    {
        if (count($this->lines) < 30) {
            $this->lines[] = ['optionId' => '', 'quantity' => 1, 'cost' => ''];
        }
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) > 1 && isset($this->lines[$index])) {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
        }
    }

    public function createOrder(): void
    {
        SessionPurchasing::createOrder($this->supplierId, $this->orderNotes, $this->lines);
        $this->reset('supplierId', 'orderNotes', 'lines');
        $this->resetErrorBag();
        $this->message = 'Purchase order recorded. Stock changes only when a delivery is received.';
    }

    public function startReceiving(string $id): void
    {
        $order = SessionPurchasing::orders()[$id] ?? null;
        if (! $order || $order['status'] === 'Received') {
            return;
        }
        $this->resetErrorBag();
        $this->receivingOrderId = $id;
        $this->receivingEventId = (string) Str::uuid();
        $this->reference = '';
        $this->deliveries = array_map(fn ($line) => ['quantity' => 0, 'cost' => number_format($line['cost'] / 100, 2, '.', ''), 'expiry' => ''], $order['lines']);
    }

    public function cancelReceiving(): void
    {
        $this->reset('receivingOrderId', 'receivingEventId', 'deliveries', 'reference');
        $this->resetErrorBag();
    }

    public function receiveOrder(): void
    {
        if ($this->receivingOrderId === null) {
            return;
        }
        SessionPurchasing::receive($this->receivingOrderId, $this->deliveries, $this->reference, $this->receivingEventId);
        $this->cancelReceiving();
        $this->message = 'Delivery recorded. Stock and order status updated in this session.';
    }

    public function render(): View
    {
        return view('livewire.purchasing', ['suppliers' => SessionPurchasing::suppliers(), 'products' => DemoCatalogue::products(),
            'orders' => array_reverse(SessionPurchasing::orders(), true),
            'receivingOrder' => SessionPurchasing::orders()[$this->receivingOrderId ?? ''] ?? null]);
    }
}
