<x-filament-panels::page>

    {{-- ── Stats header ────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">

        <div class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-950 dark:border-red-800 p-4">
            <p class="text-sm font-medium text-red-600 dark:text-red-400">Overdue</p>
            <p class="text-3xl font-bold text-red-700 dark:text-red-300 mt-1">
                {{ $this->getOverdueCount() }}
            </p>
        </div>

        <div class="rounded-xl border border-yellow-200 bg-yellow-50 dark:bg-yellow-950 dark:border-yellow-800 p-4">
            <p class="text-sm font-medium text-yellow-600 dark:text-yellow-400">Due Today</p>
            <p class="text-3xl font-bold text-yellow-700 dark:text-yellow-300 mt-1">
                {{ $this->getDueTodayCount() }}
            </p>
        </div>

        <div class="rounded-xl border border-blue-200 bg-blue-50 dark:bg-blue-950 dark:border-blue-800 p-4">
            <p class="text-sm font-medium text-blue-600 dark:text-blue-400">Due This Week</p>
            <p class="text-3xl font-bold text-blue-700 dark:text-blue-300 mt-1">
                {{ $this->getDueThisWeekCount() }}
            </p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700 p-4">
            <p class="text-sm font-medium text-gray-500">Total Pending</p>
            <p class="text-3xl font-bold text-gray-700 dark:text-gray-200 mt-1">
                {{ $this->getPendingCount() }}
            </p>
        </div>

    </div>

    {{-- ── View mode toggle ────────────────────────────────────────────────── --}}
    <div class="flex gap-2 mb-4">
        <button
            wire:click="setPendingMode"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                {{ $viewMode === 'pending'
                    ? 'bg-primary-600 text-white'
                    : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50' }}">
            Pending
        </button>
        <button
            wire:click="setCompletedMode"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                {{ $viewMode === 'completed'
                    ? 'bg-primary-600 text-white'
                    : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50' }}">
            Completed
        </button>
        <button
            wire:click="setAllMode"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                {{ $viewMode === 'all'
                    ? 'bg-primary-600 text-white'
                    : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50' }}">
            All Tasks
        </button>
    </div>

    {{-- ── Task table ──────────────────────────────────────────────────────── --}}
    {{ $this->table }}

</x-filament-panels::page>
