<?php

namespace App\Livewire;

use App\Support\SessionCash;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Sales extends Component
{
    public string $search = '';

    public string $method = '';

    public string $period = 'all';

    public string $status = '';

    public array $returnLines = [];

    public string $refundMethod = 'Cash';

    public bool $refundConfirmed = false;

    public string $returnReason = '';

    public string $message = '';

    #[Locked]
    public ?string $selectedId = null;

    #[Locked]
    public ?string $returnSaleId = null;

    #[Locked]
    public ?string $selectedReturnId = null;

    #[Locked]
    public string $returnEventId = '';

    #[Locked]
    public ?string $cashSessionId = null;

    public function viewReceipt(string $id): void
    {
        if (isset(SessionSales::all()[$id])) {
            $this->selectedId = $id;
            $this->selectedReturnId = null;
            $this->dispatch('transaction-receipt');
        }
    }

    public function startReturn(string $id): void
    {
        $sale = SessionSales::all()[$id] ?? null;
        if (! $sale) {
            return;
        }
        $this->resetErrorBag();
        $this->reset('returnLines', 'returnReason', 'refundConfirmed', 'selectedId', 'selectedReturnId', 'message');
        $this->returnSaleId = $id;
        $this->returnEventId = (string) Str::uuid();
        $this->cashSessionId = SessionCash::active()['id'] ?? null;
        $this->refundMethod = $sale['method'] === 'Credit' ? 'Cash' : $sale['method'];
        foreach (SessionReturns::remaining($id) as $index => $line) {
            $this->returnLines[$index] = ['quantity' => 0, 'disposition' => 'Restock'];
        }
        $this->dispatch('return-form-open');
    }

    public function updatedReturnLines(): void
    {
        $this->refundConfirmed = false;
    }

    public function updatedRefundMethod(): void
    {
        $this->refundConfirmed = false;
    }

    public function cancelReturn(): void
    {
        $this->returnSaleId = null;
        $this->resetErrorBag();
    }

    public function completeReturn(): void
    {
        $this->resetErrorBag();
        if ($this->returnSaleId === null) {
            $this->addError('return', 'Select a receipt to return.');

            return;
        }
        $return = SessionReturns::complete($this->returnEventId, $this->returnSaleId, $this->returnLines,
            $this->refundMethod, $this->refundConfirmed, $this->returnReason, $this->cashSessionId);
        $this->returnSaleId = null;
        $this->selectedId = null;
        $this->selectedReturnId = $return['id'];
        $this->message = "Return {$return['number']} recorded. Original receipt preserved.";
        $this->dispatch('transaction-receipt');
    }

    public function viewReturn(string $id): void
    {
        if (isset(SessionReturns::all()[$id])) {
            $this->selectedReturnId = $id;
            $this->selectedId = null;
            $this->dispatch('transaction-receipt');
        }
    }

    private function matchesPeriod(string $time): bool
    {
        if (! in_array($this->period, ['7', '30', '90'], true)) {
            return true;
        }
        $date = Carbon::parse($time)->setTimezone('Asia/Shanghai')->toDateString();
        $today = now('Asia/Shanghai')->startOfDay();

        return $date >= $today->copy()->subDays((int) $this->period - 1)->toDateString() && $date <= $today->toDateString();
    }

    public function render(): View
    {
        $sales = [];
        foreach (SessionSales::all() as $id => $sale) {
            $totals = SessionReturns::saleTotals($id);
            $status = $totals['total'] === 0 ? 'Completed' : ($totals['total'] === $sale['total'] ? 'Returned' : 'Partially returned');
            $text = $sale['number'].' '.$sale['time'].' '.Carbon::parse($sale['time'])->setTimezone('Asia/Shanghai')->toDateString().' '.($sale['customer'] ?? '').' '.implode(' ', array_column($sale['lines'], 'name'));
            if (($this->method === '' || $sale['method'] === $this->method) && ($this->status === '' || $this->status === $status)
                && $this->matchesPeriod($sale['time']) && str_contains(strtolower($text), strtolower($this->search))) {
                $sales[$id] = $sale + ['returned' => $totals['total'], 'returnStatus' => $status];
            }
        }
        $returns = array_filter(SessionReturns::all(), fn (array $entry): bool => $this->matchesPeriod($entry['time'])
            && ($this->method === '' || $entry['saleMethod'] === $this->method || $entry['method'] === $this->method)
            && str_contains(strtolower($entry['number'].' '.$entry['saleNumber'].' '.$entry['time'].' '.Carbon::parse($entry['time'])->setTimezone('Asia/Shanghai')->toDateString().' '.($entry['customer'] ?? '').' '.implode(' ', array_column($entry['lines'], 'name'))), strtolower($this->search)));
        $returnSale = SessionSales::all()[$this->returnSaleId ?? ''] ?? null;
        $quote = null;
        if ($returnSale) {
            try {
                $quote = SessionReturns::preview($this->returnSaleId, $this->returnLines);
            } catch (ValidationException) {
                $quote = null;
            }
        }

        return view('livewire.sales', ['sales' => array_reverse($sales, true), 'selected' => SessionSales::all()[$this->selectedId ?? ''] ?? null,
            'returns' => array_reverse($returns, true), 'selectedReturn' => SessionReturns::all()[$this->selectedReturnId ?? ''] ?? null,
            'returnSale' => $returnSale, 'returnable' => $returnSale ? SessionReturns::remaining($this->returnSaleId) : [],
            'quote' => $quote, 'cashSession' => SessionCash::active()]);
    }
}
