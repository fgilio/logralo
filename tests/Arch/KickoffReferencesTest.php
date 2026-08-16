<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The kickoff skill ships copies, not links.
 *
 * `.claude/skills/logralo-kickoff/references/` is self-contained on purpose —
 * the skill gets lifted into a brand-new repo, where a symlink back into this
 * one would dangle. Most of what lives there is a baseline the next project is
 * meant to outgrow, but these four are verbatim copies of scripts that still
 * run here, and a stale copy fails silently: the next kickoff hands a new repo
 * a bootstrap whose bug was fixed months ago. Nothing else compares them.
 */
it('keeps the kickoff skill scripts identical to the ones this repo runs', function (string $reference, string $live): void {
    expect(File::get(base_path(".claude/skills/logralo-kickoff/references/{$reference}")))
        ->toBe(File::get(base_path($live)));
})->with([
    ['cloud-cli.sh', 'scripts/cloud-cli.sh'],
    ['cloud-setup.sh', 'scripts/cloud/setup.sh'],
    ['cloud-link.sh', 'scripts/cloud-link.sh'],
    ['session-start.sh', '.claude/hooks/session-start.sh'],
]);
