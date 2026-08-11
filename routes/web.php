<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'password.set'])->group(function (): void {
    // The one screen. Everything else hangs off the profile avatar.
    Route::livewire('/', 'pages::today')->name('today');
    Route::livewire('perfil', 'pages::profile')->name('profile');
});
