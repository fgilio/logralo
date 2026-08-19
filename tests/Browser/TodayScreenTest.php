<?php

declare(strict_types=1);

use App\Models\Comment;
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

it('un-marks a goal from the sheet, and never from the tap alone', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Correr']);
    Mark::factory()->for($goal)->on($user->clock()->today()->toDateString())->create();

    $this->actingAs($user);

    // `aria-pressed` is drawn straight off the mark, so it is the card saying
    // whether the day survived the tap.
    visit('/')->on()->iPhone15Pro()
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'true')
        // And the card says the tap opens a dialog, so a screen reader does
        // not announce a toggle and then hand over a sheet.
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'haspopup', 'dialog')
        // The tap that used to delete the day now only opens the way out.
        ->click('@goal-card-'.$goal->id)
        ->wait(1)
        ->assertVisible('@remove')
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'true')
        ->click('@remove')
        ->wait(1)
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'false')
        ->assertNoJavaScriptErrors();

    expect(Mark::query()->count())->toBe(0);
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

    $mark = Mark::query()->where('goal_id', $goal->id)->sole();

    expect($mark->marked_on->toDateString())->toBe($user->clock()->yesterday()->toDateString());

    // The chip stays in the banner precisely so yesterday can still be taken
    // back, and taking it back goes through the chip's own sheet — the tap
    // itself no longer un-marks anything. Today's card for this goal is
    // unmarked, so its sheet offers the camera rather than a second `@remove`.
    $page->click('@grace-goal-'.$goal->id)
        ->wait(1)
        ->click('@remove')
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

it('opens a photo full screen, and lets you back out of it', function (): void {
    $ana = User::factory()->create();
    $bruno = User::factory()->create();
    $mark = Mark::factory()
        ->for(Goal::factory()->for($bruno)->create(['name' => 'Guitarra']))
        ->withPhoto()
        ->create(['note' => 'Media hora de escalas antes de que se despierte nadie.']);

    $this->actingAs($ana);

    $page = visit('/')->on()->iPhone15Pro()
        ->assertMissing('@viewer-close')
        ->click('@viewer-open-'.$mark->id)
        ->wait(1)
        ->assertVisible('@viewer-close')
        // The tap that opens the photo is no longer also a press on the card.
        ->assertMissing('@react-'.$mark->id.'-clap');

    // The note is not part of the gesture: it carries its own scroll and its
    // own selection, and a touch on it used to close the whole viewer.
    $page->click('@viewer-note')
        ->wait(1)
        ->assertVisible('@viewer-close');

    $page->click('@viewer-close')
        ->wait(1)
        ->assertMissing('@viewer-close');

    // Down is where the thread is, so dragging that way has to hold. This is
    // also the case that regressed once: clamping the picture at zero left a
    // downward drag looking exactly like a tap, and a tap closes.
    $page->click('@viewer-open-'.$mark->id)
        ->wait(1)
        ->drag('@viewer-photo', '@viewer-note')
        ->wait(1)
        ->assertVisible('@viewer-close');

    // And up, carried far enough to count as a throw, is the way out.
    $page->drag('@viewer-photo', '@viewer-grip')
        ->wait(1)
        ->assertMissing('@viewer-close')
        ->assertNoJavaScriptErrors();
});

