{{--
    Compact summary of a plan's quota, shown in place of six numeric columns.

    A plan is read as a package — "5 categories, 3 zones, 20 photos" — so the
    numbers are more legible grouped than spread across the table.
--}}
@php
    $quota = $getRecord()->quota;
@endphp

<div class="px-3 py-2">
    @if ($quota === null)
        <span class="text-sm text-danger-600 dark:text-danger-400">
            No quota set — this plan cannot be subscribed to.
        </span>
    @else
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
            @foreach ($quota->limits() as $label => $value)
                <span class="whitespace-nowrap text-sm">
                    <span class="font-semibold text-gray-950 dark:text-white">{{ $value }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                </span>
            @endforeach

            <span
                @class([
                    'whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-medium',
                    'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400' => $quota->priority_rank > 0,
                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $quota->priority_rank === 0,
                ])
                title="Higher tiers rank first in customer search, before rating and recency."
            >
                Priority {{ $quota->priority_rank }}
            </span>
        </div>
    @endif
</div>
