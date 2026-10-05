# Dairy Management System

A browser-based management application for a dairy and cattle business: milk
production and distribution, direct customers, Mandali and vendor sales,
animals, employees, partner-funded finance, reporting and exports.

Built as a professional business dashboard — compact, keyboard-friendly and
responsive — not a simplified kiosk interface.

---

## What it does

The table below is the product this is being built into. It is delivered in
phases, and **[docs/PROGRESS.md](docs/PROGRESS.md) is the accurate statement of
what works today** — Finance, Administration, the master data behind the rest, milk
production, internal usage and reconciliation, direct customers in full
(registration, preferences, pauses, pricing, the daily entry grid, ledgers,
outstanding and payments), and the other three sales channels in full (Mandali
deliveries with fat and SNF and monthly settlement, vendor sales, sales to any channel
the business sets up itself — each with receipts, a ledger and an on-screen period
statement) are built; Animals, Employees and Reports are not yet. A module is absent
from the navigation until it works: nothing here is stubbed or shown as "coming soon".

| Module | Covers |
| ------ | ------ |
| **Milk** | Date and shift-wise production, internal usage, adjustments, and a reconciliation engine that accounts for every litre |
| **Direct customers** | Registered customers, milk preferences, quantity reminders, pauses, customer-specific pricing, a one-screen daily entry grid, ledgers and outstanding |
| **Mandali and vendors** | Deliveries with a manually agreed rate, fat and SNF, collection slips, vendor sales at a configured rate, sales to the channels you set up yourself, ledgers, receipts, an on-screen period statement and monthly settlement |
| **Animals** | Profiles, independent lifecycle states, event timeline, and a purchase workflow that splits payment across partners and accounts in one step |
| **Finance** | Expenses, multi-source funding, financial accounts, cashbook, partner contributions and ledgers |
| **Employees** | Profiles, private documents, monthly payroll, split-funded salary payments, loans and recoverable charges |
| **Reports** | Milk, customer, finance, animal and employee reports with Excel, PDF and print output |
| **Administration** | Users, editable roles and granular permissions, notifications, audit log, settings |

Two principles shape the whole product:

- **Enter once, update everywhere.** Entering a customer's milk creates the sale,
  and the reconciliation figure, the customer statement, the outstanding balance,
  the dashboard and the reports all derive from it. Nothing is entered twice, and
  nothing is stored that could be worked out. Cash is the exception that proves the
  rule: a delivery is a receivable, so it moves no money until the payment does.
- **Historical accuracy.** Rates and salaries are snapshotted onto the records
  that used them, so changing today's price never rewrites yesterday's books —
  including when the quantity on an old record is corrected.

---

## Requirements

| | Version |
| - | ------- |
| PHP | 8.3 minimum, 8.4 preferred |
| Composer | 2.x |
| MySQL | 8.4 LTS |
| Node | 20.19+ or 22.12+ (26.x recommended) |
| npm | 10+ |

Required PHP extensions: `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `bcmath`,
`fileinfo`, `openssl`, `curl`, `dom`, `xml`, `tokenizer`.

> **SQLite is not supported.** The application relies on foreign keys, composite
> unique constraints, `DECIMAL` arithmetic and strict mode, so both the
> application and its test suite run on MySQL. See
> [docs/DECISIONS.md](docs/DECISIONS.md) D2.

---

## Local setup

```
composer install
cp .env.example .env
php artisan key:generate
```

Create the two schemas:

```
mysql -u <user> -p -e "CREATE DATABASE IF NOT EXISTS dairy_management CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u <user> -p -e "CREATE DATABASE IF NOT EXISTS dairy_management_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
```

Set `DB_USERNAME` and `DB_PASSWORD` in `.env`, then:

```
php artisan migrate
npm install
npm run build
php artisan serve
```

For development with hot reloading, run `npm run dev` alongside
`php artisan serve`.

`.env` holds your database password and is gitignored. Never commit it.

---

## Commands

| Task | Command |
| ---- | ------- |
| Run the app | `php artisan serve` |
| Front-end dev server | `npm run dev` |
| Production asset build | `npm run build` |
| Migrate | `php artisan migrate` |
| Fresh database with seed data | `php artisan migrate:fresh --seed` |
| Run tests | `php artisan test` |
| Fix code style | `vendor/bin/pint` |
| Check code style | `vendor/bin/pint --test` |

Commands are always invoked through `PATH`; the repository contains no absolute
executable paths ([docs/DECISIONS.md](docs/DECISIONS.md) D1).

---

## Initial admin user

`php artisan db:seed` creates the business, its primary farm, all permissions, the
default roles and the master data the finance module needs — payment methods,
expense categories, sales channels, two financial accounts with zero opening
balances, and an opening price per milk type. It creates **no financial
transactions**: an invented financial history would be worse than an empty one.

It does seed a few days of milk production, internal usage and one adjustment,
which carry no money and no counterparty — enough to make the reconciliation screen
explorable. One evening shift is deliberately left unentered so the "Production not
entered" state is visible on a fresh installation.

Re-running it is safe and changes nothing.

It creates an administrator **only** if both `ADMIN_EMAIL` and
`ADMIN_PASSWORD` are set in `.env`; otherwise it skips that step and says so,
because a seeder that invents a default password would put a known credential on
every installation.

The recommended route is to leave those blank and run:

```
php artisan dairy:create-admin
```

It prompts for the password without echoing it, so the password never reaches
your shell history or a process listing. The account is created with the
**Super Admin** role.

To reset a forgotten password:

```
php artisan dairy:create-admin --email=you@example.com --reset
```

There is no public registration. All other accounts are created from
**Administration → Users** by someone holding the `user.manage` permission.

---

## Docker

An optional Docker setup (PHP-FPM, Nginx, MySQL 8.4, Mailpit) is planned. The
application does not require Docker and runs natively as described above.

*Not yet provided.*

---

## Documentation

| Document | Contents |
| -------- | -------- |
| [docs/MASTER_SPEC.md](docs/MASTER_SPEC.md) | The product and development specification — the source of truth |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Structure, modules, domain services, financial and milk flow |
| [docs/DATABASE.md](docs/DATABASE.md) | Schema, constraints, indexes, migration order |
| [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) | The rules the application enforces |
| [docs/PERMISSIONS.md](docs/PERMISSIONS.md) | Roles and the permission catalogue |
| [docs/TESTING.md](docs/TESTING.md) | How to run and write tests |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | VPS deployment, backups, production settings |
| [docs/PWA.md](docs/PWA.md) | Installability and caching policy |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Architecture decisions and their reasoning |
| [docs/PROGRESS.md](docs/PROGRESS.md) | Current phase, completed work, exact next task |

---

## Project status

See [docs/PROGRESS.md](docs/PROGRESS.md). The application is built in phases;
each phase ends with migrations, tests, Pint and a production build all passing.