it('reacts and comments without leaving the open photo', function (): void {
    $ana = User::factory()->create(['name' => 'Ana']);
    $bruno = User::factory()->create();
    $mark = Mark::factory()
        ->for(Goal::factory()->for($bruno)->create(['name' => 'Guitarra']))
        ->withPhoto()
        ->create();

    $this->actingAs($ana);

    $page = visit('/')->on()->iPhone15Pro()
        ->click('@viewer-open-'.$mark->id)
        ->wait(1)
        ->assertVisible('@viewer-close')
        // The thread arrives on its own request, so the field is the proof it
        // landed — asserted here rather than only at the end, so a thread that
        // never loaded reads differently from one a later render wiped.
        ->assertVisible('@comment-body')
        ->assertNoJavaScriptErrors();

    $page->click('@viewer-react-open')
        ->wait(1)
        ->click('@viewer-react-fire')
        ->wait(1)
        // The count is drawn from the payload and moved in place, so it is
        // right before the write that follows it has come back.
        ->assertVisible('@viewer-tally');

    // Sixteen pixels is the line between a field you tap and a field that
    // zooms the whole viewer on the way in — iOS scales the page up to reach
    // anything smaller, and does not scale it back when the keyboard goes.
    $page->assertScript(
        "parseFloat(getComputedStyle(document.querySelector('[data-test=\"comment-body\"]')).fontSize) >= 16",
    );

    $page->type('@comment-body', 'Sos un crack')
        ->click('@comment-send')
        ->wait(1)
        ->assertSee('Sos un crack')
        // Emptied by the field itself the moment the arrow was tapped, so the
        // next comment starts on a clean box rather than on the last one.
        ->assertValue('@comment-body', '')
        // Still open: a comment is something you leave while looking.
        ->assertVisible('@viewer-close')
        ->assertNoJavaScriptErrors();

    expect(Reaction::query()->sole()->user_id)->toBe($ana->id)
        ->and(Comment::query()->sole()->body)->toBe('Sos un crack');
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

it('sends feedback from the floating button', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create();

    $this->actingAs($user);

    visit('/')->on()->iPhone15Pro()
        ->assertMissing('@feedback-body')
        ->click('@open-feedback')
        ->wait(1)
        ->type('@feedback-body', 'La cámara no abre')
        ->click('@send-feedback')
        ->wait(1)
        ->assertSee('Gracias')
        ->assertNoJavaScriptErrors();

    $feedback = $user->feedback()->sole();

    expect($feedback->body)->toBe('La cámara no abre')
        // The layout hands the component the screen it is drawing, and "Hoy"
        // is the root path.
        ->and($feedback->page)->toBe('/');
});

it('writes what the member dictates into the feedback sheet', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create();

    $this->actingAs($user);

    // A stand-in for the phone's speech engine, because a headless Chromium
    // has the API and no microphone behind it. It answers a `start()` with one
    // settled phrase and then keeps listening, which is the state the button
    // has to survive: everything under test is on this side of the engine.
    $engine = <<<'JS'
        window.SpeechRecognition = window.webkitSpeechRecognition = class {
            start() {
                setTimeout(() => {
                    this.onresult({
                        results: [
                            { isFinal: true, 0: { transcript: 'la cámara no abre' } },
                        ],
                    });
                }, 20);
            }

            stop() {
                this.onend();
            }
        };

        // Playwright calls back whatever the script evaluates to, and a class
        // is callable enough to try. Anything else ends it.
        'installed';
    JS;

    // Opened in one chain, up to and including the assertion that the sheet is
    // really there: a `click()` left on its own line has nothing waiting on it,
    // and the script below would land on a page that has not opened yet.
    $page = visit('/')->on()->iPhone15Pro()
        ->click('@open-feedback')
        ->wait(1)
        ->assertVisible('@feedback-body');

    expect($page->script($engine))->toBe('installed');

    $page->click('@dictate')
        ->wait(1)
        ->assertValue('@feedback-body', 'la cámara no abre')
        ->assertAriaAttribute('@dictate', 'pressed', 'true')
        ->assertSeeIn('@dictate', 'Escuchando')
        // Tapping it again is what stops the microphone, and the label follows
        // the engine rather than the tap.
        ->click('@dictate')
        ->wait(1)
        ->assertAriaAttribute('@dictate', 'pressed', 'false')
        ->assertSeeIn('@dictate', 'Hablar')
        ->click('@send-feedback')
        ->wait(1)
        ->assertSee('Gracias')
        ->assertNoJavaScriptErrors();

    expect($user->feedback()->sole()->body)->toBe('la cámara no abre');
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

/**
 * The synthetic touch driver the pull tests share.
 *
 * Playwright's own drag is a mouse and emits no touch events at all, so the
 * gesture is built by hand. `fire()` answers whether the page took the move —
 * `dispatchEvent` comes back false when something called `preventDefault()` —
 * which is the only way to see ownership from outside the component, and the
 * thing the whole gesture now turns on.
 *
 * Note what these tests do NOT do: call `visit()` and then reach for the
 * result again later. Every call on what `on()` returns builds a fresh page
 * (`Api\On::__call`), so a gesture driven on one and asserted on the next
 * proves nothing. Binding the webpage from the first chained call is what
 * keeps all of it on one page.
 */
function touchDriver(): string
{
    return <<<'DRIVER'
        const root = document.querySelector('[data-test="pull-to-refresh"]');

        const fire = (target, type, x, y) => {
            const touch = new Touch({ identifier: 1, target, clientX: x, clientY: y });
            const held = type === 'touchend' ? [] : [touch];

            return ! target.dispatchEvent(new TouchEvent(type, {
                bubbles: true,
                cancelable: true,
                touches: held,
                targetTouches: held,
                changedTouches: [touch],
            }));
        };

        const drag = (target, from, to) => {
            const taken = [];

            for (let y = from; y <= to; y += 20) taken.push(fire(target, 'touchmove', 40, y));

            return taken;
        };

        // Alpine flushes `x-show` in a microtask, so a script that returns the
        // moment the gesture ends leaves the indicator's old `display` behind.
        // Every assertion about what is on screen waits for this — including
        // the ones asserting nothing appeared, which would otherwise pass on a
        // DOM that had simply not caught up yet.
        const settle = () => new Promise((resolve) =>
            requestAnimationFrame(() => requestAnimationFrame(resolve)),
        );

        // The refresh is a round trip, and a fixed sleep is either flaky or
        // slow. Three seconds is the ceiling because Playwright gives an
        // `evaluate` five.
        const until = async (predicate) => {
            for (let tick = 0; tick < 30 && ! predicate(); tick++) {
                await new Promise((resolve) => setTimeout(resolve, 100));
            }

            return predicate();
        };
        DRIVER;
}

/**
 * The pull that reloads the screen.
 *
 * Synthetic touches cost the browser's own decision to hand the drag to the
 * scroller — the part `overscroll-behavior-y` and the `preventDefault()` on
 * the first move exist to survive. What they buy is the rest, and it is
 * asserted the way a member sees it: the same page comes back carrying
 * somebody else's mark, which only the server could have told it about.
 *
 * The feed is what the pull is asked for, and it is also the only thing on
 * this screen that a pull can bring down: `$refresh` re-renders the page
 * component, Livewire skips children on a parent render, and the feed is the
 * one child `end()` nudges by hand. A member's own goal cards are `goal-card`
 * components and stay as they were.
 */
it("brings somebody else's newest mark down when the page is pulled", function (): void {
    $ana = User::factory()->create(['name' => 'Ana Pérez']);
    $bruno = User::factory()->create(['name' => 'Bruno']);

    Goal::factory()->for($ana)->create(['name' => 'Gimnasio']);

    $this->actingAs($ana);

    $page = visit('/')->on()->iPhone15Pro()->assertSee('Gimnasio');

    $page->assertDontSee('Guitarra')->assertMissing('@pull-indicator');

    // Marked behind the screen's back: only a real re-render brings it down.
    Mark::factory()->for(Goal::factory()->for($bruno)->create(['name' => 'Guitarra']))->create();

    $driver = touchDriver();

    $landed = $page->script(<<<PULL
        (async () => {
            {$driver}

            fire(root, 'touchstart', 40, 100);
            drag(root, 120, 300);
            fire(root, 'touchend', 40, 300);

            const arrived = await until(() => root.textContent.includes('Guitarra'));

            await settle();

            return arrived;
        })()
    PULL);

    expect($landed)->toBeTrue();

    $page->assertSee('Guitarra')
        ->assertMissing('@pull-indicator')
        ->assertNoJavaScriptErrors();
});

/**
 * The slow pull.
 *
 * A finger that creeps off the top delivers its first `touchmove` before it
 * has travelled the slop, and that move is the only one the page can still
 * claim: measured in Chromium, everything after the first unclaimed move
 * arrives uncancelable and the browser is already scrolling. So the claim is
 * asserted on the small move, and the indicator separately — the two are what
 * the slop was conflating.
 */
it('claims the pull on the first move, before the finger has travelled', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    $this->actingAs($user);

    $page = visit('/')->on()->iPhone15Pro()->assertSee('Gimnasio');

    $driver = touchDriver();

    // 4px: under the 8px slop, and the whole gesture rides on this one move.
    $claimedTheCreep = $page->script(<<<CREEP
        (async () => {
            {$driver}

            fire(root, 'touchstart', 40, 100);

            const claimed = fire(root, 'touchmove', 40, 104);

            await settle();

            return claimed;
        })()
    CREEP);

    expect($claimedTheCreep)->toBeTrue();

    // Claimed, but a tap's jitter must not light the indicator up.
    $page->assertMissing('@pull-indicator');

    $page->script(<<<PULL
        (async () => {
            {$driver}

            drag(root, 120, 260);

            await settle();
        })()
    PULL);

    $page->assertVisible('@pull-indicator')
        ->assertNoJavaScriptErrors();
});

/** A drag that begins below the top of the page is somebody scrolling. */
it('leaves the gesture alone when the page is not at the top', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    $this->actingAs($user);

    $page = visit('/')->on()->iPhone15Pro()->assertSee('Gimnasio');

    $driver = touchDriver();

    $scrolled = $page->script(<<<SCROLLED
        (async () => {
            {$driver}

            // Tall enough to scroll, whatever the feed happens to hold. The
            // height is read back before the scroll so the new one is laid out
            // by the time it happens, and the scroll is instant on purpose:
            // <html> is `scroll-smooth`, and an animated one is still at
            // nought on the next line.
            root.style.minHeight = '400vh';
            root.offsetHeight;
            window.scrollTo({ top: 200, behavior: 'instant' });

            fire(root, 'touchstart', 40, 100);
            const taken = drag(root, 120, 300);

            await settle();

            return JSON.stringify({
                // Reported rather than assumed: a setup that failed to scroll
                // would otherwise look like a gesture correctly ignored.
                scrollTop: document.scrollingElement.scrollTop,
                tookAny: taken.some(Boolean),
            });
        })()
    SCROLLED);

    expect(json_decode((string) $scrolled, true))->toBe([
        'scrollTop' => 200,
        'tookAny' => false,
    ]);

    $page->assertMissing('@pull-indicator')
        ->assertNoJavaScriptErrors();
});

