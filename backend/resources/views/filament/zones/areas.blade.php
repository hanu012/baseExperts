{{--
    "Zone Areas" tab: a zone's sub-zones.

    Areas are zones with this one as parent, added and edited on the
    Sub-zones screen ("Add area" prefills the parent).
    The hierarchy is two levels deep (SPEC section 8), so a sub-zone shows
    a note instead of a list.
--}}
@php
    $zone = $getRecord();
    $areas = $zone?->children()->orderBy('name')->get() ?? collect();
@endphp

@if ($zone === null)
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Save the zone first to add areas.
    </p>
@elseif ($zone->parent_id !== null)
    <p class="text-sm text-gray-500 dark:text-gray-400">
        This zone is itself an area of
        <a
            href="{{ \App\Filament\Resources\ZoneResource::getUrl('edit', ['record' => $zone->parent_id]) }}"
            class="font-medium text-primary-600 hover:underline dark:text-primary-400"
        >{{ $zone->parent?->name }}</a>.
        Zones go two levels deep (city → area), so it cannot have areas of its own.
    </p>
@else
    <div class="space-y-3">
        <div class="flex items-center justify-between gap-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($areas->isEmpty())
                    No areas yet — customers are matched to this zone as a whole.
                @else
                    Customers are matched to these areas, not to {{ $zone->name }} itself.
                @endif
            </p>

            <x-filament::button
                tag="a"
                size="sm"
                icon="heroicon-m-plus"
                :href="\App\Filament\Resources\SubZoneResource::getUrl('create', ['parent_id' => $zone->getKey()])"
            >
                Add area
            </x-filament::button>
        </div>

        @if ($areas->isNotEmpty())
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-2 font-medium">Area</th>
                            <th class="px-3 py-2 font-medium">Code</th>
                            <th class="px-3 py-2 font-medium">Shape</th>
                            <th class="px-3 py-2 font-medium">Pincode</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($areas as $area)
                            <tr class="text-gray-700 dark:text-gray-200">
                                <td class="px-3 py-2 font-medium">{{ $area->name }}</td>
                                <td class="px-3 py-2">{{ $area->code ?? '—' }}</td>
                                <td class="px-3 py-2">{{ \App\Filament\Resources\ZoneResource::shapeLabel($area) }}</td>
                                <td class="px-3 py-2">{{ $area->pincode ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <span @class([
                                        'rounded px-1.5 py-0.5 text-xs',
                                        'bg-success-500/10 text-success-700 dark:text-success-400' => $area->is_active,
                                        'bg-gray-500/10 text-gray-600 dark:text-gray-400' => ! $area->is_active,
                                    ])>
                                        {{ $area->is_active ? 'Active' : 'Draft' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <a
                                        href="{{ \App\Filament\Resources\SubZoneResource::getUrl('edit', ['record' => $area]) }}"
                                        class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    >
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
