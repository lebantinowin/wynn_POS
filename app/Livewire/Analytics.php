<?php

namespace App\Livewire;

use App\Support\ReportExports;
use App\Support\SessionAnalytics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Analytics extends Component
{
    public int $days = 7;

    public string $reportType = 'summary';

    #[Locked]
    public string $selectedDay = '';

    #[Locked]
    public bool $calendar = false;

    #[Locked]
    public string $month = '';

    public int $calendarYear = 2026;

    public int $calendarMonthNumber = 1;

    public function mount(bool $calendar = false): void
    {
        $this->calendar = $calendar;
        $this->month = now('Asia/Shanghai')->format('Y-m');
        $this->selectedDay = $calendar ? now('Asia/Shanghai')->toDateString() : '';
        $this->syncCalendarChoices();
    }

    private function syncCalendarChoices(): void
    {
        [$year, $month] = explode('-', $this->month);
        $this->calendarYear = (int) $year;
        $this->calendarMonthNumber = (int) $month;
    }

    private function selectCalendarMonth(): void
    {
        $this->resetErrorBag();
        try {
            Validator::make(['calendarYear' => $this->calendarYear, 'calendarMonthNumber' => $this->calendarMonthNumber], [
                'calendarYear' => 'required|integer|between:1900,'.now('Asia/Shanghai')->year,
                'calendarMonthNumber' => 'required|integer|between:1,12',
            ])->validate();
            $month = sprintf('%04d-%02d', $this->calendarYear, $this->calendarMonthNumber);
            SessionAnalytics::calendarMonth($month);
            $this->month = $month;
            $this->clearDay();
        } catch (ValidationException $exception) {
            $this->syncCalendarChoices();
            throw $exception;
        }
    }

    public function updatedCalendarMonthNumber(): void
    {
        $this->selectCalendarMonth();
    }

    public function updatedCalendarYear(): void
    {
        if ($this->calendarYear === now('Asia/Shanghai')->year && $this->calendarMonthNumber > now('Asia/Shanghai')->month) {
            $this->calendarMonthNumber = now('Asia/Shanghai')->month;
        }
        $this->selectCalendarMonth();
    }

    public function moveMonth(int $direction): void
    {
        if (! in_array($direction, [-1, 1], true)) {
            throw ValidationException::withMessages(['month' => 'Choose the previous or next month.']);
        }
        $month = Carbon::parse($this->month.'-01')->addMonths($direction)->format('Y-m');
        SessionAnalytics::calendarMonth($month);
        $this->month = $month;
        $this->syncCalendarChoices();
        $this->clearDay();
    }

    public function currentMonth(): void
    {
        $this->month = now('Asia/Shanghai')->format('Y-m');
        $this->selectedDay = now('Asia/Shanghai')->toDateString();
        $this->syncCalendarChoices();
        $this->resetErrorBag();
    }

    public function viewDay(string $date): void
    {
        $this->resetErrorBag('selectedDay');
        if ($this->calendar && ! str_starts_with($date, $this->month.'-')) {
            throw ValidationException::withMessages(['selectedDay' => 'Choose a day in the displayed month.']);
        }
        SessionAnalytics::dailyActivity($date, $this->calendar ? null : $this->days);
        $this->selectedDay = $date;
        if ($this->calendar) {
            $this->dispatch('calendar-day-selected');
        }
    }

    public function clearDay(): void
    {
        $this->selectedDay = '';
        $this->resetErrorBag('selectedDay');
    }

    public function updatedDays(): void
    {
        $this->clearDay();
    }

    public function render(): View
    {
        if ($this->calendar) {
            $month = SessionAnalytics::calendarMonth($this->month);

            return view('livewire.dashboard-calendar', ['calendarMonth' => $month, 'overall' => SessionAnalytics::summarizeAll(),
                'daySummary' => $this->selectedDay !== '' ? SessionAnalytics::summarizeDay($this->selectedDay) : null,
                'dayActivity' => $this->selectedDay !== '' ? SessionAnalytics::dailyActivity($this->selectedDay) : null]);
        }
        $summary = SessionAnalytics::summarize($this->days);
        if (! in_array($this->selectedDay, array_column($summary['daily'], 'date'), true)) {
            $this->selectedDay = '';
        }

        return view('livewire.analytics', ['summary' => $summary, 'reportTypes' => ReportExports::TYPES,
            'dayActivity' => $this->selectedDay !== '' ? SessionAnalytics::dailyActivity($this->selectedDay, $this->days) : null]);
    }
}