/**
 * A sheet is its own surface.
 *
 * Flux paints a modal in the top layer, but the `<dialog>` stays a descendant
 * of the screen, so every touch inside it bubbles down to the page's own
 * handler. Without the guard the standings sheet cannot be dragged without
 * reloading the page behind it.
 */
it('leaves the gesture to an open sheet', function (): void {
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    $this->actingAs($user);

    $page = visit('/')->on()->iPhone15Pro()->click('@open-standings');

    $page->wait(1);

    $driver = touchDriver();

    $inSheet = $page->script(<<<SHEET
        (async () => {
            {$driver}

            // Flux's own element, wrapping the <dialog> the guard looks for.
            const inside = document.querySelector('ui-modal dialog *');

            fire(inside, 'touchstart', 40, 100);
            const taken = drag(inside, 120, 300);

            await settle();

            return JSON.stringify({
                // Reported rather than assumed: a drag that never reached the
                // page handler would pass this test for the wrong reason.
                reachedThePage: root.contains(inside),
                tookAny: taken.some(Boolean),
            });
        })()
    SHEET);

    expect(json_decode((string) $inSheet, true))->toBe([
        'reachedThePage' => true,
        'tookAny' => false,
    ]);

    $page->assertMissing('@pull-indicator')
        ->assertNoJavaScriptErrors();
});

