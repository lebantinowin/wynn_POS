<?php

namespace App\Livewire;

use App\Support\ShopPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ShopSettings extends Component
{
    public array $settings = [];

    public string $message = '';

    public function mount(): void
    {
        $this->settings = app(ShopPreferences::class)->all();
    }

    public function save(): void
    {
        $this->message = '';
        $this->resetErrorBag();
        try {
            app(ShopPreferences::class)->save($this->settings);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field === 'settings' || $field === 'offline' ? $field : 'settings.'.$field, $messages[0]);
            }

            return;
        }
        $this->settings = app(ShopPreferences::class)->all();
        $this->message = 'Shop details saved. New sales use these details; existing receipt copies are preserved.';
    }

    public function render(): View
    {
        return view('livewire.shop-settings', ['profile' => app(ShopPreferences::class)->receiptProfile()]);
    }
}
