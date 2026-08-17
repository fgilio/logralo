<?php

declare(strict_types=1);

use App\Http\Controllers\LogoutController;
use App\Http\Controllers\MagicLinkController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::livewire('entrar', 'pages::auth.login')->name('login');
    Route::livewire('recuperar', 'pages::auth.forgot-password')->name('password.request');
    Route::livewire('recuperar/{token}', 'pages::auth.reset-password')->name('password.reset');

    // Both halves of the magic link carry the same signature, so neither the
    // member id nor the token can be swapped on the way through WhatsApp.
    Route::middleware(['signed', 'throttle:magic-link'])->group(function (): void {
        Route::get('acceso/{user}/{token}', [MagicLinkController::class, 'show'])->name('magic-link.show');
        Route::post('acceso/{user}/{token}', [MagicLinkController::class, 'consume'])->name('magic-link.consume');
    });
});

Route::middleware('auth')->group(function (): void {
    // Deliberately outside the password.set gate: a member who has not chosen a
    // password yet must still be able to leave.
    Route::livewire('clave', 'pages::auth.create-password')->name('password.create');
    Route::post('salir', LogoutController::class)->name('logout');
});
