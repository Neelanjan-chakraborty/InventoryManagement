# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laravel 13 / PHP 8.4 department inventory and student item-issue system ("Stockroom"). Server-rendered Blade + Tailwind 4 via Vite; no SPA framework. **MySQL 8.4** (InnoDB, `utf8mb4`/`utf8mb4_unicode_ci`), run as a compose service; the suite migrates into a separate `inventory_test` database on the same server. `pdo_sqlite` is still compiled into the image, so `DB_CONNECTION=sqlite DB_DATABASE=:memory:` works as an offline escape hatch, but it is not the target engine — do not reintroduce SQLite-only assumptions.

## Commands

Docker is the zero-setup path and needs no `.env`, key, or migrate step:

```bash
docker compose up --build                             # app on http://localhost:8000
docker compose -f docker-compose.yml -f docker-compose.dev.yml up   # live code + asset watch
docker compose run --rm test                          # php artisan test against MySQL
docker compose exec app php artisan <cmd>
docker compose exec mysql mysql -uinventory -pinventory inventory   # SQL shell
docker compose --profile worker up                    # add the queue worker
```

Host-side `php artisan test` also targets MySQL on `127.0.0.1:3306` (see `phpunit.xml`), so `docker compose up -d mysql` must be running first.

Without Docker:

```bash
composer setup              # install, .env, key, migrate, npm install, build
composer dev                # php artisan dev (serve + vite + queue, concurrently)
php artisan serve           # server only
npm run dev                 # vite dev server only
npm run build               # production assets (README treats this as part of "tests")

composer test               # config:clear + php artisan test
php artisan test --filter=test_partial_return_keeps_balance_outstanding_and_quarantines_damaged_units
php artisan test tests/Feature/BorrowingWorkflowTest.php

vendor/bin/pint             # format (Pint is the only linter; no static analysis configured)

php artisan inventory:promote-admin user@example.edu   # bootstrap the first admin
```

There are no seeded or hard-coded staff credentials: register through the app, promote with the artisan command above, then assign `coordinator` from **Access control**.

## Architecture

**Roles.** `users.role` is a plain string (`student`, `coordinator`, `admin`) checked by `User::hasRole(...$roles)`. Authorization is route middleware only — `role:coordinator,admin` (alias registered in `bootstrap/app.php` → `App\Http\Middleware\EnsureRole`). There are no Gates/Policies; add new staff routes inside the existing middleware groups in `routes/web.php` rather than checking roles in controllers. `AuthController::register` must never accept a role from input.

**All state transitions live in `app/Services/BorrowingService.php`.** Controllers validate and delegate; they never mutate stock, loan status, or due dates directly. Each of `issue`, `receiveReturn`, `requestRenewal`, `reviewRenewal` runs in `DB::transaction` with `lockForUpdate()` on the item/asset/loan/line it touches, throws `ValidationException` for rule violations (so a failed issue leaves no `Loan` or movement row), and writes an `InventoryMovement` via the private `recordMovement`. Preserve that shape — invariants depend on the lock-then-validate-then-write order.

**Two tracking modes on `inventory_items.tracking_type`:**
- `bulk` — availability is derived, not stored: `total_quantity − (damaged+lost+maintenance) − outstanding on active loans` (`InventoryItem::availableQuantity()`). Non-usable returns increment the corresponding `*_quantity` column so the units stay quarantined until staff records recovery.
- `asset` — one row per unit in `assets` with `asset_tag`, `condition` (usable/damaged/lost/maintenance) and `status` (available/issued/retired/…). Quantity is always 1; availability is `status = available AND condition = usable`.

Code branching on tracking type appears in the service, `InventoryItem`, and the inventory views — update all of them together.

**Loan shape.** `loans` → `loan_lines` (one line per item/asset) → `item_returns`. `loan_lines.outstanding_quantity` drives partial returns; a loan flips to `completed` only when no line has outstanding quantity. Due date is issue date + 15 days; renewals add 15 days to the *existing* due date and only on coordinator approval. Approval re-checks that the due date hasn't moved since the request (`previous_due_date`), and the renewal limit is the **minimum** `max_renewals` across the loan's items.

**MySQL-specific invariants** (the app ran on SQLite until the migration to MySQL — these are the differences that bite):
- Quantity columns are `UNSIGNED`. MySQL **rejects** an underflow instead of silently storing a negative, so every `decrement()` needs a guard proving the result stays >= 0. `InventoryController::updateAsset` has one; copy that pattern.
- `SUM()` comes back as a string (and `null` for no rows). Cast with `(int)` before arithmetic — see `InventoryItem::availableQuantity()` and the CSV export.
- Strict SQL mode is on (`STRICT_ALL_TABLES`, `ONLY_FULL_GROUP_BY`). Text longer than a `VARCHAR(255)` is an error, not a truncation: keep validation `max:` rules consistent with column types, and use `text()` for anything over 255 (this is why `loans.purpose` is `text`).
- InnoDB genuinely enforces `lockForUpdate()`, which SQLite ignored, so concurrent writers can deadlock. The four `BorrowingService` transactions pass `self::DEADLOCK_ATTEMPTS`; new locking transactions should too.
- Collation `utf8mb4_unicode_ci` is **case-insensitive**, so unique keys on `email`, `code`, `asset_tag` and `storage_locations.name` now collide across case. Intended, but tests asserting case-distinct duplicates would fail.
- Timestamp-ish columns use `dateTime()` rather than `timestamp()` to dodge MySQL's 2038 limit and implicit-default quirks.

**Audit trail.** `inventory_movements` records every issue/return/renewal/adjustment with the responsible `user_id`. Any new stock-changing operation must record one.

All domain tables are created in a single migration, `database/migrations/2026_10_13_000000_create_inventory_tables.php` (edited in place during the MySQL migration rather than patched, since no deployed MySQL data existed); foreign keys use `restrictOnDelete`, so rows are never hard-deleted — retire via `active`/`status` flags instead.

## Docker layout

`Dockerfile` is multi-stage: `assets` (node, `vite build`) → `vendor` (composer, no-dev) → `vendor-dev` → `runtime` (php:8.4-cli-alpine with `pdo_mysql`, `php artisan serve`) → `test` (runtime + dev deps, entrypoint `php artisan`). `docker/mysql/init/*.sql` runs once on first initialisation of the `app-mysql` volume and creates the test database — editing it has no effect on an existing volume without `docker compose down -v`. The runtime image **has no `.env`** — all configuration arrives as compose `environment:` entries and Dockerfile `ENV` defaults.

`docker/entrypoint.sh` is where boot-time setup lives: chowns the mounted volumes, persists a generated `APP_KEY` at `storage/app/.app_key`, blocks until MySQL accepts a real connection+query (compose's healthcheck alone is not enough — the app user/database can still be initialising), runs `migrate --force`, then caches config/routes/views. Its first argument is a verb — `serve`, `queue`, `scheduler`, `artisan`, or any raw command. Migrations and caching run only for the first three. Anything that must happen before the app accepts traffic belongs in that script, not in a README step.

`docker-compose.dev.yml` bind-mounts the host source, so it clears caches instead of building them and runs `vite build --watch` in a side `vite` service (deliberately not the hot-reload server — it avoids extra ports and dev-server URL config).

## Notes

- `AGENTS.md` and the old `CLAUDE.md` contained a Laravel Boost bootstrap stub. Boost is not installed and the app is already built; install it only if asked.
- A stray untracked `InventoryManagement/` subdirectory containing only a `.git` dir sits in the project root. Ignore it.
