<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Open your authenticator app and enter the 6-digit code for <strong>{{ auth()->user()->email }}</strong>.
            Lost your phone? Enter one of your recovery codes instead.
        </p>

        <form wire:submit="verify" class="mt-4">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit">Continue</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
