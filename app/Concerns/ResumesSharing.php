<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\ResumeSharing;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use Illuminate\Support\Facades\Auth;

/**
 * The "share it again" button, for the Livewire components that render feed
 * cards.
 *
 * It lives in a trait rather than on one component because the share button
 * appears in the feed and in the milestone celebration, and a revoked card
 * reachable from one but not the other is the kind of dead end nobody reports.
 */
trait ResumesSharing
{
    public function resumeSharing(string $kind, string $id): void
    {
        $shared = $kind === 'recap'
            ? MonthlyRecap::query()->findOrFail($id)
            : Mark::query()->findOrFail($id);

        // A mark belongs to whoever made it. A month belongs to the group, so
        // anyone in it may put that one back.
        abort_unless(
            $shared instanceof MonthlyRecap || $shared->user_id === Auth::id(),
            403,
        );

        resolve(ResumeSharing::class)->handle($shared);

        $this->dispatch('mark-updated');
    }
}