/**
 * The pulse strip is the one thing on this screen that scrolls sideways, and
 * it sits at the very top, where every pull begins. Claiming the first move
 * means claiming before the direction is obvious, so the direction has to be
 * decided in that same move or a swipe along the strip is blocked by a pull
 * that was never intended.
 */
it('leaves a sideways swipe to the pulse strip', function (): void {
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    $this->actingAs($user);

    $page = visit('/')->on()->iPhone15Pro()->assertSee('Gimnasio');

    $driver = touchDriver();

    $swipe = $page->script(<<<SWIPE
        (async () => {
            {$driver}

            const avatar = document.querySelector('[data-test^="pulse-"]');

            fire(avatar, 'touchstart', 300, 40);

            // Sideways, with the drift a real thumb leaves down the screen.
            const taken = [];

            for (let step = 1; step <= 8; step++) {
                taken.push(fire(avatar, 'touchmove', 300 - step * 25, 40 + step * 1.5));
            }

            await settle();

            return JSON.stringify({
                reachedThePage: root.contains(avatar),
                tookAny: taken.some(Boolean),
            });
        })()
    SWIPE);

    expect(json_decode((string) $swipe, true))->toBe([
        'reachedThePage' => true,
        'tookAny' => false,
    ]);

    $page->assertMissing('@pull-indicator')
        ->assertNoJavaScriptErrors();
});

