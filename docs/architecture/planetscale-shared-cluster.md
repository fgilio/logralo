# The shared PlanetScale cluster

Production Logralo does not have a database cluster of its own. It has a database inside the cluster jarvis already pays for.

A PlanetScale Postgres "database" is a whole Postgres cluster, and a branch inside it is a running Postgres server. A Postgres server holds many logical databases. That is the seam we use.

```
org fgilio
└── database `main`            ← the cluster: PS-5, aarch64, aws-us-east-2 (Ohio)
    └── branch `main`          ← the server: PostgreSQL 18.4, production, no replicas
        ├── database `postgres`  ← jarvis, owner `postgres`
        └── database `logralo`   ← Logralo, owner `logralo`
        └── the pscale_* databases PlanetScale keeps for itself
```

Both apps run Laravel, so both have `users`, `cache`, `jobs`, `sessions` and `migrations`. A shared schema would have collided on the first migration. A database each keeps the names apart and makes `migrate:fresh` unable to reach the other app.

## The credential

Logralo connects as a hand-made Postgres role, not one from `pscale role create`. Roles that the PlanetScale API creates inherit `postgres`, which owns jarvis's tables — one leaked Logralo credential would then read jarvis's Telegram history.

```sql
CREATE ROLE logralo WITH LOGIN NOINHERIT PASSWORD '…';
CREATE DATABASE logralo OWNER logralo;
REVOKE ALL ON DATABASE logralo FROM PUBLIC;
```

`logralo` is a member of nothing. It can open a connection to the `postgres` database, because Postgres grants `CONNECT` to `PUBLIC` by default, but every table there refuses it (`permission denied for table users`). Removing that `CONNECT` needs a `REVOKE … FROM PUBLIC`, which also touches jarvis and PlanetScale's own exporter, so we do not do it.

The isolation is symmetric, and that has a cost. `postgres` is not a member of `logralo`, so jarvis's credential cannot `SET ROLE logralo`, and no role that `pscale role create` makes can reach this database either — a PlanetScale role inherits `postgres`, and `postgres` has no privilege here. **The password is the only key**, and it lives in 1Password and in the Laravel Cloud environment. The break-glass is `pscale_admin`, PlanetScale's own superuser, which means a support request.

The two facts are the same fact: give `postgres` admin over `logralo` and recovery becomes easy, but jarvis's application credential can then read Logralo's data. We chose isolation.

The pooler needs the branch id in the username. `logralo` alone is refused with _"User parameter must include branch"_; the username is **`logralo.<branch-id>`**.

```
DB_URL=postgresql://logralo.<branch-id>:<password>@<host>:5432/logralo?sslmode=verify-full&sslrootcert=system
```

`sslrootcert=system` is not optional on a machine without `~/.postgresql/root.crt` — libpq looks for that file, fails to find it, and refuses the connection before it reaches PlanetScale. `system` needs libpq 16 or later, which Laravel Cloud has: jarvis has connected with the same query string since June. `config/database.php` reads it from `DB_SSLROOTCERT`, and Laravel's URL parser sets it from the query string too.

On Laravel Cloud the production environment carries `DB_CONNECTION=pgsql`, `DB_SSLMODE=verify-full` and that `DB_URL`. The credential is also in 1Password, in the Private vault, as **Logralo PlanetScale Postgres production** — with a `TablePlus` field you can click to open the database. Its jarvis twin is **Jarvis PlanetScale Postgres production**. Titles avoid punctuation on purpose: an em dash makes `op://` secret references invalid.

## What the two apps share

- **One compute.** PS-5, and `max_connections = 25` on the backend behind PlanetScale's pgbouncer. Logralo load slows jarvis and the reverse.
- **One backup timeline.** Branches, restores and point-in-time recovery act on the whole cluster. You cannot restore jarvis to last Tuesday and leave Logralo where it is.
- **One bill, one region.** Ohio. Both apps pay the same latency from Laravel Cloud.

## What PlanetScale does not show you

PlanetScale's tooling introspects the default `postgres` database only. `pscale branch schema main main` lists jarvis's 18 tables and none of Logralo's 13. Expect the same blind spot in the web console's schema view and in query insights.

Use `artisan` for anything about Logralo's schema:

```bash
DB_CONNECTION=pgsql DB_URL='<the url>' ./scripts/php artisan db:show --counts
DB_CONNECTION=pgsql DB_URL='<the url>' ./scripts/php artisan migrate --force
```

## When to undo this

Move Logralo to its own cluster when either app needs a restore the other cannot accept, or when the PS-5 runs out of connections. The move is a `pg_dump` of one database and a restore into a new cluster — the isolation is already complete, so nothing in the app changes except `DB_URL`.
