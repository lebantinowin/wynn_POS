<?php

namespace App\Livewire;

use App\Support\SessionCash;
use App\Support\SessionCredit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Credit extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $search = '';

    public string $customerId = '';

    public string $amount = '';

    public string $method = 'Cash';

    public string $dueDate = '';

    public string $notes = '';

    public string $debtId = '';

    public string $repaymentAmount = '';

    public string $repaymentMethod = 'Cash';

    public string $reference = '';

    public string $message = '';

    #[Locked]
    public string $loanEventId = '';

    #[Locked]
    public string $repaymentEventId = '';

    #[Locked]
    public ?string $cashSessionId = null;

    public function mount(): void
    {
        $this->loanEventId = (string) Str::uuid();
        $this->repaymentEventId = (string) Str::uuid();
        $this->cashSessionId = SessionCash::active()['id'] ?? null;
    }

    public function addCustomer(): void
    {
        $this->resetErrorBag();
        SessionCredit::addCustomer($this->name, $this->phone);
        $this->reset('name', 'phone');
        $this->message = 'Customer added. You can now select them at checkout or record a cash loan.';
    }

    public function lend(): void
    {
        $this->resetErrorBag();
        SessionCredit::cashLoan($this->loanEventId, $this->customerId, $this->amount, $this->method, $this->dueDate, $this->notes, $this->cashSessionId);
        $this->loanEventId = (string) Str::uuid();
        $this->reset('customerId', 'amount', 'dueDate', 'notes');
        $this->message = 'Cash loan recorded in this session.';
    }

    public function repay(): void
    {
        $this->resetErrorBag();
        SessionCredit::repay($this->repaymentEventId, $this->debtId, $this->repaymentAmount, $this->repaymentMethod, $this->reference, $this->cashSessionId);
        $this->repaymentEventId = (string) Str::uuid();
        $this->reset('debtId', 'repaymentAmount', 'reference');
        $this->message = 'Repayment recorded. Customer balance updated.';
    }

    public function selectDebt(string $id): void
    {
        $debt = SessionCredit::debts()[$id] ?? null;
        if ($debt && $debt['balance'] > 0) {
            $this->debtId = $id;
            $this->repaymentAmount = number_format($debt['balance'] / 100, 2, '.', '');
            $this->resetErrorBag();
            $this->dispatch('repayment-selected');
        }
    }

    public function updatedDebtId(): void
    {
        $this->reset('repaymentAmount', 'reference');
    }

    public function render(): View
    {
        $customers = SessionCredit::customers();
        $debts = array_reverse(SessionCredit::debts(), true);
        $repayments = array_reverse(SessionCredit::repayments());
        $match = fn (array $entry): bool => $this->search === '' || str_contains(strtolower($entry['customer'].' '.($entry['number'] ?? '')), strtolower(trim($this->search)));

        return view('livewire.credit', ['customers' => $customers, 'debts' => array_filter($debts, $match),
            'repayments' => array_filter($repayments, $match), 'openDebts' => array_filter($debts, fn (array $debt): bool => $debt['balance'] > 0),
            'outstanding' => array_sum(array_column($debts, 'balance')), 'today' => now('Asia/Shanghai')->toDateString()]);
    }
}
