<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

const RESET_LINK_NEUTRAL_STATUS = 'Si ese email es de alguien del grupo, le llega un enlace.';

it('sends the link to a member and says nothing about it', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ana@logralo.test']);

    // The message is flashed and rendered inside the same Livewire round trip,
    // so the returned HTML is where it has to show up.
    Livewire::test('pages::auth.forgot-password')
        ->set('email', 'ana@logralo.test')
        ->call('sendResetLink')
        ->assertHasNoErrors()
        ->assertSee(RESET_LINK_NEUTRAL_STATUS);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('says exactly the same thing about an email nobody has', function (): void {
    Notification::fake();

    // Same words, no error, nothing sent: the form cannot be used to find out
    // who is in the group.
    Livewire::test('pages::auth.forgot-password')
        ->set('email', 'nadie@logralo.test')
        ->call('sendResetLink')
        ->assertHasNoErrors()
        ->assertSee(RESET_LINK_NEUTRAL_STATUS);

    Notification::assertNothingSent();
});

it('still wants something that looks like an email', function (): void {
    Notification::fake();

    Livewire::test('pages::auth.forgot-password')
        ->set('email', 'no-es-un-email')
        ->call('sendResetLink')
        ->assertHasErrors(['email' => 'email']);

    Notification::assertNothingSent();
});

it('sets a new password from a valid token and rotates the remember token', function (): void {
    Event::fake([PasswordReset::class]);

    $user = User::factory()->create(['email' => 'ana@logralo.test', 'remember_token' => 'el-viejo']);
    $token = Password::createToken($user);

    Livewire::test('pages::auth.reset-password', ['token' => $token])
        ->set('email', 'ana@logralo.test')
        ->set('password', 'rachalarga1')
        ->set('password_confirmation', 'rachalarga1')
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    expect(session('status'))->toBe('Listo, ya podés entrar con la nueva.');

    $user->refresh();

    expect(Hash::check('rachalarga1', (string) $user->password))->toBeTrue()
        ->and($user->password_set_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($user->remember_token)->not->toBe('el-viejo')
        ->and($user->remember_token)->toHaveLength(60);

    Event::assertDispatched(PasswordReset::class);
});

it('takes the token off the query string when the screen loads', function (): void {
    $user = User::factory()->create(['email' => 'ana@logralo.test']);
    $token = Password::createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => 'ana@logralo.test']))
        ->assertOk()
        ->assertSee('Nueva contraseña')
        ->assertSee('ana@logralo.test', escape: false);
});

it('refuses a token that is not the one that was issued', function (): void {
    $user = User::factory()->create(['email' => 'ana@logralo.test']);
    Password::createToken($user);

    $component = Livewire::test('pages::auth.reset-password', ['token' => 'no-es-el-token'])
        ->set('email', 'ana@logralo.test')
        ->set('password', 'rachalarga1')
        ->set('password_confirmation', 'rachalarga1')
        ->call('resetPassword')
        ->assertHasErrors('email')
        ->assertNoRedirect();

    expect($component->errors()->first('email'))->toBe(__(Password::InvalidToken))
        ->and(Hash::check('rachalarga1', (string) $user->fresh()->password))->toBeFalse();
});

it('burns the token so the same link cannot be replayed', function (): void {
    $user = User::factory()->create(['email' => 'ana@logralo.test']);
    $token = Password::createToken($user);

    Livewire::test('pages::auth.reset-password', ['token' => $token])
        ->set('email', 'ana@logralo.test')
        ->set('password', 'rachalarga1')
        ->set('password_confirmation', 'rachalarga1')
        ->call('resetPassword')
        ->assertHasNoErrors();

    Livewire::test('pages::auth.reset-password', ['token' => $token])
        ->set('email', 'ana@logralo.test')
        ->set('password', 'otraracha22')
        ->set('password_confirmation', 'otraracha22')
        ->call('resetPassword')
        ->assertHasErrors('email');

    expect(Hash::check('rachalarga1', (string) $user->fresh()->password))->toBeTrue();
});

it('holds the new password to the same policy', function (): void {
    $user = User::factory()->create(['email' => 'ana@logralo.test']);
    $token = Password::createToken($user);

    Livewire::test('pages::auth.reset-password', ['token' => $token])
        ->set('email', 'ana@logralo.test')
        ->set('password', 'corta1')
        ->set('password_confirmation', 'corta1')
        ->call('resetPassword')
        ->assertHasErrors('password')
        ->assertNoRedirect();

    expect($user->fresh()->password_set_at)->toBeNull();
});

it('wipes both password fields when validation fails', function (): void {
    $user = User::factory()->create(['email' => 'ana@logralo.test']);
    $token = Password::createToken($user);

    Livewire::test('pages::auth.reset-password', ['token' => $token])
        ->set('email', 'ana@logralo.test')
        ->set('password', 'corta1')
        ->set('password_confirmation', 'corta1')
        ->call('resetPassword')
        ->assertHasErrors('password')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');
});
