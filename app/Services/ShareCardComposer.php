<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShareCardFormat;
use App\Exceptions\PhotoUnreadableException;
use App\ValueObjects\ShareCard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use RuntimeException;
use Throwable;

/**
 * The picture that lands in the WhatsApp chat.
 *
 * A link on its own unfurls as whatever the page's `og:image` points at, so
 * this is the difference between "🔥 Ginasio — Logralo" over a grey login tile
 * and a full-bleed photo with the streak burned into the corner. It is the one
 * artefact of this app that people outside the group ever see.
 *
 * Drawn with GD through Intervention, deliberately, rather than by
 * screenshotting a Blade view: headless Chrome would mean shipping a Chromium
 * binary, and a Chromium binary means a build that downloads 150 MB from the
 * network — which is what `docs/architecture/laravel-cloud-production.md`
 * forbids and `tests/Arch/BuildTest.php` guards after the font-CDN incident.
 * The cost is that layout is arithmetic and emoji do not exist here.
 *
 * Everything scales off the card's width, so the wide unfurl and the tall
 * portrait share one layout and differ only in how much photo sits above the
 * words.
 */
final readonly class ShareCardComposer
{
    /** The warm charcoal the app sits on, for cards with no photo. */
    private const string GROUND = '#0f0a07';

    private const string EMBER = '#ff9845';

    /** How much of the card's height the scrim under the text covers. */
    private const float SCRIM_FRACTION = 0.62;

    /** Bands the scrim is painted in. Enough that no edge is visible. */
    private const int SCRIM_BANDS = 72;

    public function __construct(private ImageManager $images) {}

    /**
     * @param  string|null  $photo  the raw feed JPEG, or null for a ghost mark
     *                              or a month recap, which have no photo
     *
     * @throws PhotoUnreadableException when the photo handed in cannot be
     *                                  decoded on this host
     */
    public function compose(ShareCardFormat $format, ShareCard $card, ?string $photo = null): string
    {
        $width = $format->width();
        $height = $format->height();

        $canvas = $photo === null
            ? $this->ground($width, $height)
            : $this->photograph($photo, $width, $height);

        $this->scrim($canvas, $width, $height);
        $this->words($canvas, $card, $width, $height);

        return (string) $canvas->encode(
            new JpegEncoder(quality: 88, progressive: true, strip: true)
        );
    }

    /**
     * A photo, cropped to the card and dimmed.
     *
     * `cover` rather than `contain`: a letterboxed photo in a chat preview
     * reads as a broken image, and losing the edges of a gym selfie costs
     * nothing.
     */
    private function photograph(string $photo, int $width, int $height): ImageInterface
    {
        try {
            $image = $this->images->read($photo);
        } catch (Throwable $throwable) {
            throw new PhotoUnreadableException('share card', $throwable);
        }

        return $image->cover($width, $height)->brightness(-8);
    }

    /**
     * The no-photo ground: charcoal with an ember bloom behind the words,
     * the same one the recap card wears in the feed.
     */
    private function ground(int $width, int $height): ImageInterface
    {
        $canvas = $this->images->create($width, $height)->fill(self::GROUND);

        // Concentric ellipses at a low alpha, because GD has no gradient.
        // Painted largest first so the centre accumulates the most passes.
        $radius = (int) ($width * 0.55);
        $centerX = (int) ($width * 0.82);
        $centerY = (int) ($height * 0.12);

        for ($step = 24; $step > 0; $step--) {
            $size = (int) ($radius * ($step / 24));

            $canvas->drawEllipse($centerX, $centerY, function ($ellipse) use ($size): void {
                $ellipse->size($size * 2, $size * 2);
                $ellipse->background('rgba(255, 152, 69, 0.028)');
            });
        }

        return $canvas;
    }

    /**
     * The darkening under the text, so a bright photo never eats the words.
     *
     * Painted as bands whose alpha grows on a square curve: linear bands leave
     * a visible hard edge where the scrim begins, a curve does not.
     */
    private function scrim(ImageInterface $canvas, int $width, int $height): void
    {
        $scrimHeight = (int) ($height * self::SCRIM_FRACTION);
        $bandHeight = (int) ceil($scrimHeight / self::SCRIM_BANDS);
        $top = $height - $scrimHeight;

        for ($band = 0; $band < self::SCRIM_BANDS; $band++) {
            $progress = $band / (self::SCRIM_BANDS - 1);
            $alpha = round(0.92 * $progress ** 2, 4);

            $canvas->drawRectangle(0, $top + $band * $bandHeight, function (RectangleFactory $rectangle) use ($width, $bandHeight, $alpha): void {
                $rectangle->size($width, $bandHeight);
                $rectangle->background("rgba(0, 0, 0, {$alpha})");
            });
        }
    }

    /** Badge, title, byline and stats, stacked up from the bottom edge. */
    private function words(ImageInterface $canvas, ShareCard $card, int $width, int $height): void
    {
        $pad = (int) ($width * 0.058);
        $titleSize = (int) ($width * 0.072);
        $badgeSize = (int) ($width * 0.028);
        $bylineSize = (int) ($width * 0.024);

        $baseline = $height - $pad;

        if ($card->stats !== []) {
            $baseline = $this->stats($canvas, $card->stats, $pad, $baseline, $width);
        }

        $this->write($canvas, $card->byline, $pad, $baseline, $bylineSize, 'rgba(255, 255, 255, 0.72)', $this->body());
        $baseline -= (int) ($bylineSize * 1.9);

        // Anton is drawn uppercase because it has no lowercase worth reading at
        // this size, and because a shout is the point.
        $this->write($canvas, Str::upper($card->title), $pad, $baseline, $titleSize, '#ffffff', $this->display());
        $baseline -= (int) ($titleSize * 1.08);

        if ($card->badge !== null) {
            $this->write($canvas, Str::upper($card->badge), $pad, $baseline, $badgeSize, self::EMBER, $this->display(), tracking: 3);
        }

        $this->wordmark($canvas, $width, $pad);
    }

    /**
     * The month recap's podium: small stacked label/value blocks along the
     * bottom. Returns the baseline the rest of the text should sit above.
     *
     * @param  array<string, string>  $stats
     */
    private function stats(ImageInterface $canvas, array $stats, int $pad, int $baseline, int $width): int
    {
        $labelSize = (int) ($width * 0.018);
        $valueSize = (int) ($width * 0.03);
        $column = (int) (($width - $pad * 2) / max(count($stats), 1));
        $x = $pad;

        foreach ($stats as $label => $value) {
            $this->write($canvas, $value, $x, $baseline, $valueSize, '#ffffff', $this->bodyBold());
            $this->write($canvas, Str::upper($label), $x, $baseline - (int) ($valueSize * 1.5), $labelSize, 'rgba(255, 255, 255, 0.45)', $this->body(), tracking: 2);

            $x += $column;
        }

        return $baseline - (int) ($valueSize * 1.5) - (int) ($labelSize * 2.6);
    }

    /** "LOGRALO", top right, so the card is recognisable at thumbnail size. */
    private function wordmark(ImageInterface $canvas, int $width, int $pad): void
    {
        $size = (int) ($width * 0.026);

        $canvas->text('LOGRALO', $width - $pad, $pad + $size, function (FontFactory $font) use ($size): void {
            $font->filename($this->display());
            $font->size($size);
            $font->color('rgba(255, 255, 255, 0.82)');
            $font->align('right');
            $font->valign('bottom');
        });
    }

    /**
     * One line of text on its baseline.
     *
     * Letter-spacing is drawn by hand — GD has no tracking — so a tracked line
     * costs one `text()` call per character. Only the two small uppercase lines
     * use it, where the spacing is what makes them read as labels.
     */
    private function write(
        ImageInterface $canvas,
        string $text,
        int $x,
        int $y,
        int $size,
        string $color,
        string $font,
        int $tracking = 0,
    ): void {
        if ($tracking === 0) {
            $canvas->text($text, $x, $y, function (FontFactory $factory) use ($size, $color, $font): void {
                $factory->filename($font);
                $factory->size($size);
                $factory->color($color);
                $factory->align('left');
                $factory->valign('bottom');
            });

            return;
        }

        foreach (mb_str_split($text) as $character) {
            $canvas->text($character, $x, $y, function (FontFactory $factory) use ($size, $color, $font): void {
                $factory->filename($font);
                $factory->size($size);
                $factory->color($color);
                $factory->align('left');
                $factory->valign('bottom');
            });

            $x += $this->advance($character, $size, $font) + $tracking;
        }
    }

    /** How far the pen moves after drawing one character, in pixels. */
    private function advance(string $character, int $size, string $font): int
    {
        $box = imagettfbbox($size, 0, $font, $character);

        return $box === false ? $size : (int) ($box[2] - $box[0]);
    }

    private function display(): string
    {
        return $this->font('Anton-Regular.ttf');
    }

    private function body(): string
    {
        return $this->font('Archivo-Regular.ttf');
    }

    private function bodyBold(): string
    {
        return $this->font('Archivo-Bold.ttf');
    }

    /**
     * The fonts are committed rather than pulled from node_modules: @fontsource
     * ships woff2 only, and FreeType — which is what actually draws these —
     * cannot read a woff2.
     */
    private function font(string $name): string
    {
        $path = resource_path('fonts/'.$name);

        if (! File::exists($path)) {
            throw new RuntimeException("Missing share card font: {$name}");
        }

        return $path;
    }
}
