<?php

declare(strict_types=1);

use App\Http\Controllers\Api\HenryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes here are mounted under /api by Laravel's default api routing.
| Henry's MCP server (registered in ~/.openclaw/openclaw.json) calls these
| endpoints with a bearer token verified by HenryApiToken middleware.
|
*/

Route::prefix('henry')
    ->middleware('henry.token')
    ->controller(HenryController::class)
    ->group(function () {
        Route::get('health',                       'health');
        Route::get('people/search',                'searchPeople');
        Route::get('people/{id}',                  'showPerson');
        Route::get('metrics/book',                 'bookMetrics');
        Route::post('events',                      'postEvent');
        Route::post('actions/pause-autonomous',    'pauseAutonomous');
    });
