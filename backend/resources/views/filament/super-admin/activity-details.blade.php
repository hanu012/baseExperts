{{-- Before / after of one audit_logs row (ActivityLogResource "Details"). --}}
@php
    $old = $log->old_values ?? [];
    $new = $log->new_values ?? [];
    $keys = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
    $show = fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '—'));
@endphp

<div class="space-y-4 text-sm">
    <p class="text-gray-600 dark:text-gray-300">
        {{ $log->user?->name ?? 'System' }}
        @if ($log->user) ({{ $log->user->email }}) @endif
        · {{ $log->created_at?->format('d M Y, H:i:s') }}
        @if ($log->ip_address) · {{ $log->ip_address }} @endif
    </p>

    @if ($keys === [])
        <p class="text-gray-500 dark:text-gray-400">No field values were recorded for this entry.</p>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">Field</th>
                        <th class="px-3 py-2">Before</th>
                        <th class="px-3 py-2">After</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($keys as $key)
                        <tr>
                            <td class="px-3 py-2 font-medium">{{ $key }}</td>
                            <td class="px-3 py-2 break-all text-gray-600 dark:text-gray-300">{{ array_key_exists($key, $old) ? $show($old[$key]) : '—' }}</td>
                            <td class="px-3 py-2 break-all">{{ array_key_exists($key, $new) ? $show($new[$key]) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
