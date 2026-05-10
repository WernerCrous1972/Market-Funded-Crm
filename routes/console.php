<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly full sync at 00:05 SAST.
// Gated by MTR_SYNC_ENABLED so production can disable while its IP is
// blocked at the Cloudflare layer from reaching the MTR API. Defaults
// true so local dev and the Mac-as-relay path keep working unchanged.
if (env('MTR_SYNC_ENABLED', true)) {
    Schedule::command('mtr:sync --full')
        ->dailyAt('00:05')
        ->timezone('Africa/Johannesburg')
        ->withoutOverlapping()
        ->runInBackground();
}

// Refresh person_metrics nightly at 01:00 SAST (after MTR sync at 00:05)
Schedule::command('metrics:refresh --sync')
    ->dailyAt('01:00')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping()
    ->runInBackground();

// Calculate health scores daily at 01:30 SAST (after metrics:refresh at 01:00)
Schedule::job(new \App\Jobs\Metrics\CalculateHealthScoresJob())
    ->dailyAt('01:30')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping();

// Incremental sync every 15 minutes between 06:00–22:00 SAST.
// Same MTR_SYNC_ENABLED gate as the nightly full.
if (env('MTR_SYNC_ENABLED', true)) {
    Schedule::command('mtr:sync --incremental')
        ->everyFifteenMinutes()
        ->timezone('Africa/Johannesburg')
        ->between('06:00', '22:00')
        ->withoutOverlapping()
        ->runInBackground();
}

// Detect dormant clients (14d / 30d) at 09:00 SAST and dispatch matching
// autonomous outreach templates. The job is a no-op when no templates have
// autonomous_enabled = true, so it's safe to run even before any templates
// are activated. 30-day dedup prevents re-firing for the same person.
Schedule::job(new \App\Jobs\AI\DetectDormantClientsJob())
    ->dailyAt('09:00')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping();
