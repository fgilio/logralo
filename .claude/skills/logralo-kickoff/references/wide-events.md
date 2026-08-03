# Wide Event Logging

Observability pattern for Laravel projects. Replace `{project}` with the project name (here: `logralo`).

## 1. Doctrine

Log what happened, not step-by-step narration.

One unit of work -> one canonical `Log::info()` line.

Context lives in `Context::add()` keys. Canonical info lines carry no manual array.

Use high-cardinality IDs and explicit outcomes so Nightwatch queries answer real incidents fast.

## 2. Scope (Full App)

These rules apply to all production paths:
- Queue jobs
- Console commands
- HTTP middleware/profile/validators
- Service methods that represent a business operation boundary

## 3. Mechanics

### 3.1 Context

Add fields incrementally:

```php
Context::add('{project}.conversation_id', $conversation->id);
Context::add('{project}.turn_id', $turn->id);
Context::add('{project}.outcome', 'completed');
```

All keys must be namespaced with `{project}.`.

### 3.2 Canonical info lines

Exactly one canonical line per unit of work, usually in `finally`:

```php
try {
    // operation
} catch (Throwable $e) {
    Context::add('{project}.outcome', 'error');
    Context::add('{project}.error', $e->getMessage());
    Context::add('{project}.error_class', $e::class);
    throw $e;
} finally {
    Log::info('turn.handled');
}
```

No manual context array on canonical lines.

### 3.3 Warning lines

Warnings are allowed for rejects, invariant violations, or degraded-but-recoverable paths.

Every warning payload must include `reason`.

```php
Log::warning('webhook.signature.rejected', [
    'reason' => 'clock_skew',
    'channel_id' => $channelId,
]);
```

### 3.4 Error/Critical

- `Log::error()` only when the exception is being handled or converted into a non-throwing path.
- If an exception is rethrown and upstream will capture it, prefer Context fields and avoid duplicate error logs.
- `Log::critical()` only for urgent production incidents (data loss, security, hard safety checks).

### 3.5 Queue failure model (simple)

Single failure layer for exhausted queue attempts:
- Global `Queue::failing` listener logs `job.failed.safety_net` (warning).
- Do not add per-job `failed()` loggers unless there is a unique, justified exception.

## 4. Naming

Use lowercase dot-separated event names.

Examples:
- `inbound.processed`
- `turn.handled`
- `reaction.processed`
- `sync.completed`
- `export.generated`

Avoid CamelCase, spaces, or interpolated message strings.

## 5. Required Context Fields

Minimum set by unit type:

### Jobs/Commands
- `{project}.outcome`
- `{project}.duration_ms` (via queue middleware or explicit timing)
- Primary entity IDs if available

### HTTP gates/profiles
- `{project}.event_type` or equivalent request discriminator
- `{project}.outcome`
- `{project}.reject_reason` when rejected

### AI operations
- `{project}.agent.model`
- `{project}.agent.iteration`
- `{project}.agent.response_length` when available

## 6. Anti-Patterns

- Breadcrumb logs (`starting`, `step 2`, `done`)
- More than one canonical info line per unit
- `Log::info('event', [...])` on canonical lines
- `Log::debug()` in production paths
- Warning logs without `reason`
- Non-namespaced context keys

## 7. Audit Checklist (Pass/Fail)

R1. Every queue job has exactly one canonical `Log::info('...')` in a completion path (`finally` preferred).

R2. Canonical info lines pass no manual context array.

R3. Each canonical unit sets `{project}.outcome`.

R4. Queue jobs include `{project}.duration_ms` (usually via middleware).

R5. Warnings include a `reason` field.

R6. No `Log::debug()` in production code paths.

R7. Context keys use `{project}.` namespace.

R8. Event names are lowercase dot-separated.

R9. No string interpolation in log event names/messages.

R10. Queue failure handling is centralized in global `Queue::failing` unless explicitly justified.

R11. Service boundary operations emit a canonical info line or intentionally emit none and only enrich parent context.

R12. Tests verify canonical line emission for critical units.

## 8. Audit Commands

Run from repo root. Replace `{project}` with actual namespace.

```bash
# All logs
rg -n "Log::(debug|info|warning|error|critical)\(" app -g'*.php'

# Canonical info lines that still pass manual arrays (should be 0)
rg -n "Log::info\([^\)]*\[" app -g'*.php'

# Warning logs missing reason (manual review set)
rg -n "Log::warning\(" app -g'*.php'

# Debug logs in app code (should be 0)
rg -n "Log::debug\(" app -g'*.php'

# Non-namespaced context keys (replace {project} with actual name)
rg -n "Context::add\('(?!{project}\.)" app -g'*.php' -P
```

## 9. Testing Pattern

```php
Log::spy();

// trigger unit

Log::shouldHaveReceived('info')
    ->withArgs(fn (string $message): bool => $message === 'turn.handled')
    ->once();
```

For context assertions in job tests, assert `Context::get('{project}.outcome')` when the unit runs in-process.

## 10. Migration Policy

When touching a file with old-style logs:
- Convert breadcrumb `info` logs to Context fields
- Keep or improve warning/error semantics
- Align event names and reason payloads
- Add/update tests for canonical lines where practical
