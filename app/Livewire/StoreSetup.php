<?php

namespace App\Livewire;

use App\Support\PosStore;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class StoreSetup extends Component
{
    public function importCurrent(): void
    {
        PosStore::import(session('demo', []), session()->getId());
        $this->redirect('/dashboard', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.store-setup', ['ready' => PosStore::ready(), 'legacyCounts' => array_map('count', array_filter(session('demo', []), 'is_array'))]);
    }
}
