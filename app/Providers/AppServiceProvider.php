<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Blaze\Blaze;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureDates();
        $this->configureModels();
        $this->configurePasswords();
        $this->configureRateLimiters();
        $this->configureBlade();
    }

    private function configureDates(): void
    {
        // Every cutoff in this app is date arithmetic. Immutable dates remove
        // a whole class of "who mutated my Carbon" bugs from it.
        Date::use(CarbonImmutable::class);
    }

    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    private function configurePasswords(): void
    {
        // The length and character rules apply everywhere, so the test suite
        // exercises the real policy. Only the breach check is production-only:
        // it needs an outbound request, and tests forbid stray requests.
        Password::defaults(function (): Password {
            $rule = Password::min(10)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    private function configureRateLimiters(): void
    {
        // Magic links travel through WhatsApp, so the limiter is there to cap
        // link scanning and to bound the damage of a leaked chat export.
        RateLimiter::for('magic-link', fn (Request $request): array => [
            Limit::perMinute(5)->by('magic-link-ip:'.$request->ip()),
            Limit::perHour(10)->by('magic-link-user:'.$request->route('user')),
        ]);

        // Share links are the app's only public surface, and a 24-character
        // token is the whole of their access control. The limit is generous
        // enough that a card forwarded around a family group never trips it,
        // and tight enough that nobody enumerates the space from one address.
        RateLimiter::for('share', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('share-ip:'.$request->ip()));
    }

    private function configureBlade(): void
    {
        // Blade components in this app are all anonymous and prop-driven, which
        // is exactly what Blaze compiles well. Livewire SFCs live elsewhere and
        // are untouched by it.
        Blaze::optimize()->in(resource_path('views/components'));
    }
}