/**
 * The photo does not hand itself over.
 *
 * Only a real browser can answer this: what is under test is a cancelled
 * event and a computed style, and neither exists in a rendered string. The
 * long press that opens iOS's own save sheet has no event behind it at all —
 * `-webkit-touch-callout` is what turns it off, and Chromium does not
 * implement the property, so this proves the half that is provable here.
 */
it('refuses the gestures that would save a photo', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Correr']);
    $mark = Mark::factory()->for($goal)->for($user)->withPhoto()->create(['note' => 'Diez kilómetros']);

    $this->actingAs($user);

    // The photo is on the page before the script asks for it, the way every
    // other test here waits: an assertion, rather than a branch inside the JS.
    $page = visit('/')->on()->iPhone15Pro()->assertVisible('@viewer-open-'.$mark->id);

    $refused = $page->script(<<<'REFUSED'
        (() => {
            const photo = document.querySelector('picture img');

            // `dispatchEvent` answers false once something has cancelled it.
            const refuses = (type) =>
                ! photo.dispatchEvent(new Event(type, { bubbles: true, cancelable: true }));

            // The one target that is not an element: Gecko hands `dragstart`
            // the text node when a selection is dragged, and the note under a
            // card is selectable. Dispatching from the node itself is the
            // whole case — a listener that throws here is reported to the
            // page rather than to `dispatchEvent`, so the error assertion
            // below is what catches it.
            const note = document.querySelector('.select-text');
            const text = note && document.createTreeWalker(note, NodeFilter.SHOW_TEXT).nextNode();

            text?.dispatchEvent(new Event('dragstart', { bubbles: true, cancelable: true }));

            return JSON.stringify({
                menu: refuses('contextmenu'),
                drag: refuses('dragstart'),
                select: getComputedStyle(photo).userSelect,
                draggedText: text !== null,
            });
        })()
    REFUSED);

    expect(json_decode((string) $refused, true))->toBe([
        'menu' => true,
        'drag' => true,
        'select' => 'none',
        'draggedText' => true,
    ]);

    $page->assertNoJavaScriptErrors();
});
