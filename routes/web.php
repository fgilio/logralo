<?php

declare(strict_types=1);

use App\Http\Controllers\ShareCardController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\ShareRevokeController;
use Illuminate\Support\Facades\Route;

/*
 * Shared links, and the only public corner of the app.
 *
 * A WhatsApp preview is built by an anonymous crawler, so anything it has to
 * draw lives outside `auth` — behind the gate, every share the group ever sent
 * unfurled as the login page. The 24-character token is the credential, and
 * clearing it is how a link is taken back.
 */
Route::get('l/{token}', ShareController::class)
    ->where('token', '[A-Za-z0-9]{24}')
    ->middleware('throttle:share')
    ->name('share.show');

Route::get('l/{token}/{format}.jpg', ShareCardController::class)
    ->where(['token' => '[A-Za-z0-9]{24}', 'format' => 'og|portrait'])
    ->middleware('throttle:share')
    ->name('share.card');

Route::get('og.jpg', [ShareCardController::class, 'default'])->name('share.default-card');

Route::post('l/{token}/revocar', ShareRevokeController::class)
    ->where('token', '[A-Za-z0-9]{24}')
    ->middleware('auth')
    ->name('share.revoke');

Route::middleware(['auth', 'password.set'])->group(function (): void {
    // The one screen. Everything else hangs off the profile avatar.
    Route::livewire('/', 'pages::today')->name('today');
    Route::livewire('perfil', 'pages::profile')->name('profile');
});
