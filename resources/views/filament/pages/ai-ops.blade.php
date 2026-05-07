<x-filament-panels::page>

    @php
        $spent     = $this->getSpendCents();
        $softCap   = $this->getSoftCapCents();
        $hardCap   = $this->getHardCapCents();
        $softPct   = $softCap > 0 ? min(100, round(($spent / $softCap) * 100)) : 0;
        $hardPct   = $hardCap > 0 ? min(100, round(($spent / $hardCap) * 100)) : 0;
        $state     = $this->getGuardState();
        $stateLabel = match ($state) {
            \App\Services\AI\GuardState::Proceed         => 'Operating normally',
            \App\Services\AI\GuardState::PauseAutonomous => 'Soft cap hit — autonomous paused',
            \App\Services\AI\GuardState::PauseAll        => 'PAUSED — all AI calls blocked',
        };
        $stateColour = match ($state) {
            \App\Services\AI\GuardState::Proceed         => 'green',
            \App\Services\AI\GuardState::PauseAutonomous => 'amber',
            \App\Services\AI\GuardState::PauseAll        => 'red',
        };
    @endphp

    {{-- ── State banner ────────────────────────────────────────────────── --}}
    <div class="rounded-xl border p-4 mb-6"
         style="border-color: {{ $stateColour === 'green' ? '#86efac' : ($stateColour === 'amber' ? '#fde68a' : '#fecaca') }};
                background-color: {{ $stateColour === 'green' ? '#f0fdf4' : ($stateColour === 'amber' ? '#fffbeb' : '#fef2f2') }};">
        <p class="text-sm font-medium" style="color: {{ $stateColour === 'green' ? '#16a34a' : ($stateColour === 'amber' ? '#b45309' : '#b91c1c') }};">
            Current state
        </p>
        <p class="text-xl font-bold mt-1" style="color: {{ $stateColour === 'green' ? '#15803d' : ($stateColour === 'amber' ? '#92400e' : '#991b1b') }};">
            {{ $stateLabel }}
        </p>
        @if ($this->isAutonomousPaused())
            <p class="text-sm mt-2 text-red-700">
                Manual kill switch is engaged. Use the "Resume" button above to lift it.
            </p>
        @endif
    </div>

    {{-- ── Spend cards ────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

        <div class="rounded-xl border p-4 bg-white dark:bg-gray-900 dark:border-gray-700">
            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Spent this month</p>
            <p class="text-3xl font-bold mt-1">${{ number_format($spent / 100, 2) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ now('Africa/Johannesburg')->format('F Y') }}</p>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950 dark:border-amber-800 p-4">
            <p class="text-sm font-medium text-amber-700 dark:text-amber-400">Soft cap</p>
            <p class="text-3xl font-bold text-amber-900 dark:text-amber-100 mt-1">
                ${{ number_format($softCap / 100, 0) }}
            </p>
            <div class="mt-2 h-2 bg-amber-100 dark:bg-amber-900 rounded">
                <div class="h-2 rounded {{ $softPct >= 100 ? 'bg-amber-700' : 'bg-amber-500' }}"
                     style="width: {{ $softPct }}%"></div>
            </div>
            <p class="text-xs text-amber-700 mt-1">{{ $softPct }}% used — autonomous pauses at 100%</p>
        </div>

        <div class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-950 dark:border-red-800 p-4">
            <p class="text-sm font-medium text-red-700 dark:text-red-400">Hard cap</p>
            <p class="text-3xl font-bold text-red-900 dark:text-red-100 mt-1">
                ${{ number_format($hardCap / 100, 0) }}
            </p>
            <div class="mt-2 h-2 bg-red-100 dark:bg-red-900 rounded">
                <div class="h-2 rounded {{ $hardPct >= 100 ? 'bg-red-700' : 'bg-red-500' }}"
                     style="width: {{ $hardPct }}%"></div>
            </div>
            <p class="text-xs text-red-700 mt-1">{{ $hardPct }}% used — all AI pauses at 100%</p>
        </div>
    </div>

    {{-- ── Activity ────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">

        <div class="rounded-xl border p-4 bg-white dark:bg-gray-900 dark:border-gray-700">
            <p class="text-xs font-medium text-gray-600 dark:text-gray-400 uppercase">Autonomous sent today</p>
            <p class="text-2xl font-bold mt-1">{{ $this->getAutonomousSendsToday() }}</p>
        </div>

        <div class="rounded-xl border border-red-200 p-4 bg-red-50 dark:bg-red-950 dark:border-red-800">
            <p class="text-xs font-medium text-red-700 uppercase">Blocked by compliance today</p>
            <p class="text-2xl font-bold text-red-900 dark:text-red-100 mt-1">{{ $this->getBlockedTodayCount() }}</p>
            <p class="text-xs text-red-700 mt-1">{{ $this->getBlockedThisMonthCount() }} this month</p>
        </div>

        <div class="rounded-xl border border-amber-200 p-4 bg-amber-50 dark:bg-amber-950 dark:border-amber-800">
            <p class="text-xs font-medium text-amber-700 uppercase">Pending review</p>
            <p class="text-2xl font-bold text-amber-900 dark:text-amber-100 mt-1">{{ $this->getPendingReviewCount() }}</p>
            <a href="{{ \App\Filament\Resources\AiDraftResource::getUrl('index') }}" class="text-xs text-amber-700 hover:underline">Open queue →</a>
        </div>

        <div class="rounded-xl border p-4 bg-white dark:bg-gray-900 dark:border-gray-700">
            <p class="text-xs font-medium text-gray-600 dark:text-gray-400 uppercase">Autonomous templates</p>
            <p class="text-2xl font-bold mt-1">{{ $this->getAutonomousTemplatesCount() }}</p>
            <a href="{{ \App\Filament\Resources\OutreachTemplateResource::getUrl('index') }}" class="text-xs text-gray-500 hover:underline">Manage →</a>
        </div>
    </div>

    {{-- ── Spend by model ─────────────────────────────────────────────── --}}
    <div class="rounded-xl border p-4 bg-white dark:bg-gray-900 dark:border-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Spend by model — month to date</h3>

        @php $rows = $this->getMonthSpendByModel(); @endphp

        @if (empty($rows))
            <p class="text-sm text-gray-500">No AI usage recorded this month.</p>
        @else
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 border-b dark:border-gray-700">
                    <tr>
                        <th class="text-left py-2">Model</th>
                        <th class="text-right py-2">Calls</th>
                        <th class="text-right py-2">Tokens (in)</th>
                        <th class="text-right py-2">Tokens (out)</th>
                        <th class="text-right py-2">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b dark:border-gray-800">
                            <td class="py-2 font-mono">{{ $row['model'] }}</td>
                            <td class="py-2 text-right">{{ number_format($row['calls']) }}</td>
                            <td class="py-2 text-right">{{ number_format($row['tin']) }}</td>
                            <td class="py-2 text-right">{{ number_format($row['tout']) }}</td>
                            <td class="py-2 text-right font-semibold">${{ number_format($row['cost'] / 100, 4) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</x-filament-panels::page>
