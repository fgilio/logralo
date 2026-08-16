<?php

declare(strict_types=1);

use App\Actions\RemoveAvatar;
use App\Actions\UpdateAvatar;
use App\Models\Goal;
use App\Models\User;
use App\Queries\MonthlyStandings;
use App\Services\PhotoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Profile pictures: what an upload leaves on the disk, what the three sources
 * fall through to, and what the profile screen does with each.
 */

it('stores one square derivative under an avatar key', function (): void {
    Storage::fake('photos');

    $key = resolve(PhotoProcessor::class)->storeAvatar(UploadedFile::fake()->image('face.jpg', 1200, 800));

    $size = (int) config('logralo.avatars.size');
    $stored = getimagesizefromstring((string) Storage::disk('photos')->get("{$key}/avatar.webp"));

    expect($key)->toStartWith(PhotoProcessor::AVATAR_PREFIX.'/')
        ->and(Storage::disk('photos')->allFiles())->toBe(["{$key}/avatar.webp"])
        // Cropped rather than letterboxed: a circle would eat the bars anyway.
        ->and([$stored[0], $stored[1]])->toBe([$size, $size]);
});

it('keeps an avatar out of the way of the feed derivatives', function (): void {
    Storage::fake('photos');

    $photo = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('proof.jpg', 400, 500));
    $avatar = resolve(PhotoProcessor::class)->storeAvatar(UploadedFile::fake()->image('face.jpg', 400, 400));

    // The prefix is what lets a bucket listing tell a face from a gym selfie.
    expect($avatar)->toStartWith('avatars/')
        ->and($photo->key)->not->toStartWith('avatars/')
        ->and(resolve(PhotoProcessor::class)->avatarUrl($avatar))->toContain("{$avatar}/avatar.webp");
});

it('replaces the picture and takes the old one off the disk', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();

    resolve(UpdateAvatar::class)->handle($user, UploadedFile::fake()->image('first.jpg', 400, 400));
    $first = $user->avatar_key;

    resolve(UpdateAvatar::class)->handle($user, UploadedFile::fake()->image('second.jpg', 400, 400));

    expect($first)->not->toBeNull()
        ->and($user->avatar_key)->not->toBe($first)
        // One member, one picture: the replaced one is not left paying rent.
        ->and(Storage::disk('photos')->exists((string) $first))->toBeFalse()
        ->and(Storage::disk('photos')->files($user->avatar_key))->toHaveCount(1);
});

it('falls back to Gravatar once the upload is removed', function (): void {
    Storage::fake('photos');
    config()->set('logralo.avatars.gravatar', true);

    $user = User::factory()->create(['email' => 'franco@example.com']);

    resolve(UpdateAvatar::class)->handle($user, UploadedFile::fake()->image('face.jpg', 400, 400));
    $key = (string) $user->avatar_key;

    expect($user->pictureUrl())->toContain("{$key}/avatar.webp");

    resolve(RemoveAvatar::class)->handle($user);

    expect($user->avatar_key)->toBeNull()
        ->and($user->pictureUrl())->toBeNull()
        ->and($user->gravatarUrl())->toContain(hash('sha256', 'franco@example.com'))
        ->and(Storage::disk('photos')->exists($key))->toBeFalse();
});

it('shrugs off removing a picture that was never there', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();

    resolve(RemoveAvatar::class)->handle($user);

    expect($user->avatar_key)->toBeNull();
});

it('applies a picked photo without a second tap', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('avatar', UploadedFile::fake()->image('face.jpg', 600, 400))
        ->call('saveAvatar')
        ->assertHasNoErrors()
        // Emptied, so the next pick is not saved on top of this one.
        ->assertSet('avatar', null);

    expect($user->refresh()->avatar_key)->not->toBeNull();
});

it('lays the Gravatar over the initials rather than replacing them', function (): void {
    config()->set('logralo.avatars.gravatar', true);

    $user = User::factory()->create(['email' => 'franco@example.com', 'name' => 'Franco Gilio']);

    $page = Livewire::actingAs($user)->test('pages::today')->html();

    // Both, in one avatar: the initials are what shows, and the layer on top
    // of them is what takes itself away when the address has no Gravatar.
    // Flux renders `$initials ?? $slot`, so passing a name or initials
    // alongside the layer silently drops it — which is what this pins.
    expect($page)->toContain(hash('sha256', 'franco@example.com'))
        ->toContain('onerror="this.remove()"')
        ->toContain('>FG')
        ->toContain('d=404');
});

it('shows an uploaded picture instead of asking Gravatar', function (): void {
    Storage::fake('photos');
    config()->set('logralo.avatars.gravatar', true);

    $user = User::factory()->create(['email' => 'franco@example.com']);
    resolve(UpdateAvatar::class)->handle($user, UploadedFile::fake()->image('face.jpg', 400, 400));

    $page = Livewire::actingAs($user)->test('pages::today')->html();

    expect($page)->toContain("{$user->avatar_key}/avatar.webp")
        ->not->toContain(hash('sha256', 'franco@example.com'));
});

it('leaves Gravatar out of every page when it is switched off', function (): void {
    config()->set('logralo.avatars.gravatar', false);

    $user = User::factory()->create(['email' => 'franco@example.com']);

    expect(Livewire::actingAs($user)->test('pages::today')->html())
        ->not->toContain(hash('sha256', 'franco@example.com'));
});

it('puts the same face on a standings row the recap froze a name into', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    Goal::factory()->for($user)->create();

    resolve(UpdateAvatar::class)->handle($user, UploadedFile::fake()->image('face.jpg', 400, 400));

    // The standings carry a user id and a name — a recap freezes them as JSON
    // at month close — so the roster is what turns one back into a picture,
    // and the picture is whatever the member has now rather than then.
    $row = Blade::render(
        '<x-standing-row :standing="$standing" />',
        ['standing' => resolve(MonthlyStandings::class)->current()->sole()],
    );

    expect($row)->toContain("{$user->avatar_key}/avatar.webp");
});

it('reads the roster once for the whole page', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create();
    User::factory()->count(4)->create();

    DB::enableQueryLog();

    Livewire::actingAs($user)->test('pages::today')->html();

    // The pulse strip, the monthly table and every avatar on the page want the
    // same five rows. Before `Members` held the read they took one full scan
    // each, and the avatars were the ones who added the third. Counted by the
    // scan's own shape, so the feed's eager load of one mark's author — which
    // is keyed by id and is not a roster read — stays out of it.
    $rosterReads = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => $query['query'] === 'select * from "users" order by "name" asc')
        ->values();

    expect($rosterReads)->toHaveCount(1);
});

it('does nothing when the save races the upload', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('saveAvatar')
        ->assertHasNoErrors();

    expect($user->refresh()->avatar_key)->toBeNull();
});

it('refuses a file that is not an image', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('avatar', UploadedFile::fake()->create('cv.pdf', 12, 'application/pdf'))
        ->call('saveAvatar')
        ->assertHasErrors(['avatar' => 'mimetypes']);

    expect($user->refresh()->avatar_key)->toBeNull();
});

it('removes the picture from the profile screen', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    resolve(UpdateAvatar::class)->handle($user, UploadedFile::fake()->image('face.jpg', 400, 400));

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('removeAvatar');

    expect($user->refresh()->avatar_key)->toBeNull()
        ->and(Storage::disk('photos')->allFiles())->toBe([]);
});
