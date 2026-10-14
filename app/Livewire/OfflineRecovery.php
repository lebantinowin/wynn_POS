<?php

namespace App\Livewire;

use App\Support\OfflineCheckout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OfflineRecovery extends Component
{
    #[Locked]
    public string $leaseId = '';

    #[Locked]
    public string $eventId = '';

    public string $reason = '';

    public string $confirmation = '';

    public bool $acknowledged = false;

    public string $message = '';

    public function mount(): void
    {
        $this->leaseId = app(OfflineCheckout::class)->active()?->id ?? '';
        $this->eventId = (string) Str::uuid();
        $this->message = session()->pull('offline.recovery.message', '');
    }

    public function release(): void
    {
        $this->resetErrorBag();
        $this->message = '';
        app(OfflineCheckout::class)->releaseUnavailable($this->leaseId, $this->eventId, $this->reason, $this->confirmation, $this->acknowledged);
        $this->reset('reason', 'confirmation', 'acknowledged');
        $this->leaseId = '';
        $this->eventId = (string) Str::uuid();
        $this->message = 'Counter 1 reopened. Unconfirmed sales have not been recovered. Review stock, drawer cash and customer balances before continuing.';
        session()->flash('offline.recovery.message', $this->message);
        $this->redirect('/settings', navigate: true);
    }

    public function render(): View
    {
        $offline = app(OfflineCheckout::class);
        $lease = $offline->active();
        $snapshot = $lease ? json_decode($lease->snapshot, true, flags: JSON_THROW_ON_ERROR) : null;

        return view('livewire.offline-recovery', ['available' => $offline->recoveryAvailable(), 'lease' => $lease, 'snapshot' => $snapshot,
            'expired' => $snapshot && now()->greaterThanOrEqualTo(Carbon::parse($snapshot['validUntil'])), 'history' => array_slice($offline->releaseHistory(), 0, 10)]);
    }
}
