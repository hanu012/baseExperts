<x-filament-panels::page>
    <x-filament-widgets::widgets
        :widgets="$this->getHeaderWidgets()"
        :columns="1"
    />

    {{ $this->table }}
</x-filament-panels::page>
