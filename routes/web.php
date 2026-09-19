<?php

use Filament\Facades\Filament;
use Gadya\Connect\Http\Controllers\HelpController;
use Gadya\Connect\Http\Controllers\SingleSignOnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:20,1'])
    ->get('gadya-connect/sso', SingleSignOnController::class)
    ->name('gadya-connect.sso');

$helpPage = config('gadya-connect.help_page', 'auto');

if ($helpPage === true || $helpPage === 'true' || ($helpPage === 'auto' && ! class_exists(Filament::class))) {
    Route::middleware(['web', 'auth'])->prefix('gadya-connect/help')->name('gadya-connect.help.')->group(function () {
        Route::get('/', [HelpController::class, 'index'])->name('index');
        Route::post('/', [HelpController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::get('/{reference}', [HelpController::class, 'show'])->name('show');
        Route::post('/{reference}', [HelpController::class, 'reply'])->middleware('throttle:20,1')->name('reply');
    });
}
