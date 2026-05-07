<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly full sync at 00:05 SAST
Schedule::command('mtr:sync --full')
    ->dailyAt('00:05')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping()
    ->runInBackground();

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

// Incremental sync every 15 minutes between 06:00–22:00 SAST
Schedule::command('mtr:sync --incremental')
    ->everyFifteenMinutes()
    ->timezone('Africa/Johannesburg')
    ->between('06:00', '22:00')
    ->withoutOverlapping()
    ->runInBackground();

// Detect dormant clients (14d / 30d) at 09:00 SAST and dispatch matching
// autonomous outreach templates. The job is a no-op when no templates have
// autonomous_enabled = true, so it's safe to run even before any templates
// are activated. 30-day dedup prevents re-firing for the same person.
Schedule::job(new \App\Jobs\AI\DetectDormantClientsJob())
    ->dailyAt('09:00')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping();
