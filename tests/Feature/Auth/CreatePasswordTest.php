<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('sets the password, stamps the date and rotates the remember token', function (): void {
    $user = User::factory()->withoutPassword()->create(['remember_token' => 'el-viejo']);

    $this->actingAs($user);

    Livewire::test('pages::auth.create-password')
        ->set('password', 'rachalarga1')
        ->set('password_confirmation', 'rachalarga1')
        ->call('createPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('today'));

    $user->refresh();

    expect(Hash::check('rachalarga1', (string) $user->password))->toBeTrue()
        ->and($user->hasPassword())->toBeTrue()
        ->and($user->password_set_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($user->remember_token)->not->toBe('el-viejo')
        ->and($user->remember_token)->toHaveLength(60);
});

it('keeps the plaintext out of the component once it is saved', function (): void {
    $this->actingAs(User::factory()->withoutPassword()->create());

    Livewire::test('pages::auth.create-password')
        ->set('password', 'rachalarga1')
        ->set('password_confirmation', 'rachalarga1')
        ->call('createPassword')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');
});

it('is unreachable once a password exists', function (): void {
    $this->actingAs(User::factory()->create());

    // The bounce happens in mount(), so it fires for the full page request and
    // for the component on its own.
    $this->get(route('password.create'))->assertRedirect(route('today'));

    Livewire::test('pages::auth.create-password')->assertRedirect(route('today'));
});

it('rejects a password the policy considers weak', function (): void {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::auth.create-password')
        ->set('password', 'corta1')
        ->set('password_confirmation', 'corta1')
        ->call('createPassword')
        ->assertHasErrors('password')
        ->assertNoRedirect();

    expect($component->errors()->first('password'))
        ->toBe(__('validation.min.string', ['attribute' => 'password', 'min' => 10]))
        ->and($user->fresh()->hasPassword())->toBeFalse();
});

it('demands letters and numbers', function (): void {
    $this->actingAs(User::factory()->withoutPassword()->create());

    Livewire::test('pages::auth.create-password')
        ->set('password', 'solamenteletras')
        ->set('password_confirmation', 'solamenteletras')
        ->call('createPassword')
        ->assertHasErrors('password');

    Livewire::test('pages::auth.create-password')
        ->set('password', '1234567890123')
        ->set('password_confirmation', '1234567890123')
        ->call('createPassword')
        ->assertHasErrors('password');
});

it('rejects a mismatched confirmation', function (): void {
    $this->actingAs(User::factory()->withoutPassword()->create());

    Livewire::test('pages::auth.create-password')
        ->set('password', 'rachalarga1')
        ->set('password_confirmation', 'rachacorta1')
        ->call('createPassword')
        ->assertHasErrors(['password' => 'confirmed']);
});

it('wipes both password fields when validation fails', function (): void {
    $this->actingAs(User::factory()->withoutPassword()->create());

    // Livewire carries component state into the next request, and a rejected
    // plaintext password has no business riding along.
    Livewire::test('pages::auth.create-password')
        ->set('password', 'corta1')
        ->set('password_confirmation', 'corta1')
        ->call('createPassword')
        ->assertHasErrors('password')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');
});
