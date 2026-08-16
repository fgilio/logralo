<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The kickoff skill ships copies, not links.
 *
 * `.claude/skills/logralo-kickoff/references/` is self-contained on purpose —
 * the skill gets lifted into a brand-new repo, where a symlink back into this
 * one would dangle. Most of what lives there is a baseline the next project is
 * meant to outgrow, but these two are verbatim copies of scripts that still run
 * here, and a stale copy fails silently: the next kickoff hands a new repo a
 * script whose bug was fixed months ago. Nothing else compares them.
 *
 * The bootstrap pair — `cloud-setup.sh` and `session-start.sh` — was on this
 * list and came off it, because this repo outgrew them rather than because they
 * went stale. A hosted sandbox turned out to need a PHP newer than the image
 * ships and a `vendor/` the egress proxy will not let composer download, so
 * what runs here is now the module set in `scripts/cloud/`, coupled to a
 * CI-built snapshot of this repo. A brand-new repo has no such snapshot and no
 * workflow to build one, so pinning the kickoff to those files would hand it a
 * bootstrap that cannot work on its first day. The references stay the simple
 * pair, each carrying a header that says what this repo learned and when to
 * adopt it; `scripts/cloud/SETUP.md` is the full account.
 */
it('keeps the kickoff skill scripts identical to the ones this repo runs', function (string $reference, string $live): void {
    expect(File::get(base_path(".claude/skills/logralo-kickoff/references/{$reference}")))
        ->toBe(File::get(base_path($live)));
})->with([
    ['cloud-cli.sh', 'scripts/cloud-cli.sh'],
    ['cloud-link.sh', 'scripts/cloud-link.sh'],
]);
