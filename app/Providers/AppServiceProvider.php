<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\LargeWithdrawalReceived;
use App\Events\LeadConverted;
use App\Events\LeadCreated;
use App\Events\WhatsApp\WhatsAppMessageReceived;
use App\Listeners\AI\OnDepositFirst;
use App\Listeners\AI\OnLargeWithdrawal;
use App\Listeners\AI\OnLeadCreated;
use App\Listeners\WhatsApp\RouteToAgentListener;
use App\Models\Person;
use App\Models\User;
use App\Observers\PersonObserver;
use App\Observers\UserPermissionObserver;
use App\Policies\PersonPolicy;
use App\Services\AI\CostCeilingGuard;
use App\Services\AI\ModelRouter;
use App\Services\Notifications\TelegramNotifier;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind ModelRouter with an explicitly-configured Guzzle client.
        // Without this, the container auto-resolves the constructor's
        // `?GuzzleClient` to a default Guzzle (no base_uri, no timeout)
        // and our Anthropic calls fail with "No host part in the URL".
        $this->app->singleton(ModelRouter::class, function ($app) {
            return new ModelRouter(new GuzzleClient([
                'base_uri' => rtrim((string) config('ai.anthropic.base_url'), '/'),
                'timeout'  => (int) config('ai.anthropic.timeout', 30),
            ]));
        });

        // CostCeilingGuard takes an optional TelegramNotifier so it can fire
        // a once-per-month alert when soft/hard caps are crossed. Wire it
        // explicitly here so the dependency is available in production but
        // omitted in unit tests that just instantiate `new CostCeilingGuard()`.
        $this->app->singleton(CostCeilingGuard::class, function ($app) {
            return new CostCeilingGuard($app->make(TelegramNotifier::class));
        });
    }

    public function boot(): void
    {
        Event::listen(WhatsAppMessageReceived::class, RouteToAgentListener::class);

        // ── Phase 4a milestone 4: autonomous outreach triggers ───────────────
        // Each listener inspects the event's person, looks up matching
        // OutreachTemplate rows where autonomous_enabled=true, and dispatches
        // OutreachOrchestrator::autonomousSend(). When no template matches OR
        // none are autonomous_enabled, the listener no-ops silently.
        Event::listen(LeadCreated::class,             OnLeadCreated::class);
        Event::listen(LeadConverted::class,           OnDepositFirst::class);
        Event::listen(LargeWithdrawalReceived::class, OnLargeWithdrawal::class);

        // Person observer dispatches LeadCreated on new LEAD inserts.
        Person::observe(PersonObserver::class);

        // ── Phase B: Super admin bypass ──────────────────────────────────────
        // Super admins pass every Gate check unconditionally. Return null (not
        // false) for non-super-admins so normal policy evaluation continues.
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->is_super_admin ? true : null;
        });

        // ── Phase B: Permission audit observer ───────────────────────────────
        User::observe(UserPermissionObserver::class);

        // ── Phase C: Person policy ────────────────────────────────────────────
        Gate::policy(Person::class, PersonPolicy::class);
    }
}
