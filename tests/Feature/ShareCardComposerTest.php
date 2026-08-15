<?php

declare(strict_types=1);

use App\Enums\ShareCardFormat;
use App\Services\ShareCardComposer;
use App\ValueObjects\ShareCard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * The picture that lands in the chat.
 *
 * These assert the shape and the plumbing rather than the art: that both
 * formats come out at the size WhatsApp needs, that a card renders with and
 * without a photo, and that the fonts are actually in the repository — the one
 * failure that would only ever show up on a deployed server.
 */

/** The real dimensions of whatever the composer just encoded. */
function composedSize(string $jpeg): array
{
    $size = getimagesizefromstring($jpeg);

    expect($size)->toBeArray();

    return ['width' => $size[0], 'height' => $size[1], 'mime' => $size['mime']];
}

function sampleCard(): ShareCard
{
    return new ShareCard(
        title: 'Gimnasio',
        badge: null,
        byline: '14 de agosto',
        streak: 12,
    );
}

/**
 * Whether any pixel in the card's last column is bright enough to be text.
 *
 * The ground is charcoal and the bloom is a dark ember, so the only thing on
 * a card that comes out near-white is a line of words — and a line of words
 * reaching the edge is one that has run off it.
 */
function touchesRightEdge(string $jpeg): bool
{
    $image = imagecreatefromstring($jpeg);

    expect($image)->not->toBeFalse();

    $x = imagesx($image) - 1;

    for ($y = 0; $y < imagesy($image); $y++) {
        $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        if ($pixel['red'] > 200 && $pixel['green'] > 200 && $pixel['blue'] > 200) {
            return true;
        }
    }

    return false;
}

/**
 * How much of the byline's line is drawn in the ember the streak wears.
 *
 * Only the bottom fifth is counted, which on a card with no stats is the
 * byline and nothing else. Nothing else down there comes near this colour:
 * the ground is charcoal, the brightest ring of the bloom is a dark brown,
 * and the date beside the streak is white at 72%.
 */
function emberPixels(string $jpeg): int
{
    $image = imagecreatefromstring($jpeg);

    expect($image)->not->toBeFalse();

    $height = imagesy($image);
    $found = 0;

    for ($y = (int) ($height * 0.8); $y < $height; $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));

            // Wide, because JPEG at quality 88 smears the edge of a glyph, and
            // because the flame's orange and the digits' ember are two shades
            // of the same thing.
            if ($pixel['red'] > 190 && $pixel['green'] > 90 && $pixel['green'] < 210 && $pixel['blue'] < 150) {
                $found++;
            }
        }
    }

    return $found;
}

it('draws the unfurl at the ratio that gets a large preview', function (): void {
    $jpeg = resolve(ShareCardComposer::class)->compose(ShareCardFormat::Unfurl, sampleCard());

    // 1200x630 is what WhatsApp and friends draw big. Anything squarer falls
    // back to the thumbnail tile beside the text, which is the whole problem
    // this feature exists to fix.
    expect(composedSize($jpeg))->toBe(['width' => 1200, 'height' => 630, 'mime' => 'image/jpeg']);
});

it('draws the portrait card for sending the image itself', function (): void {
    $jpeg = resolve(ShareCardComposer::class)->compose(ShareCardFormat::Portrait, sampleCard());

    expect(composedSize($jpeg))->toBe(['width' => 1080, 'height' => 1350, 'mime' => 'image/jpeg']);
});

it('covers the card with the photo when there is one', function (): void {
    // Held in a variable on purpose: a fake upload deletes its temporary file
    // when the object goes out of scope, so inlining this reads a path that no
    // longer exists by the time File::get() runs.
    $upload = UploadedFile::fake()->image('proof.jpg', 900, 1600);
    $photo = (string) File::get($upload->getRealPath());

    $jpeg = resolve(ShareCardComposer::class)->compose(ShareCardFormat::Unfurl, sampleCard(), $photo);

    // A tall phone photo still comes out the card's shape: letterboxing reads
    // as a broken image in a chat preview.
    expect(composedSize($jpeg))->toBe(['width' => 1200, 'height' => 630, 'mime' => 'image/jpeg']);
});

