<?php

use App\Http\Controllers\EmailTrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Email tracking & unsubscribe
Route::get('/email/track/open/{recipient}', [EmailTrackingController::class, 'trackOpen'])
    ->name('email.track.open');

Route::get('/email/track/click/{recipient}', [EmailTrackingController::class, 'trackClick'])
    ->name('email.track.click');

Route::get('/email/unsubscribe/{recipient}', [EmailTrackingController::class, 'unsubscribeForm'])
    ->name('email.unsubscribe');

Route::post('/email/unsubscribe/{recipient}', [EmailTrackingController::class, 'unsubscribeConfirm'])
    ->name('email.unsubscribe.confirm');
