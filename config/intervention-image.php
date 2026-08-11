<?php

declare(strict_types=1);

use Intervention\Image\Drivers\Gd\Driver;

return [

    /*
    |---------------------------------------------------------------------------
    | Image Driver
    |---------------------------------------------------------------------------
    |
    | GD is present on every PHP build, which is what makes it the safe default
    | for a deploy target we do not control. The one thing it cannot read is
    | HEIC — and iOS transcodes HEIC to JPEG on its way through a file input,
    | so that only bites a desktop Safari upload. Set IMAGE_DRIVER to the
    | Imagick driver on a host whose ImageMagick has the libheif delegate.
    |
    */

    'driver' => env('IMAGE_DRIVER', Driver::class),

    'options' => [
        // Rotate from EXIF on decode, so an iPhone photo is stored upright.
        'autoOrientation' => true,
        // Goal photos are stills; skip animation decoding entirely.
        'decodeAnimation' => false,
        'backgroundColor' => 'ffffff',
        // Never ship someone's GPS coordinates to the group feed.
        'strip' => true,
    ],

];
