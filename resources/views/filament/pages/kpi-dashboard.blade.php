<x-filament-panels::page>
    {{-- Period selector --}}
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

    @if ($activeTab === 'overview')
        {{-- ── Zone 1: Headline numbers ───────────────────────────────── --}}
        @livewire(\App\Filament\Widgets\Kpi\MoneyFlowStatsWidget::class, ['filters' => $this->filters], key('mf-stats'))
        @livewire(\App\Filament\Widgets\Kpi\LeadConversionStatsWidget::class, ['filters' => $this->filters], key('lc-stats'))

        {{-- ── Zone 2: Money flow trend cards (2-up on md+) ───────────── --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @livewire(\App\Filament\Widgets\Kpi\DepositsTrendCard::class, ['filters' => $this->filters], key('dep-trend'))
            @livewire(\App\Filament\Widgets\Kpi\WithdrawalsTrendCard::class, ['filters' => $this->filters], key('wit-trend'))
            @livewire(\App\Filament\Widgets\Kpi\NettTrendCard::class, ['filters' => $this->filters], key('net-trend'))
            @livewire(\App\Filament\Widgets\Kpi\ChallengeSalesTrendCard::class, ['filters' => $this->filters], key('chg-trend'))
        </div>

        {{-- ── Zone 3: Per-branch grid ────────────────────────────────── --}}
        @livewire(\App\Filament\Widgets\Kpi\BranchHealthGridWidget::class, ['filters' => $this->filters], key('branch-grid'))
    @else
        <div class="space-y-6">
            @foreach ($this->getSalesWidgets() as $widgetClass)
                @livewire($widgetClass, ['filters' => $this->filters], key($widgetClass))
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
