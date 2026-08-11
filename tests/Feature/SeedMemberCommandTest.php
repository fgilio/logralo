<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

/** Runs the command and hands back the link it printed for WhatsApp. */
function seedMember(string $name, string $email, ?string $timezone = null): string
{
    $arguments = ['name' => $name, 'email' => $email];

    if ($timezone !== null) {
        $arguments['timezone'] = $timezone;
    }

    expect(Artisan::call('logralo:seed-member', $arguments))->toBe(Command::SUCCESS);

    preg_match('#https?://\S+#', Artisan::output(), $matches);

    expect($matches)->not->toBeEmpty();

    return $matches[0];
}

it('creates the member and prints a link that works', function (): void {
    $link = seedMember('Ana Pérez', 'ana@logralo.test');

    $user = User::query()->sole();

    expect($user->name)->toBe('Ana Pérez')
        ->and($user->email)->toBe('ana@logralo.test')
        ->and($user->timezone)->toBe('America/Montevideo')
        ->and($user->password)->toBeNull()
        ->and($user->magic_link_token)->not->toBeNull()
        ->and($user->magic_link_used_at)->toBeNull();

    $this->get($link)->assertOk()->assertSee('Entrar como Ana Pérez');
    $this->post($link)->assertRedirect(route('password.create'));

    $this->assertAuthenticatedAs($user->fresh());
});

it('never puts the raw token in the database', function (): void {
    $link = seedMember('Ana Pérez', 'ana@logralo.test');

    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
    $plainToken = basename((string) parse_url($link, PHP_URL_PATH));

    expect(User::query()->sole()->magic_link_token)
        ->toBe(hash('sha256', $plainToken))
        ->not->toBe($plainToken)
        ->and($query)->toHaveKeys(['expires', 'signature']);
});

it('takes a timezone and stamps when the link went out', function (): void {
    seedMember('Beto', 'beto@logralo.test', 'Europe/Lisbon');

    $user = User::query()->sole();

    expect($user->timezone)->toBe('Europe/Lisbon')
        ->and($user->magic_link_sent_at?->toIso8601String())->toBe(now()->toIso8601String());
});

it('lowercases and trims the email', function (): void {
    seedMember('  Ana Pérez  ', '  Ana@Logralo.TEST ');

    $user = User::query()->sole();

    expect($user->email)->toBe('ana@logralo.test')
        ->and($user->name)->toBe('Ana Pérez');
});

it('updates the same member instead of adding a second one', function (): void {
    seedMember('Ana', 'ana@logralo.test');
    seedMember('Ana Pérez', 'ANA@logralo.test', 'Europe/Lisbon');

    expect(User::query()->count())->toBe(1);

    $user = User::query()->sole();

    expect($user->name)->toBe('Ana Pérez')
        ->and($user->timezone)->toBe('Europe/Lisbon');
});

it('keeps the member id and their history across a re-run', function (): void {
    seedMember('Ana', 'ana@logralo.test');
    $id = User::query()->sole()->id;

    seedMember('Ana', 'ana@logralo.test');

    expect(User::query()->sole()->id)->toBe($id);
});

it('rotates the token so the link already sent stops working', function (): void {
    $first = seedMember('Ana', 'ana@logralo.test');
    $second = seedMember('Ana', 'ana@logralo.test');

    expect($second)->not->toBe($first);

    $this->get($first)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Ese enlace ya no sirve. Entrá con tu email y contraseña.');

    $this->assertGuest();

    $this->post($second)->assertRedirect(route('password.create'));
    $this->assertAuthenticatedAs(User::query()->sole());
});

it('says whether it created or updated the member', function (): void {
    $this->artisan('logralo:seed-member', ['name' => 'Ana', 'email' => 'ana@logralo.test'])
        ->expectsOutputToContain('Created Ana (ana@logralo.test, America/Montevideo).')
        ->assertExitCode(Command::SUCCESS);

    $this->artisan('logralo:seed-member', ['name' => 'Ana', 'email' => 'ana@logralo.test'])
        ->expectsOutputToContain('Updated Ana (ana@logralo.test, America/Montevideo).')
        ->assertExitCode(Command::SUCCESS);
});

it('refuses a timezone that is not a real one', function (): void {
    $this->artisan('logralo:seed-member', [
        'name' => 'Ana',
        'email' => 'ana@logralo.test',
        'timezone' => 'Mars/Olympus',
    ])
        ->expectsOutputToContain('Unknown timezone [Mars/Olympus].')
        ->assertExitCode(Command::FAILURE);

    expect(User::query()->count())->toBe(0);
});

it('refuses something that is not an email', function (): void {
    $this->artisan('logralo:seed-member', ['name' => 'Ana', 'email' => 'ana-arroba-logralo'])
        ->expectsOutputToContain('Invalid email [ana-arroba-logralo].')
        ->assertExitCode(Command::FAILURE);

    expect(User::query()->count())->toBe(0);
});

it('checks the timezone before it touches the database', function (): void {
    seedMember('Ana', 'ana@logralo.test');
    $token = User::query()->sole()->magic_link_token;

    $this->artisan('logralo:seed-member', [
        'name' => 'Ana',
        'email' => 'ana@logralo.test',
        'timezone' => 'Mars/Olympus',
    ])->assertExitCode(Command::FAILURE);

    expect(User::query()->sole()->magic_link_token)->toBe($token);
});
