# Production on Laravel Cloud

One application, `logralo`, in the `fgilio` organisation, with a single environment called `production` in `us-east-2` (Ohio) — the region the PlanetScale cluster is in, so the database is one hop away.

```
app logralo  (fgilio/logralo, branch main, push-to-deploy)
└── environment `production`   PHP 8.5, Node 24, https://logralo.fgilio.com
    ├── App             Flex 512 MiB, 1 replica, scheduler on
    ├── default         managed queue, Flex 256 MiB, 0–3 workers
    ├── database        the `logralo` database inside jarvis's PlanetScale cluster
    └── bucket logralo  Cloudflare R2, private, mounted as the disk `private`
```

Every push to `main` builds and deploys. There is no staging environment: the audience is one group of friends, and the blast radius of a bad deploy is an evening of nobody marking.

## The build

```bash
composer config --global --auth http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --audit false
npm run build
```

The first line is there because the repository is public and Flux Pro is not. The licence lives in the environment variables, never in `auth.json`, and the same pair is a GitHub Actions secret so CI can install too.

The deploy command is `php artisan migrate --force`.

**The build must not touch the network.** `npm ci` wipes `node_modules`, so anything the build downloads is downloaded on every deploy, and a remote change can then fail a deploy that has nothing to do with the commit. That is not hypothetical: `laravel-vite-plugin`'s `google()` font provider fetched `fonts.gstatic.com` at build time, one of its versioned URLs went 404, and a change to `.env.example` failed to deploy. Fonts now come from `@fontsource/*` in `node_modules`, pinned by `package-lock.json`. `tests/Arch/BuildTest.php` fails if a network-fetching provider comes back.

## The photo bucket is mounted as `private`

Attaching a bucket in the dashboard asks for a disk name, and the name chosen was `private`. That name is what `LOGRALO_PHOTO_DISK` points at in production. It describes the bucket's visibility, not its contents — renaming it means a dashboard change and a matching change to that variable, together, in one redeploy.

Because the bucket is private, `LOGRALO_PHOTO_SIGNED_URLS=true`, and every photo is served through a temporary URL — see [`photos-and-onboarding.md`](photos-and-onboarding.md) for why those signatures are cached.

Two settings depend on each other and are easy to break apart:

- The attach makes `private` the environment's **default** disk. `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local` must stay set, or Livewire uploads a temporary file to R2 and `PhotoProcessor` then cannot read it off the local filesystem.
- `aws/aws-sdk-php` is a direct requirement in `composer.json`. It was already in the lock file through `league/flysystem-aws-s3-v3`, but Laravel Cloud refuses to deploy an environment with a managed queue unless the package is required outright.

## Authenticating the CLI

`scripts/cloud-cli.sh` installs `laravel/cloud-cli` and authenticates it, and `scripts/cloud/setup.sh` calls it, so a hosted session comes up able to talk to production without anybody logging in.

The trap it exists for: **the CLI never reads `LARAVEL_CLOUD_API_TOKEN`.** Tokens come from `~/.config/cloud/config.json`, and when that file holds none, every authenticated command falls back to browser-based OAuth — which, headless, blocks until the caller times out rather than failing. A `cloud` command that produces nothing for two minutes is an unauthenticated CLI, not a slow API. The script copies the environment variable into the file the CLI actually reads:

```json
{ "api_tokens": ["…"] }
```

`LARAVEL_CLOUD_API_TOKEN` comes from the session environment, the same way `FLUX_USERNAME` and `FLUX_LICENSE_KEY` do — set on the hosted sessions and never committed. A laptop generally does not have it, and does not need it: `cloud auth` stores a token there once, and the script's first act is to notice the variable is missing and do nothing at all.

It appends rather than replaces, because the CLI keeps one token per organisation and a laptop is likely to hold others. Nothing prunes: rotate the token and the revoked one stays in the file until `cloud auth:token --remove` takes it out.

The committed `.cloud/config.json` pins `organization_id` and `application_id`. That is why `cloud application:get -n` resolves to logralo with no arguments, and why the right token is still chosen when several are stored.

## The CLI cannot see the variables Cloud injects

`cloud environment:get --fields=environmentVariables` lists what a human typed. It does **not** list what Cloud injects at deploy time — `LARAVEL_CLOUD_DISK_CONFIG`, `NIGHTWATCH_TOKEN`, the managed queue's credentials. Reading that list and concluding a bucket is not attached is wrong, and it has already been concluded once.

Ask the running application instead:

```bash
cloud tinker production -n --code='
    echo "disk=".config("logralo.photos.disk")
        ." driver=".config("filesystems.disks.".config("logralo.photos.disk").".driver")
        ." nightwatch=".(env("NIGHTWATCH_TOKEN") ? "set" : "unset");
'
```

The end-to-end storage check — write, read back, sign, fetch over HTTPS, confirm the signature is cached, delete — is the one command that proves R2 credentials, the presigned URL path and the cleanup in a single pass. Run it after anything that touches the bucket.

## Doing things to production

```bash
# Any artisan command.
cloud command:run production -n --cmd='php artisan …'

# Any PHP. Nothing is printed unless the code echoes it.
cloud tinker production -n --code='echo \App\Models\User::count();'
```

Adding a member is the same command as locally, run through the first of those:

```bash
cloud command:run production -n \
    --cmd='php artisan logralo:seed-member "Nombre" alguien@example.com Europe/Lisbon'
```

It prints a link that logs that person in. Treat the output as a credential: it goes to them over WhatsApp and nowhere else. Running it again for the same email rotates the token and kills the link already sent — which is also how you replace a link somebody lost.

## What is provisioned but not yet earning its keep

**The managed queue.** `QUEUE_CONNECTION=cloud`, and nothing in the application dispatches a job. Photo derivatives are built in the request, and the password-reset mail is sent in the request. The worker scales to zero, so it costs almost nothing, and it is the right thing to have ready the day photo processing moves off the request. It is not, today, load-bearing.

**Sleep.** The App instance sleeps after two hours of quiet, and the environment wakes on its own to run scheduled tasks. `logralo:close-months` runs **hourly**, so the environment is woken every hour and never reaches a two-hour idle window. In practice the app runs continuously. Either accept that as the price of an hourly month-close, or shorten the sleep timeout to something under an hour in the dashboard and let it sleep between ticks.

## Errors

Logralo reports to Nightwatch on Franco's **personal** account. The Nightwatch MCP connection available in a Claude session is scoped to a work workspace and cannot see this application at all — an empty issue list there means "wrong account", not "no errors". Check the Nightwatch web interface, or connect the personal account.
