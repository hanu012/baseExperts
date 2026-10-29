{{--
    One logged API error in full, for ApiErrorLogResource's Details action:
    the request, who made it, the error, the exception and trace, and the
    request data (secrets already blanked by LogApiErrors::redact()).
--}}
@php
    $payload = $log->request_payload
        ? json_encode($log->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;
@endphp

<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-4">
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">When</dt>
            <dd class="text-gray-800 dark:text-gray-100">{{ $log->created_at->format('d M Y, H:i:s') }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</dt>
            <dd class="font-semibold {{ $log->status >= 500 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-800 dark:text-gray-100' }}">{{ $log->status }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Took</dt>
            <dd class="text-gray-800 dark:text-gray-100">{{ $log->duration_ms !== null ? $log->duration_ms.' ms' : '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Error code</dt>
            <dd class="text-gray-800 dark:text-gray-100">{{ $log->error_code ?? '—' }}</dd>
        </div>
        <div class="col-span-2">
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">User</dt>
            <dd class="text-gray-800 dark:text-gray-100">
                {{ $log->user ? $log->user->name.' · '.$log->user->email.' ('.$log->user->role?->value.')' : 'Not signed in' }}
            </dd>
        </div>
        <div class="col-span-2">
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">IP · device</dt>
            <dd class="break-all text-gray-800 dark:text-gray-100">{{ $log->ip_address ?? '—' }} · {{ $log->user_agent ?? '—' }}</dd>
        </div>
    </dl>

    <div>
        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Message</p>
        <p class="mt-1 whitespace-pre-line break-words text-gray-800 dark:text-gray-100">{{ $log->message ?? '—' }}</p>
    </div>

    @if ($log->exception_class)
        <div class="rounded-lg bg-danger-500/10 p-3">
            <p class="text-xs font-medium uppercase tracking-wide text-danger-600 dark:text-danger-400">Exception</p>
            <p class="mt-1 font-mono text-xs text-gray-800 dark:text-gray-100">{{ $log->exception_class }}</p>
            <p class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $log->exception_location }}</p>
        </div>
    @endif

    @if ($payload)
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Request data (secrets hidden)</p>
            <pre class="mt-1 max-h-64 overflow-auto rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-100">{{ $payload }}</pre>
        </div>
    @endif

    @if ($log->trace)
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Stack trace</p>
            <pre class="mt-1 max-h-72 overflow-auto rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-100">{{ $log->trace }}</pre>
        </div>
    @endif
</div>
