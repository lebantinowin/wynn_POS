<?php

namespace App\Livewire;

use App\Support\DatabaseBackups;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;

class Backups extends Component
{
    public bool $enabled = true;

    public int $intervalHours = 24;

    public int $retentionDays = 14;

    public string $message = '';

    public function mount(): void
    {
        $settings = app(DatabaseBackups::class)->settings();
        $this->enabled = $settings['enabled'];
        $this->intervalHours = $settings['intervalHours'];
        $this->retentionDays = $settings['retentionDays'];
    }

    public function saveSettings(): void
    {
        $this->validate(['enabled' => ['boolean'], 'intervalHours' => ['required', 'integer', 'in:1,6,24'], 'retentionDays' => ['required', 'integer', 'in:7,14,30,90']]);
        $this->perform(function (): void {
            app(DatabaseBackups::class)->saveSettings($this->enabled, $this->intervalHours, $this->retentionDays);
            $this->message = 'Backup preferences saved.';
        });
    }

    public function backupNow(): void
    {
        $this->perform(function (): void {
            $backup = app(DatabaseBackups::class)->create();
            $this->message = isset($backup['retentionWarning']) ? $backup['retentionWarning'] : 'Backup saved successfully.';
        });
    }

    public function verify(string $id): void
    {
        $this->perform(function () use ($id): void {
            app(DatabaseBackups::class)->verify($id);
            $this->message = 'Recovery check passed. The saved records were restored and matched in a separate temporary database.';
        });
    }

    private function perform(callable $action): void
    {
        $this->resetErrorBag();
        $this->message = '';
        try {
            $action();
        } catch (RuntimeException $exception) {
            $this->addError('backup', $exception->getMessage());
        }
    }

    public function render(): View
    {
        $backups = app(DatabaseBackups::class);

        return view('livewire.backups', ['history' => array_slice($backups->history(), 0, 30),
            'latest' => collect($backups->history())->firstWhere('status', 'Succeeded'), 'scheduler' => $backups->scheduler(), 'available' => $backups->available()]);
    }
}
