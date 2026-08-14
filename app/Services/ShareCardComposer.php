<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShareCardFormat;
use App\ValueObjects\ShareCard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Geometry\Factories\EllipseFactory;
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

    /**
     * How far up the scrim reaches, as a fraction of height and a fraction of
     * width. The narrower of the two wins, so the tall portrait card gets a
     * scrim in proportion to its text rather than one gradual wash over half
     * the photo.
     */
    private const float SCRIM_OF_HEIGHT = 0.62;

    private const float SCRIM_OF_WIDTH = 0.55;

    /**
     * Rings in the ember bloom behind a card that has no photo. High, because
     * each ellipse has a hard edge and two dozen of them read as tree rings.
     */
    private const int BLOOM_STEPS = 96;

    /** The centre of the bloom: the ground, pushed towards the ember. */
    private const string BLOOM_PEAK = '#3d1f0c';

    public function __construct(private ImageManager $images) {}

    /**
     * @param  string|null  $photo  the raw feed JPEG, or null for a ghost mark
     *                              or a month recap, which have no photo
     */
    public function compose(ShareCardFormat $format, ShareCard $card, ?string $photo = null): string
    {
        $width = $format->width();
        $height = $format->height();

        $canvas = $this->canvas($photo, $width, $height);

        $this->scrim($canvas, $width, $height);
        $this->words($canvas, $card, $width, $height);

        return (string) $canvas->encode(
            new JpegEncoder(quality: 88, progressive: true, strip: true)
        );
    }

    /**
     * What the words are drawn on.
     *
     * `cover` rather than `contain`: a letterboxed photo in a chat preview
     * reads as a broken image, and losing the edges of a gym selfie costs
     * nothing.
     *
     * A photo that will not decode falls back to the plain ground rather than
     * raising. This is fetched by a link crawler building a preview, and a
     * card without the photo is worth far more there than a 500 — which would
     * cost the unfurl entirely, for the one derivative that failed.
     */
    private function canvas(?string $photo, int $width, int $height): ImageInterface
    {
        if ($photo === null) {
            return $this->ground($width, $height);
        }

        try {
            return $this->images->decodeBinary($photo)->cover($width, $height)->brightness(-8);
        } catch (Throwable) {
            return $this->ground($width, $height);
        }
    }

    /**
     * The no-photo ground: charcoal with an ember bloom behind the words,
     * the same one the recap card wears in the feed.
     */
    private function ground(int $width, int $height): ImageInterface
    {
        $canvas = $this->images->createImage($width, $height)->fill(self::GROUND);

        // Concentric ellipses, largest first, each an opaque step along a ramp
        // from the ground to the ember.
        //
        // Opaque rather than a stack of translucent ones: alpha compositing
        // the same colour a hundred times saturates the red channel long
        // before the others and the glow comes out maroon with a green fringe.
        // Interpolating the colour keeps the hue exactly where it was put, and
        // the outermost ring is the ground itself, so the bloom has no edge.
        $radius = (int) ($width * 0.55);
        $centerX = (int) ($width * 0.82);
        $centerY = (int) ($height * 0.12);

        for ($step = self::BLOOM_STEPS; $step > 0; $step--) {
            $distance = $step / self::BLOOM_STEPS;
            $size = (int) ($radius * $distance);

            // Eased, so the bloom has a bright core rather than a flat disc.
            $colour = $this->mix(self::GROUND, self::BLOOM_PEAK, (1 - $distance) ** 1.8);

            $canvas->drawEllipse(function (EllipseFactory $ellipse) use ($size, $colour, $centerX, $centerY): void {
                $ellipse->at($centerX, $centerY);
                $ellipse->size($size * 2, $size * 2);
                $ellipse->background($colour);
            });
        }

        return $canvas;
    }

    /**
     * The darkening under the text, so a bright photo never eats the words.
     *
     * One row at a time. Painting it in bands of a few pixels was cheaper and
     * left visible stripes: the band edges do not line up with the rounding,
     * and a chat preview is exactly the size where that reads as a corrupt
     * image. The alpha grows on a curve rather than linearly, so there is no
     * hard line where the scrim begins either.
     */
    private function scrim(ImageInterface $canvas, int $width, int $height): void
    {
        $scrimHeight = (int) min($height * self::SCRIM_OF_HEIGHT, $width * self::SCRIM_OF_WIDTH);
        $top = $height - $scrimHeight;

        for ($y = $top; $y < $height; $y++) {
            $progress = ($y - $top) / max($scrimHeight - 1, 1);
            $alpha = round(0.97 * $progress ** 1.2, 4);

            $canvas->drawRectangle(function (RectangleFactory $rectangle) use ($width, $y, $alpha): void {
                $rectangle->at(0, $y);
                $rectangle->size($width, 1);
                $rectangle->background("rgba(0, 0, 0, {$alpha})");
            });
        }
    }

    /** Badge, title, byline and stats, stacked up from the bottom edge. */
    private function words(ImageInterface $canvas, ShareCard $card, int $width, int $height): void
    {
        $pad = (int) ($width * 0.058);
        $titleSize = (int) ($width * 0.075);
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

        // Anton's caps fill the whole em and the accented ones overshoot it,
        // so a single line of clearance puts Í straight through the badge.
        $baseline -= (int) ($titleSize * 1.38);

        if ($card->badge !== null) {
            $this->write($canvas, Str::upper($card->badge), $pad, $baseline, $badgeSize, self::EMBER, $this->display(), tracking: 3);
            $this->rule($canvas, $pad, $baseline - (int) ($badgeSize * 1.5), $width);
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

    /** One point along a straight line between two hex colours. */
    private function mix(string $from, string $to, float $amount): string
    {
        $channels = collect([0, 1, 2])->map(function (int $channel) use ($from, $to, $amount): string {
            $start = (int) hexdec(Str::substr($from, 1 + $channel * 2, 2));
            $end = (int) hexdec(Str::substr($to, 1 + $channel * 2, 2));

            return Str::padLeft(dechex((int) round($start + ($end - $start) * $amount)), 2, '0');
        });

        return '#'.$channels->implode('');
    }

    /** The short ember bar over the badge. Brand, for the price of a rectangle. */
    private function rule(ImageInterface $canvas, int $x, int $y, int $width): void
    {
        $canvas->drawRectangle(function (RectangleFactory $rectangle) use ($x, $y, $width): void {
            $rectangle->at($x, $y);
            $rectangle->size((int) ($width * 0.05), max((int) ($width * 0.004), 1));
            $rectangle->background(self::EMBER);
        });
    }

    /** "LOGRALO", top right, so the card is recognisable at thumbnail size. */
    private function wordmark(ImageInterface $canvas, int $width, int $pad): void
    {
        $size = (int) ($width * 0.026);

        $canvas->text('LOGRALO', $width - $pad, $pad + $size, function (FontFactory $font) use ($size): void {
            $font->filename($this->display());
            $font->size($size);
            $font->color('rgba(255, 255, 255, 0.82)');
            $font->align('right', 'bottom');
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
                $factory->align('left', 'bottom');
            });

            return;
        }

        foreach (mb_str_split($text) as $character) {
            $canvas->text($character, $x, $y, function (FontFactory $factory) use ($size, $color, $font): void {
                $factory->filename($font);
                $factory->size($size);
                $factory->color($color);
                $factory->align('left', 'bottom');
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

        throw_if(! File::exists($path), RuntimeException::class, "Missing share card font: {$name}");

        return $path;
    }
}
