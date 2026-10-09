<?php

namespace App\Livewire;

use App\Support\DemoCatalogue;
use App\Support\SessionLosses;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Losses extends Component
{
    public string $optionId = '';

    public mixed $quantity = 1;

    public string $reason = 'Unpaid consumption';

    public string $notes = '';

    public string $unitCost = '';

    public string $message = '';

    #[Locked]
    public string $eventId = '';

    public function mount(): void
    {
        $this->eventId = (string) Str::uuid();
    }

    public function updatedOptionId(): void
    {
        $this->unitCost = '';
    }

    public function record(): void
    {
        $this->resetErrorBag();
        SessionLosses::record($this->eventId, $this->optionId, $this->quantity, $this->reason, $this->notes, $this->unitCost);
        $this->eventId = (string) Str::uuid();
        $this->reset('optionId', 'quantity', 'notes', 'unitCost');
        $this->message = 'Stock loss recorded. Available stock updated in this session.';
    }

    public function render(): View
    {
        $option = DemoCatalogue::option($this->optionId);

        return view('livewire.losses', ['products' => DemoCatalogue::products(), 'losses' => array_reverse(SessionLosses::all()),
            'reasons' => SessionLosses::REASONS, 'option' => $option]);
    }
}
