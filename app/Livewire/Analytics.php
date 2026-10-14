<?php

namespace App\Livewire;

use App\Support\ReportExports;
use App\Support\SessionAnalytics;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Analytics extends Component
{
    public int $days = 7;

    public string $reportType = 'summary';

    public function render(): View
    {
        return view('livewire.analytics', ['summary' => SessionAnalytics::summarize($this->days), 'reportTypes' => ReportExports::TYPES]);
    }
}
