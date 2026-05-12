@php
    $data = $this->getViewData();
    $period = $data['period'];
    $rows = $data['rows'];
    $isManager = $data['isManager'];
    $sortColumn = $data['sortColumn'];
    $sortDir = $data['sortDir'];
    $fmtMoney = fn (int $cents) => ($cents < 0 ? '-' : '') . '$' . number_format(abs($cents) / 100, 2);
    $fmtPct   = fn (float $r) => number_format($r * 100, 2) . '%';
    $sortIndicator = fn (string $col) => $sortColumn === $col
        ? ($sortDir === 'desc' ? ' ▼' : ' ▲')
        : '';
@endphp

<x-filament::section>
    <x-slot name="heading">
        Hunter / Farmer Leaderboard
    </x-slot>
    <x-slot name="description">
        {{ $period->label() }}
        @if (! $isManager && isset($data['rank']))
            · Your rank: {{ $data['rank'] }} of {{ $data['totalAgents'] }}
        @endif
    </x-slot>

    @if ($rows->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400 py-4">No agents with assigned clients in this period.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        @if ($isManager)
                            <th class="px-3 py-2 font-semibold">#</th>
                            <th class="px-3 py-2 font-semibold">Agent</th>
                        @else
                            <th class="px-3 py-2 font-semibold">You</th>
                            <th class="px-3 py-2 font-semibold text-gray-400">Company avg</th>
                        @endif
                        <th class="px-3 py-2 font-semibold cursor-pointer select-none"
                            @if ($isManager) wire:click="sort('conversion_rate')" @endif>
                            Lead → Client{{ $isManager ? $sortIndicator('conversion_rate') : '' }}
                        </th>
                        <th class="px-3 py-2 font-semibold cursor-pointer select-none"
                            @if ($isManager) wire:click="sort('challenge_sales_cents')" @endif>
                            Challenge Sales{{ $isManager ? $sortIndicator('challenge_sales_cents') : '' }}
                        </th>
                        <th class="px-3 py-2 font-semibold cursor-pointer select-none"
                            @if ($isManager) wire:click="sort('deposits_cents')" @endif>
                            Total Deposits{{ $isManager ? $sortIndicator('deposits_cents') : '' }}
                        </th>
                        <th class="px-3 py-2 font-semibold cursor-pointer select-none"
                            @if ($isManager) wire:click="sort('nett_deposits_cents')" @endif>
                            NETT Deposits{{ $isManager ? $sortIndicator('nett_deposits_cents') : '' }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @if ($isManager)
                        @foreach ($rows as $i => $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="px-3 py-2 text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-3 py-2 font-medium">{{ $row->user_name }}</td>
                                <td class="px-3 py-2">{{ $fmtPct($row->conversion_rate) }}</td>
                                <td class="px-3 py-2">{{ $fmtMoney($row->challenge_sales_cents) }}</td>
                                <td class="px-3 py-2">{{ $fmtMoney($row->deposits_cents) }}</td>
                                <td class="px-3 py-2 @if ($row->nett_deposits_cents < 0) text-danger-600 @endif">
                                    {{ $fmtMoney($row->nett_deposits_cents) }}
                                </td>
                            </tr>
                        @endforeach
                    @else
                        @php $you = $rows->first(); $avg = $data['companyAverages']; @endphp
                        @if ($you)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-2 font-medium">You</td>
                                <td class="px-3 py-2 text-gray-400">avg of {{ $avg->agent_count }} agents</td>
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{ $fmtPct($you->conversion_rate) }}</span>
                                    <span class="text-gray-400 text-xs ml-2">vs {{ $fmtPct($avg->conversion_rate) }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{ $fmtMoney($you->challenge_sales_cents) }}</span>
                                    <span class="text-gray-400 text-xs ml-2">vs {{ $fmtMoney($avg->challenge_sales_cents) }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{ $fmtMoney($you->deposits_cents) }}</span>
                                    <span class="text-gray-400 text-xs ml-2">vs {{ $fmtMoney($avg->deposits_cents) }}</span>
                                </td>
                                <td class="px-3 py-2 @if ($you->nett_deposits_cents < 0) text-danger-600 @endif">
                                    <span class="font-medium">{{ $fmtMoney($you->nett_deposits_cents) }}</span>
                                    <span class="text-gray-400 text-xs ml-2">vs {{ $fmtMoney($avg->nett_deposits_cents) }}</span>
                                </td>
                            </tr>
                        @else
                            <tr><td colspan="6" class="px-3 py-4 text-gray-500">You have no assigned clients in this period.</td></tr>
                        @endif
                    @endif
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