it('actually draws the streak and the flame', function (): void {
    // The one thing on the card nobody could see going missing from a test that
    // only measures the frame: a card whose streak never reached the canvas
    // encodes to the same 1200x630 JPEG as one whose streak did.
    $withStreak = resolve(ShareCardComposer::class)->compose(ShareCardFormat::Unfurl, sampleCard());

    $withoutStreak = resolve(ShareCardComposer::class)->compose(
        ShareCardFormat::Unfurl,
        new ShareCard(title: 'Gimnasio', badge: null, byline: '14 de agosto'),
    );

    expect(emberPixels($withStreak))->toBeGreaterThan(200)
        ->and(emberPixels($withoutStreak))->toBeLessThan(20);
});

it('renders a card with no badge and no stats', function (): void {
    $jpeg = resolve(ShareCardComposer::class)->compose(
        ShareCardFormat::Unfurl,
        new ShareCard(title: 'Leer', badge: null, byline: '3 de mayo'),
    );

    expect(composedSize($jpeg)['width'])->toBe(1200);
});

it('shrinks a title that would otherwise run off the card', function (): void {
    // Forty characters is exactly what a goal name is allowed, and Anton at
    // the size a short one wants puts the tail of this one past the edge.
    $jpeg = resolve(ShareCardComposer::class)->compose(
        ShareCardFormat::Unfurl,
        new ShareCard(
            title: 'Natación por la mañana antes del trabajo',
            badge: null,
            byline: '3 de mayo',
        ),
    );

    expect(touchesRightEdge($jpeg))->toBeFalse()
        ->and(composedSize($jpeg)['width'])->toBe(1200);
});

it('renders the recap stats row', function (): void {
    $jpeg = resolve(ShareCardComposer::class)->compose(
        ShareCardFormat::Unfurl,
        new ShareCard(
            title: 'Agosto 2026',
            badge: 'Cerró el mes',
            byline: 'Ganó Franco',
            stats: ['Campeón' => 'Franco', 'Del mes' => '92%', 'Mejor racha' => '23 días · Guido'],
        ),
    );

    expect(composedSize($jpeg)['width'])->toBe(1200);
});

it('falls back to the plain ground when the photo will not decode', function (): void {
    // The card route is fetched by a link crawler building a preview. A card
    // without its photo is worth far more there than a 500, which would cost
    // the whole unfurl over one bad derivative.
    $jpeg = resolve(ShareCardComposer::class)->compose(
        ShareCardFormat::Unfurl,
        sampleCard(),
        'this is not an image',
    );

    expect(composedSize($jpeg))->toBe(['width' => 1200, 'height' => 630, 'mime' => 'image/jpeg']);
});

it('ships everything it draws with', function (): void {
    // FreeType cannot read a woff2 and @fontsource ships nothing else, and GD
    // has no colour-glyph support so the flame has to arrive as pixels. All
    // four are committed. Losing one breaks every share card and nothing else,
    // which is exactly the kind of break nobody notices locally.
    expect(File::exists(resource_path('fonts/Anton-Regular.ttf')))->toBeTrue()
        ->and(File::exists(resource_path('fonts/Archivo-Regular.ttf')))->toBeTrue()
        ->and(File::exists(resource_path('fonts/Archivo-Bold.ttf')))->toBeTrue()
        ->and(File::exists(resource_path('images/flame.png')))->toBeTrue();
});

it('survives an accent, which is most of the Spanish it draws', function (): void {
    $jpeg = resolve(ShareCardComposer::class)->compose(
        ShareCardFormat::Unfurl,
        new ShareCard(
            title: 'Natación',
            badge: null,
            byline: '3 de diciembre',
            streak: 21,
        ),
    );

    expect(composedSize($jpeg)['mime'])->toBe('image/jpeg');
});
