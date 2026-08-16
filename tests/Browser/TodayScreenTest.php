<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\Reaction;
use App\Models\User;
use Pest\Browser\Playwright\Playwright;

/**
 * The phone smoke tests.
 *
 * A short list on purpose: a real browser is the only place that proves the
 * Alpine tap handler, the Livewire round trip and the Flux sheet still agree
 * with each other. Everything narrower than that is a feature test.
 */
it('shows the member their goals on a phone, without breaking the console', function (): void {
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio', 'emoji' => '🏋️']);

    $this->actingAs($user);

    visit('/')->on()->iPhone15Pro()
        ->assertSee('Logralo')
        ->assertSee('Gimnasio')
        ->assertVisible('@goal-card-'.$goal->id)
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'false')
        ->assertNoJavaScriptErrors();
});

it('marks a goal when the card is tapped', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Correr']);

    $this->actingAs($user);

    visit('/')->on()->iPhone15Pro()
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'false')
        ->click('@goal-card-'.$goal->id)
        ->wait(1)
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'true')
        ->assertNoJavaScriptErrors();

    // No photo went with the tap, so the day is claimed as a ghost mark.
    $mark = Mark::query()->where('goal_id', $goal->id)->sole();

    expect($mark->photo_key)->toBeNull()
        ->and($mark->marked_on->toDateString())->toBe($user->clock()->today()->toDateString());
});

/**
 * The resize that happens between the shutter and the upload.
 *
 * Driven through `window.Logralo` rather than through the file input, because
 * an upload cannot complete against this test server at all: Pest's Laravel
 * driver builds its request with an empty files array, so a multipart body
 * never reaches PHP as an upload. What is worth proving lives on this side of
 * that line anyway — a real Chromium, a real 24 MP JPEG, and the real decode,
 * resample and re-encode the phone will do.
 */
it('shrinks a camera original in the browser before it uploads', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['name' => 'Correr']);

    $budget = (int) config('logralo.photos.client_max_megapixels') * 1_000_000;
    $quality = (int) config('logralo.photos.client_jpeg_quality') / 100;

    // Both sides are scaled by the square root of the ratio between 24 MP and
    // the budget, so these are the exact dimensions the server should receive.
    $scale = sqrt($budget / (6000 * 4000));

    $this->actingAs($user);

    $page = visit('/')->on()->iPhone15Pro();

    // The fixture is drawn rather than uploaded, with enough colour in it that
    // the JPEG has something to spend bytes on. The whole round trip — draw
    // 24 MP, encode it, decode it, resample it, encode it again — runs well
    // past the five seconds an assertion is normally given.
    $script = <<<JS
        (async () => {
            const source = new OffscreenCanvas(6000, 4000);
            const context = source.getContext('2d', { alpha: false });

            for (let drawn = 0; drawn < 300; drawn++) {
                context.fillStyle = `hsl(\${(drawn * 37) % 360} 80% \${20 + ((drawn * 13) % 60)}%)`;
                context.fillRect((drawn * 811) % 6000, (drawn * 577) % 4000, 40 + (drawn % 600), 40 + (drawn % 400));
            }

            const original = new File(
                [await source.convertToBlob({ type: 'image/jpeg', quality: 0.95 })],
                'IMG_0042.jpg',
                { type: 'image/jpeg' },
            );

            const shrunk = await window.Logralo.compressPhoto(original, {
                maxPixels: {$budget},
                quality: {$quality},
            });

            const decoded = await createImageBitmap(shrunk);

            return JSON.stringify({
                width: decoded.width,
                height: decoded.height,
                type: shrunk.type,
                name: shrunk.name,
                shrank: shrunk.size < original.size,
            });
        })()
    JS;

    $result = json_decode((string) Playwright::usingTimeout(60_000, fn (): mixed => $page->script($script)), true);

    expect($result)->toBe([
        'width' => (int) round(6000 * $scale),
        'height' => (int) round(4000 * $scale),
        'type' => 'image/jpeg',
        'name' => 'IMG_0042.jpg',
        'shrank' => true,
    ]);

    $page->assertNoJavaScriptErrors();
});

it('reacts to a card from the bar the plus button opens', function (): void {
    $ana = User::factory()->create(['name' => 'Ana Pérez']);
    $bruno = User::factory()->create(['name' => 'Bruno']);
    $mark = Mark::factory()->for(Goal::factory()->for($bruno)->create(['name' => 'Guitarra']))->create();

    $this->actingAs($ana);

    visit('/')->on()->iPhone15Pro()
        ->assertSee('Guitarra')
        ->click('@react-open-'.$mark->id)
        ->wait(1)
        ->click('@react-'.$mark->id.'-clap')
        ->wait(1)
        ->assertVisible('@reactions-'.$mark->id)
        ->assertNoJavaScriptErrors();

    $reaction = Reaction::query()->sole();

    expect($reaction->user_id)->toBe($ana->id)
        ->and($reaction->mark_id)->toBe($mark->id);
});

it('opens the month table from the trophy button', function (): void {
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    Goal::factory()->for($user)->create();

    $this->actingAs($user);

    visit('/')->on()->iPhone15Pro()
        ->assertMissing('@standings')
        ->click('@open-standings')
        ->wait(1)
        ->assertVisible('@standings')
        ->assertSeeIn('@standings', 'Ana Pérez')
        ->assertNoJavaScriptErrors();
});

it('leaves the goal name the wide half of the new-goal sheet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Flux copies `class` onto its own `w-full` wrapper, which once let the
    // emoji box eat the row and squeezed the name box down to a sliver.
    visit('/perfil')->on()->iPhone15Pro()
        ->click('@new-goal')
        ->wait(1)
        ->assertScript("document.querySelector('[data-test=goal-name]').clientWidth > 2 * document.querySelector('[data-test=goal-emoji]').clientWidth")
        ->type('@goal-name', 'Gimnasio')
        ->click('@save-goal')
        ->wait(1)
        ->assertSee('Gimnasio')
        ->assertNoJavaScriptErrors();

    expect($user->goals()->sole()->name)->toBe('Gimnasio');
});

it('renders the login screen and refuses the wrong password', function (): void {
    User::factory()->create(['email' => 'ana@logralo.test']);

    visit('/entrar')->on()->iPhone15Pro()
        ->assertSee('Volvé a la racha')
        ->type('@email', 'ana@logralo.test')
        ->type('@password', 'not-the-password')
        ->click('@submit')
        ->wait(1)
        ->assertPathIs('/entrar')
        ->assertSee(__('auth.failed'))
        ->assertNoJavaScriptErrors();
});
