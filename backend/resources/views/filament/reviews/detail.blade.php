{{--
    One review in full, for ReviewResource's View action: who, about which
    service, the rating, the whole comment and reply, and — if hidden — why.
--}}
@php
    $service = $review->lead?->subcategory;
    $stars = str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating);
@endphp

<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Customer</dt>
            <dd class="font-medium text-gray-800 dark:text-gray-100">{{ $review->customer?->user?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Vendor</dt>
            <dd class="font-medium text-gray-800 dark:text-gray-100">{{ $review->vendor?->business_name ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Service</dt>
            <dd class="text-gray-800 dark:text-gray-100">
                {{ $service ? trim(($service->category?->name ?? '').' › '.$service->name, ' ›') : '—' }}
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Contacted via</dt>
            <dd class="text-gray-800 dark:text-gray-100">
                {{ $review->lead?->channel ? ucfirst($review->lead->channel) : '—' }}
                @if ($review->lead?->created_at)
                    <span class="text-gray-500 dark:text-gray-400">on {{ $review->lead->created_at->format('d M Y') }}</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Rating</dt>
            <dd class="text-base text-warning-500">{{ $stars }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Posted</dt>
            <dd class="text-gray-800 dark:text-gray-100">{{ $review->created_at?->format('d M Y, H:i') }}</dd>
        </div>
    </dl>

    <div>
        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Comment</p>
        <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-100">{{ $review->comment ?: 'No comment — rating only.' }}</p>
    </div>

    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Vendor reply
            @if ($review->replied_at)
                · {{ $review->replied_at->format('d M Y') }}
            @endif
        </p>
        <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-100">{{ $review->vendor_reply ?: 'The vendor has not replied.' }}</p>
    </div>

    @if ($review->is_hidden)
        <div class="rounded-lg bg-danger-500/10 p-3">
            <p class="text-xs font-medium uppercase tracking-wide text-danger-600 dark:text-danger-400">Hidden from customers</p>
            <p class="mt-1 text-gray-800 dark:text-gray-100">{{ $review->hidden_reason ?: 'No reason recorded.' }}</p>
        </div>
    @endif
</div>
