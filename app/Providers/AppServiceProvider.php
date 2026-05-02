<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\WhatsApp\WhatsAppMessageReceived;
use App\Listeners\WhatsApp\RouteToAgentListener;
use App\Models\Person;
use App\Models\User;
use App\Observers\UserPermissionObserver;
use App\Policies\PersonPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(WhatsAppMessageReceived::class, RouteToAgentListener::class);

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
