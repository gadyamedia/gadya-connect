<?php

use Gadya\Connect\Http\Controllers\SingleSignOnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:20,1'])
    ->get('gadya-connect/sso', SingleSignOnController::class)
    ->name('gadya-connect.sso');
