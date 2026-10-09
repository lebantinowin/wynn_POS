<?php

namespace App\Livewire;

use App\Support\SessionCash;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Cash extends Component
{
    public string $opening = '';

    public string $notes = '';

    public string $direction = 'in';

    public string $amount = '';

    public string $reason = '';

    public string $expenseAmount = '';

    public string $category = 'Utilities';

    public string $method = 'Cash';

    public string $expenseNotes = '';

    public string $counted = '';

    public string $closeNotes = '';

    public string $message = '';

    #[Locked]
    public ?string $cashSessionId = null;

    #[Locked]
    public ?string $selectedId = null;

    #[Locked]
    public string $openEventId = '';

    #[Locked]
    public string $movementEventId = '';

    #[Locked]
    public string $expenseEventId = '';

    #[Locked]
    public string $closeEventId = '';

    public function mount(): void
    {
        $this->cashSessionId = SessionCash::active()['id'] ?? null;
        foreach (['openEventId', 'movementEventId', 'expenseEventId', 'closeEventId'] as $property) {
            $this->{$property} = (string) Str::uuid();
        }
    }

    public function open(): void
    {
        $this->resetErrorBag();
        $cashSession = SessionCash::open($this->openEventId, $this->opening, $this->notes);
        $this->cashSessionId = $cashSession['id'];
        $this->openEventId = (string) Str::uuid();
        $this->closeEventId = (string) Str::uuid();
        $this->reset('opening', 'notes', 'counted', 'closeNotes', 'selectedId');
        $this->message = $cashSession['number'].' opened. Checkout can now record sales.';
    }

    public function recordMovement(): void
    {
        $this->resetErrorBag();
        SessionCash::recordMovement($this->movementEventId, $this->direction, $this->amount, $this->reason, $this->cashSessionId);
        $this->movementEventId = (string) Str::uuid();
        $this->reset('amount', 'reason');
        $this->message = 'Drawer movement recorded.';
    }

    public function recordExpense(): void
    {
        $this->resetErrorBag();
        SessionCash::expense($this->expenseEventId, $this->expenseAmount, $this->category, $this->method, $this->expenseNotes, $this->cashSessionId);
        $this->expenseEventId = (string) Str::uuid();
        $this->reset('expenseAmount', 'expenseNotes');
        $this->message = 'Expense recorded. The payment is included once in the movement history.';
    }

    public function close(): void
    {
        $this->resetErrorBag();
        $cashSession = SessionCash::close($this->cashSessionId ?? '', $this->closeEventId, $this->counted, $this->closeNotes);
        $this->selectedId = $cashSession['id'];
        $this->cashSessionId = null;
        $this->closeEventId = (string) Str::uuid();
        $this->reset('counted', 'closeNotes', 'amount', 'reason');
        $this->message = $cashSession['number'].' closed. Closing totals are preserved.';
        $this->dispatch('cash-summary-selected');
    }

    public function viewSession(string $id): void
    {
        if ((SessionCash::all()[$id]['status'] ?? null) === 'Closed') {
            $this->selectedId = $id;
            $this->dispatch('cash-summary-selected');
        }
    }

    public function render(): View
    {
        $active = SessionCash::active();
        $summary = $active ? SessionCash::summary($active['id']) : null;
        $counted = preg_match('/^\d{1,9}(\.\d{1,2})?$/', $this->counted) ? SessionCash::money($this->counted, 'counted') : null;
        $selected = SessionCash::all()[$this->selectedId ?? ''] ?? null;
        $targetId = $active['id'] ?? $selected['id'] ?? null;
        $movements = $targetId ? array_filter(SessionCash::movements(), fn (array $entry): bool => $entry['cashSessionId'] === $targetId) : [];

        return view('livewire.cash', ['active' => $active, 'summary' => $summary, 'countedValue' => $counted, 'selected' => $selected,
            'sessions' => array_reverse(SessionCash::all(), true), 'movements' => array_reverse($movements),
            'expenses' => array_reverse(SessionCash::expenses(), true), 'categories' => SessionCash::CATEGORIES]);
    }
}
