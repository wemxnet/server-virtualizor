<?php

use App\Http\Middleware\RequireAdminReauthentication;
use Extensions\Servers\Virtualizor\Http\Controllers\Admin\LoginController as AdminLoginController;
use Extensions\Servers\Virtualizor\Http\Controllers\Client\LoginController as ClientLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/orders/{order}/virtualizor/login', ClientLoginController::class)
        ->name('virtualizor.login');
});

Route::middleware(['web', 'auth', 'admin', RequireAdminReauthentication::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/orders/{order}/virtualizor/login', AdminLoginController::class)
            ->middleware('permission:admin.orders.view')
            ->name('virtualizor.login');
    });
