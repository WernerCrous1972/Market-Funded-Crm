@php
    $data = $this->getViewData();
    $rows = $data['rows'];
    $period = $data['period'];
    $fmtMoney = fn (int $cents) => ($cents < 0 ? '-' : '') . '$' . number_format(abs($cents) / 100, 0);
@endphp

<x-filament::section>
    <x-slot name="heading">Per-Branch Activity</x-slot>
    <x-slot name="description">{{ $period->label() }} · {{ $rows->count() }} branches with data</x-slot>

    @if ($rows->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400 py-4">No branches have activity in this period.</p>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
            @foreach ($rows as $b)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-3 shadow-sm">
                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate mb-2" title="{{ $b->branch_name }}">
                        {{ $b->branch_name }}
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">New Leads</div>
                            <div class="text-base font-medium">{{ number_format($b->new_leads) }}</div>
                        </div>
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">New Clients</div>
                            <div class="text-base font-medium">{{ number_format($b->new_clients) }}</div>
                        </div>
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">Challenge Sales</div>
                            <div class="text-base font-medium">{{ $fmtMoney($b->challenge_sales_cents) }}</div>
                        </div>
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">NETT</div>
                            <div @class([
                                'text-base font-medium',
                                'text-danger-600' => $b->nett_cents < 0,
                                'text-success-600' => $b->nett_cents > 0,
                            ])>
                                {{ $fmtMoney($b->nett_cents) }}
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament::section>
