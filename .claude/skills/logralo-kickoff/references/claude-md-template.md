# {Project Name} - {One-line Description}

## Project Overview

{Brief description of what the project does, and who it serves.}

## Tech Stack

- **Laravel 13** with PHP 8.5
- **laravel/ai** with {provider} provider
- **Livewire 4 SFCs + Flux Pro** for {UI description}
- **SQLite** locally, **MySQL 8** on Laravel Cloud
- **Pest v5** for testing

## Architecture

### {Primary Domain} (`app/{Domain}/`)
- `{Component}` - {Description}

### Services (`app/Services/`)
- `{ServiceName}` - {Description}

### Jobs (`app/Jobs/`)
- `{JobName}` - {Description}

## Key Commands

```bash
# Setup project (first time)
composer setup

# Start dev environment
composer dev

# Run tests
composer test

# Format code
composer lint

# Run specific test suites
composer test:unit          # Pest parallel + coverage
composer test:types         # PHPStan level 8
composer test:type-coverage # 100% type coverage
composer test:lint          # Pint + Rector + Prettier checks
```

## Environment Variables

See `.env.example` for all required variables. Key ones:
- `{VAR_1}` - {Description}
- `{VAR_2}` - {Description}

## Laravel-First Conventions

Prefer Laravel utilities over native PHP equivalents:

- **Filesystem**: `File` facade (`File::exists()`, `File::isDirectory()`, `File::get()`, `File::put()`, `File::ensureDirectoryExists()`) instead of `is_file()`, `is_dir()`, `file_get_contents()`, `mkdir()`
- **Collections**: `collect()` pipelines (`->map()`, `->filter()`, `->reject()`, `->keys()`, `->values()`, `->contains()`) instead of nested `array_map`/`array_filter`/`array_values`/`in_array`
- **Strings**: `Str::` helpers (`Str::before()`, `Str::after()`, `Str::startsWith()`, `Str::contains()`) and `Str::of()` fluent API instead of `mb_substr`/`mb_strpos`/`str_starts_with`
- **Arrays**: `Arr::get()`, `Arr::has()` for nested access instead of `isset()` chains

## Observability

Wide events are full-app scope: jobs, commands, HTTP gates, and service operation boundaries. Accumulate data via `Context::add('{project}.*', ...)`, then emit one canonical `Log::info()` per unit of work. Do not pass manual arrays to canonical info lines.

Warnings must include `reason`.

Queue failure logging is centralized in global `Queue::failing` (`job.failed.safety_net`).

Key context keys: `{project}.{entity_id}`, `{project}.outcome`, `{project}.duration_ms`.

Nightwatch surfaces context in job attempt records and exception records.

## Deploy and Verify Workflow

Nightwatch app: `{nightwatch_app_id}`
Nightwatch env (production): `{nightwatch_env_id}`

After pushing code:
1. Monitor CI completion (`gh run watch`)
2. After deploy job succeeds, wait 3-5 min for Laravel Cloud to deploy
3. Use Nightwatch MCP `list_issues` to check for new issues since deploy
4. Zero new issues after 5 min = green signal
5. If issues found: `get_issue()` for stack trace + context, read source file at the line, fix, repeat
