<x-filament-panels::page>
    {{-- Period selector (driven by HasFiltersForm on the page) --}}
    {{ $this->filtersForm }}

    {{-- Tab strip --}}
    <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700">
        <button
            type="button"
            wire:click="setActiveTab('overview')"
            @class([
                'px-4 py-2 -mb-px font-medium text-sm border-b-2 transition',
                'border-primary-600 text-primary-600 dark:text-primary-400' => $activeTab === 'overview',
                'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $activeTab !== 'overview',
            ])
        >
            Management Overview
        </button>
        <button
            type="button"
            wire:click="setActiveTab('sales')"
            @class([
                'px-4 py-2 -mb-px font-medium text-sm border-b-2 transition',
                'border-primary-600 text-primary-600 dark:text-primary-400' => $activeTab === 'sales',
                'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $activeTab !== 'sales',
            ])
        >
            Sales Performance
        </button>
    </div>

    {{-- Tab content --}}
    @if ($activeTab === 'overview')
        <div class="space-y-6">
            @foreach ($this->getOverviewWidgets() as $widgetClass)
                @livewire($widgetClass, ['filters' => $this->filters], key($widgetClass))
            @endforeach
        </div>
    @else
        <div class="space-y-6">
            @foreach ($this->getSalesWidgets() as $widgetClass)
                @livewire($widgetClass, ['filters' => $this->filters], key($widgetClass))
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
