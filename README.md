# Logralo

Daily goals with a streak counter and photo proof, built for one group of friends. Mark a goal with one tap, prove it with a photo, keep the flame alive — and let everyone see whether you did.

One screen: the group's pulse across the top, whatever is still open from yesterday, today's goal cards, and everyone's proof underneath.

- **Ghost marks.** You can always mark without a photo. It keeps your streak, scores half, and shows up in the feed as the goal's own emoji over "sin foto". After two in a row on the same goal, the next one goes with a camera.
- **Grace.** Yesterday stays open until noon, in your own timezone. Then it closes forever.
- **The month.** Completion of your own possible marks, so two goals can beat five. Ties share the podium, and the month-end recap card lands in the feed.

Laravel 13 · Livewire 4 · Flux Pro · Tailwind 4 · PostgreSQL on PlanetScale · deployed on Laravel Cloud.

## Running it

```bash
composer setup   # deps, .env, sqlite database, assets
composer dev     # server, queue worker, logs and vite
```

PHP 8.5 is required. If your default `php` is older, `./scripts/php` finds one that is not: `./scripts/php "$(command -v composer)" test`, `./scripts/php artisan migrate`.

`php artisan migrate:fresh --seed` fills the database with a believable month of group activity for local work.

Members are added one at a time — there is no registration page:

```bash
php artisan logralo:seed-member "Nombre" alguien@example.com America/Montevideo
```

It prints a signed link to send over WhatsApp. The link logs them in once and asks them to choose a password.

## Docs

- [`docs/mvp-v1-scope.md`](docs/mvp-v1-scope.md) — the product spec
- [`docs/architecture/day-boundaries.md`](docs/architecture/day-boundaries.md) — days, grace, streaks and scoring
- [`docs/architecture/photos-and-onboarding.md`](docs/architecture/photos-and-onboarding.md) — the camera, the photo pipeline and the magic link
- [`docs/architecture/laravel-cloud-production.md`](docs/architecture/laravel-cloud-production.md) — how production is wired, and how to run things against it
- [`CLAUDE.md`](CLAUDE.md) — how the code is laid out

Note for contributors: the UI uses [Flux Pro](https://fluxui.dev), a paid component library, so `composer install` needs a Flux license. CI runs with the maintainer's license via repository secrets.

## License

[FSL-1.1-MIT](LICENSE.md) — Functional Source License. Use it, read it, modify it, self-host it for anything non-competing; each release becomes MIT two years after publication.
