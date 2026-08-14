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
        badge: '12 días seguidos',
        byline: 'Guido · 14 de agosto',
    );
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
    $photo = (string) File::get(UploadedFile::fake()->image('proof.jpg', 900, 1600)->getRealPath());

    $jpeg = resolve(ShareCardComposer::class)->compose(ShareCardFormat::Unfurl, sampleCard(), $photo);

    // A tall phone photo still comes out the card's shape: letterboxing reads
    // as a broken image in a chat preview.
    expect(composedSize($jpeg))->toBe(['width' => 1200, 'height' => 630, 'mime' => 'image/jpeg']);
});

it('renders a card with no badge and no stats', function (): void {
    $jpeg = resolve(ShareCardComposer::class)->compose(
        ShareCardFormat::Unfurl,
        new ShareCard(title: 'Leer', badge: null, byline: 'Franco'),
    );

    expect(composedSize($jpeg)['width'])->toBe(1200);
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

it('ships the fonts it draws with', function (): void {
    // FreeType cannot read a woff2, and @fontsource ships nothing else, so
    // these two are committed. Losing them breaks every share card and
    // nothing else, which is exactly the kind of break nobody notices locally.
    expect(File::exists(resource_path('fonts/Anton-Regular.ttf')))->toBeTrue()
        ->and(File::exists(resource_path('fonts/Archivo-Regular.ttf')))->toBeTrue()
        ->and(File::exists(resource_path('fonts/Archivo-Bold.ttf')))->toBeTrue();
});

it('survives an accent, which is most of the Spanish it draws', function (): void {
    $jpeg = resolve(ShareCardComposer::class)->compose(
        ShareCardFormat::Unfurl,
        new ShareCard(title: 'Natación', badge: '21 días seguidos', byline: 'Martín · 3 de diciembre'),
    );

    expect(composedSize($jpeg)['mime'])->toBe('image/jpeg');
});
