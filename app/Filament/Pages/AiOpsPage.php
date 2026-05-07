<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AiComplianceCheck;
use App\Models\AiDraft;
use App\Models\AiUsageLog;
use App\Models\OutreachTemplate;
use App\Services\AI\CostCeilingGuard;
use App\Services\AI\GuardState;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * AI Operations dashboard. Super-admin only.
 *
 * Surfaces:
 *   - month-to-date AI spend vs soft/hard caps (progress bars)
 *   - autonomous send count + blocked-by-compliance count today
 *   - per-template autonomous toggle status
 *   - manual kill switch (one button: Pause autonomous sends)
 *
 * Read-only by default. The kill switch is the only state-changing
 * action on this page.
 */
class AiOpsPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-cpu-chip';
    protected static ?string $navigationLabel = 'AI Ops';
    protected static ?string $navigationGroup = 'AI Outreach';
    protected static ?int    $navigationSort  = 30;
    protected static ?string $title           = 'AI Operations';
    protected static string  $view            = 'filament.pages.ai-ops';

    public static function getSlug(): string
    {
        return 'ai-ops';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_super_admin === true;
    }

    public function getSpendCents(): int
    {
        return app(CostCeilingGuard::class)->currentMonthSpendCents();
    }

    public function getSoftCapCents(): int
    {
        return (int) (config('ai.cost_caps.soft_usd', 300) * 100);
    }

    public function getHardCapCents(): int
    {
        return (int) (config('ai.cost_caps.hard_usd', 500) * 100);
    }

    public function getGuardState(): GuardState
    {
        return app(CostCeilingGuard::class)->check();
    }

    public function getAutonomousSendsToday(): int
    {
        return AiDraft::where('mode', AiDraft::MODE_AUTONOMOUS)
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    public function getBlockedTodayCount(): int
    {
        return AiDraft::where('status', AiDraft::STATUS_BLOCKED_COMPLIANCE)
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    public function getBlockedThisMonthCount(): int
    {
        $start = CarbonImmutable::now('Africa/Johannesburg')->startOfMonth();
        return AiDraft::where('status', AiDraft::STATUS_BLOCKED_COMPLIANCE)
            ->where('created_at', '>=', $start)
            ->count();
    }

    public function getPendingReviewCount(): int
    {
        return AiDraft::where('status', AiDraft::STATUS_PENDING_REVIEW)->count();
    }

    public function getAutonomousTemplatesCount(): int
    {
        return OutreachTemplate::where('autonomous_enabled', true)->count();
    }

    public function getMonthSpendByModel(): array
    {
        $start = CarbonImmutable::now('Africa/Johannesburg')->startOfMonth()->toDateString();
        return AiUsageLog::where('date', '>=', $start)
            ->selectRaw('model, sum(call_count) as calls, sum(tokens_input) as tin, sum(tokens_output) as tout, sum(cost_cents) as cost')
            ->groupBy('model')
            ->orderByDesc('cost')
            ->get()
            ->toArray();
    }

    public function isAutonomousPaused(): bool
    {
        return app(CostCeilingGuard::class)->isManuallyPaused();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggle_autonomous')
                ->label(fn () => $this->isAutonomousPaused() ? 'Resume autonomous sends' : 'Pause autonomous sends')
                ->icon(fn () => $this->isAutonomousPaused() ? 'heroicon-o-play' : 'heroicon-o-pause')
                ->color(fn () => $this->isAutonomousPaused() ? 'success' : 'danger')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->isAutonomousPaused()
                    ? 'Resume autonomous AI sends?'
                    : 'Pause ALL autonomous AI sends?')
                ->modalDescription(fn () => $this->isAutonomousPaused()
                    ? 'Reviewed drafts continue working either way. Autonomous triggers will resume firing immediately.'
                    : 'Reviewed drafts continue working. Autonomous triggers (event-driven sends) will not fire until resumed.')
                ->action(function (): void {
                    $guard = app(CostCeilingGuard::class);
                    if ($guard->isManuallyPaused()) {
                        $guard->resumeAutonomous();
                        Notification::make()->title('Autonomous sends resumed')->success()->send();
                    } else {
                        $guard->pauseAutonomous();
                        Notification::make()->title('Autonomous sends paused')->warning()->send();
                    }
                }),
        ];
    }
}
