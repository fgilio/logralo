<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Fails a login attempt once and hands back the component so the caller can
 * read the message the member would have seen.
 */
function attemptWithWrongPassword(string $email = 'ana@logralo.test'): Testable
{
    return Livewire::test('pages::auth.login')
        ->set('email', $email)
        ->set('password', 'la-que-no-es')
        ->call('login');
}

it('logs a member in and lands them on today', function (): void {
    $user = User::factory()->create(['email' => 'ana@logralo.test']);

    Livewire::test('pages::auth.login')
        ->set('email', 'ana@logralo.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('today'));

    expect(auth()->id())->toBe($user->id);
});

it('keeps the wrong password out with the error on the email field', function (): void {
    User::factory()->create(['email' => 'ana@logralo.test']);

    $component = attemptWithWrongPassword()
        ->assertOnlyHasErrors('email')
        ->assertNoRedirect();

    expect($component->errors()->first('email'))->toBe(__('auth.failed'));
    $this->assertGuest();
});

it('tells an unknown email exactly what it tells a known one', function (): void {
    $unknown = Livewire::test('pages::auth.login')
        ->set('email', 'nadie@logralo.test')
        ->set('password', 'la-que-no-es')
        ->call('login');

    expect($unknown->errors()->first('email'))->toBe(__('auth.failed'));
});

it('requires an email and a password', function (): void {
    Livewire::test('pages::auth.login')
        ->call('login')
        ->assertHasErrors(['email' => 'required', 'password' => 'required'])
        ->assertNoRedirect();
});

it('locks the member out after five failures and announces it', function (): void {
    Event::fake([Lockout::class]);

    User::factory()->create(['email' => 'ana@logralo.test']);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        expect(attemptWithWrongPassword()->errors()->first('email'))->toBe(__('auth.failed'));
    }

    Event::assertNotDispatched(Lockout::class);

    $locked = attemptWithWrongPassword()->assertOnlyHasErrors('email');

    expect($locked->errors()->first('email'))
        ->toBe(__('auth.throttle', ['seconds' => 60, 'minutes' => 1]));

    Event::assertDispatched(Lockout::class);
    $this->assertGuest();
});

it('clears the limiter once the member gets in', function (): void {
    User::factory()->create(['email' => 'ana@logralo.test']);

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        attemptWithWrongPassword()->assertHasErrors('email');
    }

    Livewire::test('pages::auth.login')
        ->set('email', 'ana@logralo.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    auth()->logout();

    // With the counter still at four, the fifth of these would be the throttle
    // message instead of the plain "wrong password" one.
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        expect(attemptWithWrongPassword()->errors()->first('email'))->toBe(__('auth.failed'));
    }
});
