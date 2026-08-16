<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * One definition of what the app will take a picture as, shared by every
 * screen that accepts one.
 *
 * The format list is not a preference — it is what `PhotoProcessor::decode()`
 * can actually read on this host, which is why HEIC's fate is decided there
 * and announced here. Spelled out twice, the next format change lands on one
 * screen and a member is told their file is invalid on the other.
 */
trait PhotoValidationRules
{
    /** @return list<string> */
    protected function photoRules(): array
    {
        return [
            'file',
            'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
            'max:'.config('logralo.photos.max_upload_kilobytes'),
        ];
    }
}
