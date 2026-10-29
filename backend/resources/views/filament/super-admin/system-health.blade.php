<x-filament-panels::page>
    <x-filament::section>
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($this->getChecks() as $check)
                <li class="flex items-start gap-3 py-3">
                    <x-filament::badge
                        :color="match ($check['status']) { 'ok' => 'success', 'warn' => 'warning', default => 'danger' }"
                        :icon="match ($check['status']) { 'ok' => 'heroicon-m-check-circle', 'warn' => 'heroicon-m-exclamation-triangle', default => 'heroicon-m-x-circle' }"
                    >
                        {{ match ($check['status']) { 'ok' => 'OK', 'warn' => 'Check', default => 'Problem' } }}
                    </x-filament::badge>

                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ $check['label'] }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $check['detail'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>
