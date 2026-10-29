<?php

namespace App\Filament\Widgets;

use App\Models\Lead;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;

/**
 * Leads Analytics page (SPEC section 5 item 11) — a day-by-day count
 * over a selectable window. Uses ChartWidget's own built-in filter
 * dropdown (top-right, 7/30/90 days) rather than the page table's
 * richer vendor/category/zone/date-range filters: wiring those two
 * together would need custom Livewire plumbing SPEC doesn't ask for,
 * and the two serve different questions (the table finds specific
 * leads, this chart shows overall volume trend).
 */
class LeadsOverTimeChart extends ChartWidget
{
    protected static ?string $heading = 'Leads over time';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);
        $start = now()->subDays($days - 1)->startOfDay();

        $countsByDate = Lead::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', $start)
            ->groupBy('date')
            ->pluck('count', 'date');

        // Every day in the window gets a point, even one with zero
        // leads — a day missing from the query result would otherwise
        // silently vanish from the line rather than reading as zero.
        $period = CarbonPeriod::create($start, now());

        $labels = [];
        $values = [];

        foreach ($period as $date) {
            $key = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            $values[] = (int) ($countsByDate[$key] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Leads',
                    'data' => $values,
                    'fill' => false,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
