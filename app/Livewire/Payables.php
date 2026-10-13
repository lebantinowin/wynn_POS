<?php

namespace App\Livewire;

use App\Support\SessionCash;
use App\Support\SessionPayables;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Payables extends Component
{
    public string $search = '';

    public string $status = 'all';

    #[Locked]
    public ?string $selectedId = null;

    #[Locked]
    public ?string $invoiceId = null;

    #[Locked]
    public ?string $cashSessionId = null;

    #[Locked]
    public string $eventId = '';

    public string $paymentAmount = '';

    public string $method = 'Cash';

    public bool $confirmed = false;

    public string $reference = '';

    public string $notes = '';

    public string $message = '';

    #[On('supplier-delivery-recorded')]
    public function refreshDeliveries(): void
    {
        $this->resetErrorBag();
    }

    public function viewInvoice(string $id): void
    {
        if (isset(SessionPayables::invoices()[$id])) {
            $this->selectedId = $id;
            $this->dispatch('supplier-bill-selected');
        }
    }

    public function startPayment(string $id): void
    {
        $invoice = SessionPayables::invoices()[$id] ?? null;
        if (! $invoice || $invoice['balance'] === 0) {
            return;
        }
        $this->cancelPayment();
        $this->invoiceId = $id;
        $this->eventId = (string) Str::uuid();
        $this->cashSessionId = SessionCash::active()['id'] ?? null;
        $this->paymentAmount = number_format($invoice['balance'] / 100, 2, '.', '');
        $this->dispatch('supplier-payment-selected');
    }

    public function closeInvoice(): void
    {
        $this->selectedId = null;
    }

    public function cancelPayment(): void
    {
        $this->reset('invoiceId', 'cashSessionId', 'eventId', 'paymentAmount', 'method', 'confirmed', 'reference', 'notes');
        $this->resetErrorBag();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['paymentAmount', 'method', 'reference'], true)) {
            $this->confirmed = false;
        }
    }

    public function pay(): void
    {
        if ($this->invoiceId === null) {
            return;
        }
        $payment = SessionPayables::pay($this->eventId, $this->invoiceId, $this->paymentAmount, $this->method,
            $this->confirmed, $this->reference, $this->notes, $this->cashSessionId);
        $this->cancelPayment();
        $this->message = $payment['number'].' recorded. Supplier balance and payment history updated.';
    }

    public function render(): View
    {
        $today = now('Asia/Shanghai')->toDateString();
        $search = mb_strtolower(trim($this->search));
        $invoices = array_filter(SessionPayables::invoices(), function (array $invoice) use ($search, $today): bool {
            $matches = $search === '' || str_contains(mb_strtolower(implode(' ', [
                $invoice['number'], $invoice['supplier'], $invoice['reference'], $invoice['orderNumber'],
            ])), $search);
            $overdue = $invoice['balance'] > 0 && $invoice['dueDate'] && $invoice['dueDate'] < $today;

            return $matches && match ($this->status) {
                'outstanding' => $invoice['balance'] > 0,
                'overdue' => $overdue,
                'paid' => $invoice['balance'] === 0,
                default => true,
            };
        });

        return view('livewire.payables', [
            'invoices' => array_reverse($invoices, true), 'balances' => SessionPayables::balances(),
            'payments' => array_reverse(SessionPayables::payments(), true), 'today' => $today,
            'selected' => SessionPayables::invoices()[$this->selectedId ?? ''] ?? null,
            'paying' => SessionPayables::invoices()[$this->invoiceId ?? ''] ?? null,
            'active' => SessionCash::active(), 'unlinked' => SessionPayables::unlinkedDeliveries(),
        ]);
    }
}
