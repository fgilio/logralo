<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A mark either carries proof or it does not. Both keep a streak alive —
 * streaks reward showing up — but only a full mark scores a whole point.
 */
enum MarkKind: string
{
    case Full = 'full';
    case Ghost = 'ghost';
}
