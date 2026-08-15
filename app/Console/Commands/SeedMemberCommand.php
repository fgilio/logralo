<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\IssueMagicLink;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Adds a member to the group and prints the link to send them.
 *
 * This is the whole onboarding flow: there is no registration page and no
 * invite screen. Re-running it for the same email rotates the token, which
 * kills any link already sent.
 */
#[Signature("logralo:seed-member {name : The member's display name} {email} {timezone? : IANA timezone, defaults to the configured one}")]
#[Description('Create or update a member and print their WhatsApp magic link')]
final class SeedMemberCommand extends Command
{
    public function handle(IssueMagicLink $issueMagicLink): int
    {
        $timezone = (string) ($this->argument('timezone') ?? config('logralo.default_timezone'));

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $this->components->error("Unknown timezone [{$timezone}].");

            return self::FAILURE;
        }

        $email = Str::lower(mb_trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("Invalid email [{$email}].");

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => mb_trim((string) $this->argument('name')), 'timezone' => $timezone],
        );

        $link = $issueMagicLink->handle($user);

        $this->components->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." {$user->name} ({$user->email}, {$timezone}).");
        $this->newLine();
        $this->line('Send this over WhatsApp:');
        $this->newLine();
        // Not "Bienvenido": nothing here knows the member's gender, and this
        // line gets pasted into WhatsApp exactly as it is printed.
        $this->line("Te sumamos a Logralo, {$user->name} 🔥 Entrá con este enlace y elegí tu contraseña: {$link}");
        $this->newLine();

        return self::SUCCESS;
    }
}
