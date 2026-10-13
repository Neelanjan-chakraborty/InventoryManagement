# Department Inventory and Student Item Issue System

A Laravel application for quantity-based reusable stock and individually tracked assets. Students can view their own issue, return, and renewal history. Coordinators manage inventory and transactions. Administrators manage account roles.

## Run with Docker (recommended)

The only requirement is Docker Desktop (or Docker Engine with Compose v2).

```bash
docker compose up --build
```

Open <http://localhost:8000>. No `.env`, key generation, `composer install`, `npm install`, or `migrate` step is needed — the image builds the front-end assets and installs dependencies, and on first boot the app container generates its `APP_KEY`, waits for MySQL, and runs migrations.

The stack is two services: **`app`** (PHP, serving on 8000) and **`mysql`** (MySQL 8.4). MySQL data lives in the `app-mysql` volume and storage/logs in `app-storage`, so both survive `docker compose down` and rebuilds. MySQL is also published on `127.0.0.1:3306` so you can attach a GUI client (TablePlus, DBeaver) with the credentials below.

Default credentials (override by exporting them or putting them in `.env` before `docker compose up`):

| Variable | Default |
| --- | --- |
| `DB_DATABASE` | `inventory` |
| `DB_USERNAME` / `DB_PASSWORD` | `inventory` / `inventory` |
| `DB_ROOT_PASSWORD` | `root` |
| `DB_TEST_DATABASE` | `inventory_test` |
| `DB_PORT_HOST` | `3306` (host port for MySQL) |

Grant the first administrator (after registering an account in the app):

```bash
docker compose exec app php artisan inventory:promote-admin student@example.edu
```

Other common operations:

```bash
docker compose up -d                       # run in the background
docker compose logs -f app                 # follow logs
docker compose exec app php artisan ...    # any artisan command
docker compose --profile worker up         # also start the queue worker
docker compose down                        # stop (data is kept)
docker compose down -v                     # stop and wipe the database/storage volumes
APP_PORT=9000 docker compose up            # serve on a different host port
docker compose exec mysql mysql -uinventory -pinventory inventory   # SQL shell
```

Back up and restore the database:

```bash
docker compose exec mysql mysqldump -uroot -proot --databases inventory > backup.sql
docker compose exec -T mysql mysql -uroot -proot < backup.sql
```

Live-reload development (host source mounted, assets rebuilt on save, no config caching):

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up
```

This overlay bind-mounts the project directory, so on macOS/Windows the directory must be listed under **Docker Desktop → Settings → Resources → File Sharing**. If it is not, Docker reports `mounts denied: the path ... is not shared from the host`. The plain `docker compose up` path needs no file sharing.

Run the test suite in Docker:

```bash
docker compose run --rm test                      # php artisan test
docker compose run --rm test test --filter=Borrowing
```

The suite migrates into a separate `inventory_test` database on the same MySQL server, so it exercises the real engine, charset and SQL mode instead of SQLite. `RefreshDatabase` rolls each test back, so the application database is untouched. To run the tests without a MySQL server (faster, but no engine parity):

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

## Run locally without Docker

Requirements: PHP 8.4+ (the lockfile resolves to packages requiring 8.4.1 or newer) with the `pdo_mysql` extension, Composer, Node.js, npm, and a reachable MySQL 8 server. The simplest option is to run just the database in Docker (`docker compose up -d mysql`) and the app on the host.

```bash
composer install
npm install
cp .env.example .env     # already points at mysql on 127.0.0.1:3306
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

The app uses MySQL (`utf8mb4`, InnoDB). Register a student account in the app, then grant initial administrator access to that existing account:

```bash
php artisan inventory:promote-admin student@example.edu
```

Sign in again. An administrator can assign the coordinator role from **Access control**. Registration never accepts an elevated role, and there are no seeded or hard-coded staff credentials.

## Workflows

- Inventory items are quantity-based or individually tracked. Unique assets have their own asset ID, optional serial number, location, condition, and status.
- Each issue is recorded with its issuing staff member and receives a due date 15 calendar days after the issue date.
- Bulk availability is calculated from total stock, outstanding issues, and damaged/lost/maintenance counts. Asset availability is based on its current status and condition.
- Returns may be partial. Receipt, quantity, condition, and receiving staff are recorded. Non-usable returns stay unavailable until staff records recovery.
- Students can request renewal before the due date. The date changes only after coordinator approval, by 15 days from the existing due date. Item policies set per-issue and renewal limits.
- Coordinator dashboards show upcoming and overdue issues. Inventory movement records capture issue, return, renewal, adjustment, condition, and responsible-user events. CSV inventory export is available to staff.

## Security notes

Authentication uses Laravel sessions, hashed passwords, CSRF protection, regenerated session IDs, and throttled login/registration routes. Role middleware protects each staff and admin route; student records are queried by the authenticated user. Stock changes and borrowing decisions run in database transactions with row locks, and failed issues do not create loan or movement records.

The default MySQL credentials in `docker-compose.yml` (`inventory`/`inventory`, root `root`) are development conveniences — set real ones via `.env` or your orchestrator before exposing the stack to a network, and stop publishing port 3306 outside localhost.

Before production, configure HTTPS and secure cookies, use a managed MySQL instance with backups, set up mail and password recovery, and verify student eligibility through institutional identity/email or coordinator-managed imports. The local self-registration form collects student details but does not verify institutional identity. Configure monitoring and review the deployment's session, database, and backup policies.

## Tests

```bash
php artisan test
npm run build
```
