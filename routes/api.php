<?php

use App\Http\Controllers\Api\SmsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

Route::prefix('sms')->group(function () {
    // Get messages ready to send (main endpoint for phone querying)
    Route::get('/messages', [SmsController::class, 'getMessagesToSend'])
        ->name('api.sms.messages');

    // Get SMS statistics
    Route::get('/statistics', [SmsController::class, 'statistics'])
        ->name('api.sms.statistics');
});

