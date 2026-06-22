<?php

use App\Http\Controllers\AmoCrmOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/amocrm/oauth/redirect', [AmoCrmOAuthController::class, 'redirect'])
    ->name('amocrm.oauth.redirect');
Route::get('/amocrm/oauth/callback', [AmoCrmOAuthController::class, 'callback'])
    ->name('amocrm.oauth.callback');
