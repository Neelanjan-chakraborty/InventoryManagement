# Department Inventory and Student Item Issue System

A Laravel application for quantity-based reusable stock and individually tracked assets. Students can view their own issue, return, and renewal history. Coordinators manage inventory and transactions. Administrators manage account roles.

## Run locally

Requirements: PHP 8.3+, Composer, Node.js, and npm.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

The project is configured for SQLite by default. Register a student account in the app, then grant initial administrator access to that existing account:

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

Before production, configure HTTPS and secure cookies, use a production database with backups, set up mail and password recovery, and verify student eligibility through institutional identity/email or coordinator-managed imports. The local self-registration form collects student details but does not verify institutional identity. Configure monitoring and review the deployment's session, database, and backup policies.

## Tests

```bash
php artisan test
npm run build
```
