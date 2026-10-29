<x-filament-panels::page>
    @if ($recoveryCodes !== [])
        <x-filament::section>
            <x-slot name="heading">Two-step login is on — save your recovery codes</x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-300">
                If you lose your phone, each of these codes lets you in once instead of an app code.
                Keep them somewhere safe (a password manager, or printed). They are not shown again.
            </p>

            <ul class="mt-4 font-mono text-sm" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: 0.5rem;">
                @foreach ($recoveryCodes as $code)
                    <li class="rounded-lg bg-gray-100 px-3 py-2 text-center dark:bg-white/5">{{ $code }}</li>
                @endforeach
            </ul>

            <div class="mt-6">
                <x-filament::button wire:click="finish">I have saved them — continue</x-filament::button>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">1. Add this account to your authenticator app</x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-300">
                The Super Admin panel needs a code from an authenticator app on your phone (Google Authenticator,
                Microsoft Authenticator, Authy…) every time you sign in. In the app choose
                <strong>Add account → Enter a setup key</strong>, then:
            </p>

            <dl class="mt-4 text-sm" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0.75rem;">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Account name</dt>
                    <dd class="font-medium">{{ auth()->user()->email }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Key</dt>
                    <dd class="select-all font-mono font-medium tracking-wider">{{ $this->formattedSecret() }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Type of key</dt>
                    <dd class="font-medium">Time based</dd>
                </div>
            </dl>

            <p class="mt-4 text-sm">
                <a href="{{ $this->otpauthUri() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                    Opening this on your phone? Tap here to add it straight to the app.
                </a>
            </p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">2. Enter the code the app shows</x-slot>

            <form wire:submit="confirm">
                {{ $this->form }}

                <div class="mt-4">
                    <x-filament::button type="submit">Turn on two-step login</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
