<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\Reaction;
use App\Models\User;
use Carbon\CarbonImmutable;
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

it('marks and un-marks yesterday without removing the grace chip', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Correr']);

    $this->actingAs($user);

    $page = visit('/')->on()->iPhone15Pro()
        ->assertAriaAttribute('@grace-goal-'.$goal->id, 'pressed', 'false')
        ->click('@grace-goal-'.$goal->id)
        ->wait(1)
        ->assertVisible('@grace-goal-'.$goal->id)
        ->assertAriaAttribute('@grace-goal-'.$goal->id, 'pressed', 'true');

    expect(Mark::query()->where('goal_id', $goal->id)->count())->toBe(1);

    $page->click('@grace-goal-'.$goal->id)
        ->wait(1)
        ->assertVisible('@grace-goal-'.$goal->id)
        ->assertAriaAttribute('@grace-goal-'.$goal->id, 'pressed', 'false')
        ->assertNoJavaScriptErrors();

    expect(Mark::query()->where('goal_id', $goal->id)->count())->toBe(0);
});

it('marks a goal activated without a pointer or a key', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Correr']);

    $this->actingAs($user);

    $page = visit('/')->on()->iPhone15Pro();

    $page->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'false');

    // VoiceOver activates a role="button" by dispatching a click of its own:
    // no pointerup, so no `short-press`, and no keydown either. `element.click()`
    // is that same path, and the card is a div rather than a button on purpose,
    // so nothing synthesises the press for it.
    $page->script(<<<JS
        (() => {
            document.querySelector('[data-test="goal-card-{$goal->id}"]').click();

            return 'clicked';
        })()
    JS);

    $page->wait(1)
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'true')
        ->assertNoJavaScriptErrors();

    expect(Mark::query()->where('goal_id', $goal->id)->sole()->marked_on->toDateString())
        ->toBe($user->clock()->today()->toDateString());
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

    $budget = config()->integer('logralo.photos.client_max_megapixels') * 1_000_000;

    // Both sides are scaled by the square root of the ratio between 24 MP and
    // the budget, so these are the exact dimensions the server should receive.
    $scale = sqrt($budget / (6000 * 4000));

    $this->actingAs($user);

    $page = visit('/')->on()->iPhone15Pro();

    // The fixture is drawn rather than uploaded, with enough colour in it that
    // the JPEG has something to spend bytes on. The whole round trip — draw
    // 24 MP, encode it, decode it, resample it, encode it again — runs well
    // past the five seconds an assertion is normally given.
    $script = <<<'SHRINK'
        (async () => {
            const source = new OffscreenCanvas(6000, 4000);
            const context = source.getContext('2d', { alpha: false });

            for (let drawn = 0; drawn < 300; drawn++) {
                context.fillStyle = `hsl(${(drawn * 37) % 360} 80% ${20 + ((drawn * 13) % 60)}%)`;
                context.fillRect((drawn * 811) % 6000, (drawn * 577) % 4000, 40 + (drawn % 600), 40 + (drawn % 400));
            }

            const original = new File(
                [await source.convertToBlob({ type: 'image/jpeg', quality: 0.95 })],
                'IMG_0042.jpg',
                { type: 'image/jpeg' },
            );

            // The budget the head rendered, not one the test made up — so the
            // dimensions asserted below only come out right if what reaches
            // the browser is what `config/logralo.php` says.
            const shrunk = await window.Logralo.compressPhoto(original, window.Logralo.photo);

            const decoded = await createImageBitmap(shrunk);

            return JSON.stringify({
                width: decoded.width,
                height: decoded.height,
                type: shrunk.type,
                name: shrunk.name,
                shrank: shrunk.size < original.size,
            });
        })()
    SHRINK;

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

/**
 * The other half of the size check: a re-encode that came out heavier.
 *
 * Discarding it is right for a format the server can already open, and wrong
 * for one it cannot — a HEIC is dense enough that its honest JPEG routinely
 * grows, and handing the original back sends it to a GD-only server that
 * cannot decode it at all. A one-pixel checkerboard makes the case on purpose:
 * it is two colours in a perfect pattern, which deflate packs to nothing and
 * which lands on JPEG's worst case, an every-block full of high frequency.
 */
it('keeps the JPEG when the original is a format the server cannot read', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['name' => 'Correr']);

    $this->actingAs($user);

    $page = visit('/')->on()->iPhone15Pro();

    // Both runs are the same PNG bytes; only the declared type differs, and
    // the decoder goes by content, so the format claim is all that is under
    // test here.
    $script = <<<'SCRIPT'
        (async () => {
            const size = 1200;
            const source = new OffscreenCanvas(size, size);
            const context = source.getContext('2d');
            const image = context.createImageData(size, size);

            for (let pixel = 0; pixel < size * size; pixel++) {
                const lit = ((pixel % size) + ((pixel / size) | 0)) % 2 === 0 ? 255 : 0;

                image.data.set([lit, lit, lit, 255], pixel * 4);
            }

            context.putImageData(image, 0, 0);

            const bytes = await source.convertToBlob({ type: 'image/png' });

            const run = async (type) => {
                const original = new File([bytes], `flat.${type.split('/')[1]}`, { type });
                const result = await window.Logralo.compressPhoto(original);

                return { type: result.type, grew: result.size >= original.size };
            };

            return JSON.stringify({
                png: await run('image/png'),
                heic: await run('image/heic'),
            });
        })()
    SCRIPT;

    $result = json_decode((string) Playwright::usingTimeout(60_000, fn (): mixed => $page->script($script)), true);

    // The PNG is handed back untouched; the HEIC keeps the heavier JPEG,
    // because a HEIC the server cannot decode is worse than a big upload.
    expect($result)->toBe([
        'png' => ['type' => 'image/png', 'grew' => true],
        'heic' => ['type' => 'image/jpeg', 'grew' => true],
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
