{{--
    The vendors behind a zone's "In use" badge.

    One row per subscription selection, not per vendor: a vendor on a
    multi-zone plan appears once for each zone of this subtree they
    bought, which is what makes the rows add up to the badge.
--}}
@php
    $rows = $zone->subscribingVendors();
@endphp

@if ($rows->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Nothing subscribes to this zone yet.
    </p>
@else
    <div class="space-y-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            @if ($zone->isLeaf())
                {{ trans_choice(':count subscription selection|:count subscription selections', $rows->count(), ['count' => $rows->count()]) }}
                cover this zone.
            @else
                {{ trans_choice(':count subscription selection|:count subscription selections', $rows->count(), ['count' => $rows->count()]) }}
                cover this zone or one beneath it. Deactivating this parent
                removes all of them from matching.
            @endif
        </p>

        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2 font-medium">Vendor</th>
                        <th class="px-3 py-2 font-medium">Zone</th>
                        <th class="px-3 py-2 font-medium">Plan</th>
                        <th class="px-3 py-2 font-medium">Subscription</th>
                        <th class="px-3 py-2 font-medium">Expires</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($rows as $row)
                        <tr class="text-gray-700 dark:text-gray-200">
                            <td class="px-3 py-2">
                                {{--
                                    Links to the owning user, not the vendor:
                                    vendor management was folded into
                                    UserResource, and VendorResource has no
                                    edit page to link to. A vendor row with
                                    no user is not linked rather than linked
                                    somewhere broken.
                                --}}
                                @if ($row->user_id)
                                    <a
                                        href="{{ \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $row->user_id]) }}"
                                        class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    >
                                        {{ $row->business_name }}
                                    </a>
                                @else
                                    <span class="font-medium">{{ $row->business_name }}</span>
                                @endif

                                @if ($row->vendor_status !== 'active')
                                    <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">
                                        ({{ str_replace('_', ' ', $row->vendor_status) }})
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $row->zone_name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $row->plan_name ?? '—' }}</td>
                            <td class="px-3 py-2">
                                {{-- Expired and grace rows still count against the
                                     zone, so the status is shown rather than
                                     assumed to be active. --}}
                                <span @class([
                                    'rounded px-1.5 py-0.5 text-xs',
                                    'bg-success-500/10 text-success-700 dark:text-success-400' => $row->subscription_status === 'active',
                                    'bg-warning-500/10 text-warning-700 dark:text-warning-400' => $row->subscription_status === 'grace',
                                    'bg-gray-500/10 text-gray-600 dark:text-gray-400' => ! in_array($row->subscription_status, ['active', 'grace'], true),
                                ])>
                                    {{ $row->subscription_status }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                {{ $row->end_date ? \Illuminate\Support\Carbon::parse($row->end_date)->format('d M Y') : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
