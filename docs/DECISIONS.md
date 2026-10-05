# Architecture Decisions

Decisions that shape the codebase and are expensive to reverse. Each entry
records why the decision was made, not just what it was, so a later session can
tell whether the reasoning still holds.

Format: context, decision, consequences.

---

## D1 — Commands are invoked through `PATH`, never by absolute path

**Date:** 2026-09-20 · **Phase:** Pre-0 · **Status:** Accepted

**Context.** During the initial environment survey the development shell had a
stripped `PATH` and none of `php`, `composer`, `node`, `npm`, `git` or `mysql`
resolved. Absolute Windows paths were used temporarily to complete the survey.

**Decision.** No absolute Windows executable path appears in project scripts,
documentation, configuration, Composer/npm scripts, CI, deployment notes or
architecture. Every command is invoked bare: `php artisan ...`, `composer ...`,
`npm run ...`, `mysql ...`.

**Consequences.**

- The repository stays portable between the Windows development machine and the
  Linux VPS described in [DEPLOYMENT.md](DEPLOYMENT.md).
- A broken `PATH` is an environment problem to fix in the environment, never
  something to work around inside the repository.
- `php` resolves through Laravel Herd's `php.bat` shim locally, which selects a
  PHP version per directory. Production pins its version explicitly instead.

---

## D2 — MySQL for both development and automated testing; SQLite is not supported

**Date:** 2026-09-20 · **Phase:** Pre-0 · **Status:** Accepted

**Context.** The Laravel skeleton shipped with `DB_CONNECTION=sqlite` and a
`phpunit.xml` pinned to `sqlite` / `:memory:`. This application depends on
behaviour where SQLite and MySQL genuinely differ: composite unique constraints,
foreign key enforcement, `DECIMAL(14,2)` and `DECIMAL(10,3)` arithmetic,
transactional rollback across multi-table writes, and strict `sql_mode`. A green
SQLite suite would not prove the schema works.

**Decision.** MySQL 8.4 everywhere.

| Purpose         | Schema                  |
| --------------- | ----------------------- |
| Development     | `dairy_management`      |
| Automated tests | `dairy_management_test` |

Both are `utf8mb4` / `utf8mb4_0900_ai_ci`. `phpunit.xml` pins
`DB_CONNECTION=mysql` and `DB_DATABASE=dairy_management_test`. The test schema is
used by tests exclusively and is wiped by `RefreshDatabase` on every run, so it
must never point at development or production data.

**Consequences.**

- Tests are slower than in-memory SQLite. Accepted: correctness over speed.
- Running the suite requires a reachable MySQL server. Documented in
  [TESTING.md](TESTING.md).
- `tests/Feature/EnvironmentTest.php` asserts the driver, schema name, charset
  and decimal precision, so a silent revert to SQLite fails the build.
- `database/database.sqlite` is retired and unreferenced.
- Credentials live only in `.env`, which is gitignored. No database password
  appears in documentation, source, committed files or command output.

---

## D3 — Convert the existing skeleton rather than recreate the application

**Date:** 2026-09-21 · **Phase:** 0 · **Status:** Accepted

**Context.** The repository already contained an unmodified Laravel 13.32.0
skeleton with `vendor/` installed and a valid `composer.lock`, committed as the
baseline. Phase 0 of the specification says to create a Laravel 13 application
"if empty" — it was not empty.

**Decision.** Build on the existing skeleton. Do not run `laravel new` or
otherwise regenerate the application.

**Consequences.** The baseline commit stays a meaningful recovery point, and the
resolved dependency graph in `composer.lock` is preserved rather than re-solved.

---

## D4 — Bootstrap is compiled from source through Vite using modern Sass `@use`

**Date:** 2026-09-21 · **Phase:** 0 · **Status:** Accepted

**Context.** The skeleton shipped Tailwind CSS 4, which the specification
forbids. Bootstrap must come through NPM/Vite rather than a CDN.

**Decision.** Tailwind is removed entirely. `resources/scss/app.scss` configures
Bootstrap with `@use "bootstrap/scss/bootstrap" with (...)` rather than
`@import`, and Bootstrap Icons' `$bootstrap-icons-font-dir` is pointed at the
package's own font files so Vite hashes and emits them locally.

**Consequences.**

- Our stylesheets are already compatible with Dart Sass 3.0, which removes
  `@import`. Bootstrap's own internals still use the legacy API, so
  `quietDeps: true` in `vite.config.js` silences warnings we cannot act on. This
  keeps real build warnings visible instead of buried under ~320 deprecations.
- Icon fonts are self-hosted. Without the font-dir override the default
  `./fonts` path resolves relative to the built CSS, where no fonts exist, and
  every icon renders as an empty box. This was caught and fixed during Phase 0.
- Bootstrap variable overrides are centralised in one configuration block, so
  theming does not spread through the Blade templates.

---

## D5 — The Customer Daily Entry grid saves as a JSON request body

**Date:** 2026-09-21 · **Phase:** 0 (constraint recorded; implemented in Phase 4) · **Status:** Accepted

**Context.** PHP's `max_input_vars` defaults to 1000 on the development machine.
The Customer Daily Entry grid saves an entire day in one request, and the
specification requires unlimited customers. At roughly five fields per row a
conventional form POST silently truncates somewhere near 200 customers. PHP
discards the excess without raising an error, which would corrupt a day's milk
sales invisibly.

**Decision.** The grid submits a JSON body via the Fetch API. `json_decode` is
not subject to `max_input_vars`.

**Consequences.**

- Correctness does not depend on an `ini` setting that may differ on the VPS.
- The endpoint validates a decoded array rather than flat form input, so the
  Form Request uses array validation rules (`rows.*.morning_quantity`, ...).
- Server-side recomputation of totals after save remains mandatory. The JSON
  payload is untrusted input like any other.

---

## D6 — Table names are lowercase `snake_case`, without exception

**Date:** 2026-09-21 · **Phase:** 0 · **Status:** Accepted

**Context.** The development machine runs MySQL on Windows with
`lower_case_table_names = 1`, which folds table names to lowercase. The
production VPS will run Linux, where the default is `0` and table names are
case-sensitive. A migration or query that works locally can fail on deploy.

**Decision.** Every table name is lowercase `snake_case` (Laravel's default).
Case is never used to distinguish identifiers, and raw SQL always matches the
migration's exact spelling.

**Consequences.** Cheap to maintain, and removes an entire class of
deploy-time-only failures.

---

## D7 — Timezone conversion happens in PHP; business dates are `DATE` columns

**Date:** 2026-09-21 · **Phase:** 0 · **Status:** Accepted

**Context.** The specification requires Asia/Kolkata. The local MySQL server has
`time_zone = SYSTEM` and its named timezone tables are unpopulated, so
`CONVERT_TZ()` returns `NULL`.

**Decision.** `config/app.php` sets the application timezone (default
`Asia/Kolkata`, overridable via `APP_TIMEZONE`). Business dates such as
`production_date`, `sale_date` and settlement periods are stored as `DATE`, not
as timestamps, so no conversion is involved. Timezone-sensitive formatting is
done in PHP via Carbon. No query uses `CONVERT_TZ()`.

**Consequences.** Reporting by business date is unambiguous and does not depend
on MySQL timezone data being loaded on any given server.

---

## D8 — Pest is the test framework

**Date:** 2026-09-21 · **Phase:** 0 · **Status:** Accepted

**Context.** The specification says to use whichever testing stack the Laravel
installation already prefers. The skeleton ships Pest 5.2 on PHPUnit 13.3.

**Decision.** Pest, with `RefreshDatabase` applied to the whole `Feature` suite.

**Consequences.** Each test runs inside a transaction that is rolled back,
starting from a migrated but empty schema. Tests assert business behaviour, not
merely that a route returns 200.

**Correction (2026-09-27).** `phpunit.xml` declares both a `Unit` and a `Feature`
suite, but `tests/Unit` had been deleted during Phase 1. PHPUnit treats a missing
declared directory as a fatal configuration error, which broke
`php artisan test --filter` entirely — the whole suite still ran, so nothing
looked wrong until someone tried to run one test. The directory is kept with a
`.gitkeep`. Business rules that need a database stay in `Feature`; `Unit` holds
the few that genuinely do not.

---

## D9 — In-house audit logging rather than a package

**Date:** 2026-09-21 · **Phase:** 0 (implemented in Phase 2) · **Status:** Accepted

**Context.** The specification requires an audit log but warns against depending
on a package that forces an unnecessarily higher PHP requirement, and requires
specific fields plus a permission-restricted viewer.

**Decision.** A first-party `audit_logs` table and a domain service that records
user, action, auditable type and id, old and new values, IP, user agent and
timestamp. Passwords, reset tokens, uploaded document contents and secrets are
never recorded.

**Consequences.** Full control over what is audited and redacted, no external
version pressure, and the morph map from D10 keeps `auditable_type` stable.

---

## D10 — Polymorphic relations use a controlled morph map of stable aliases

**Date:** 2026-09-21 · **Phase:** 0 (implemented in Phase 2) · **Status:** Accepted

**Context.** Funding allocations, audit logs and ledger references are
polymorphic. Storing PHP class names in the database ties long-lived business
records to today's namespaces.

**Decision.** `App\Support\MorphMap` holds the aliases and
`Relation::enforceMorphMap()` registers them. Every alias must resolve to a model
and table that actually exist, so **the map grows one phase at a time**: an alias
is added when its model lands, never in advance of it.

Registered as of Phase 2 (15):

| Phase | Aliases |
| ----- | ------- |
| 1 | `user`, `business`, `farm`, `role`, `permission` |
| 2 — finance | `financial_account`, `payment_method`, `partner`, `partner_contribution`, `expense_category`, `expense` |
| 2 — masters and pricing | `sales_channel`, `buyer`, `milk_price_rule`, `buyer_price_rule` |

Spatie's `Role` and `Permission` are mapped because the audit log records role
and permission changes, and an enforced map would throw the moment somebody
edited a role otherwise.

Planned for later phases, not yet registered because their models do not exist:
`animal`, `animal_event` (6), `employee`, `employee_payroll`, `payroll_payment`,
`employee_loan`, `employee_loan_transaction` (7), `buyer_settlement`,
`buyer_payment` (5).

Class names are never persisted.

**Consequences.** Classes can be renamed or moved without a data migration, and
stored values stay readable. The map is enforced, so an unmapped model raises an
error rather than silently writing a class name.

**Correction (2026-09-21).** An earlier draft of this decision and of
[DATABASE.md](DATABASE.md) listed an `employee_loan_disbursement` alias. No such
model or table is planned — see D13 — so the alias would have pointed at nothing.
It is replaced by `employee_loan_transaction`. Caught during Phase 0 review,
before any migration or model was written.

---

## D11 — Export libraries

**Date:** 2026-09-21 · **Phase:** 0 (verified compatible; used in Phase 9) · **Status:** Accepted

**Context.** Reports require Excel, PDF and print output on Laravel 13.

**Decision.** `maatwebsite/excel` 4.x (verified: requires
`illuminate/support ^12.0 || ^13.0`, PHP ^8.3, PhpSpreadsheet ^5.9) for Excel,
and `barryvdh/laravel-dompdf` 3.x (verified: `^9|^10|^11|^12|^13.0`) for PDF.
Print output is CSS-based, not a third-party dependency.

**Consequences.** No Laravel downgrade is needed and no stale package is forced.
If either becomes incompatible later, PhpSpreadsheet can be used directly. The
export layer stays behind our own interfaces so callers do not change.

---

## D12 — Repo-local Git identity

**Date:** 2026-09-21 · **Phase:** 0 · **Status:** Accepted

**Context.** Git had no configured identity, which blocks committing.

**Decision.** `user.name` and `user.email` are set with `--local`, scoped to this
repository. The global Git configuration is left untouched.

**Consequences.** Commits are attributed correctly without changing the
developer's settings for unrelated repositories.

---

## D13 — A loan disbursement is a loan transaction, not a separate table

**Date:** 2026-09-21 · **Phase:** 0 (implemented in Phase 7) · **Status:** Accepted

**Context.** The specification lists `employee_loan_disbursements` among the
baseline tables with the qualifier "if needed". A disbursement is also one of the
movements the loan balance is derived from, so modelling it twice would mean two
sources of truth for the same money.

**Decision.** There is no `employee_loan_disbursements` table. A disbursement is
an `employee_loan_transactions` row with `type = 'disbursement'`, and
`funding_allocations` attach to that row through the stable morph alias
**`employee_loan_transaction`** (D10).

Only `disbursement` rows may carry funding allocations. Repayments, payroll
deductions and adjustments either move money the other way or move none, so
allocations on them are rejected in validation.

**Consequences.**

- One timeline per loan. The balance derives from a single table, so a
  disbursement cannot be recorded in one place and missed in the other.
- The morph alias points at a table that exists. An earlier draft of D10 and
  [DATABASE.md](DATABASE.md) named an `employee_loan_disbursement` alias with no
  model or table behind it; that was caught in Phase 0 review and corrected
  before any migration was written.
- Phase 7 must enforce the `type = 'disbursement'` constraint in the funding
  validation, not merely by convention.

---

## D14 — Boost's `boost:update` is removed from Composer's `post-update-cmd`

**Date:** 2026-09-21 · **Phase:** 0 · **Status:** Accepted

**Context.** Installing `spatie/laravel-permission` ran `boost:update` through
Composer's `post-update-cmd`, which appended roughly 175 lines of generated
guidance to `CLAUDE.md` — a project-controlled file. Several generated
instructions contradicted this project: that documentation files should only be
created on request (this project *requires* a documented set), that new folders
under `app/` need approval (the specification mandates several), that deployment
targets Laravel Cloud (it targets a VPS), and that `pint --test` should not be
run (it is a required phase check).

A package install silently rewriting the agent's own instruction file is the
problem, not the content.

**Decision.** Laravel Boost stays installed and its MCP server stays configured
in `.mcp.json`. Only the automatic hook is removed, so `post-update-cmd` now
contains just `vendor:publish --tag=laravel-assets`. Boost resources are
refreshed deliberately by running `php artisan boost:update` by hand.

`CLAUDE.md` gained a "Precedence Over Generated Guidelines" section that makes
the specification and the project rules authoritative, and each conflicting
generated instruction is marked `OVERRIDDEN FOR THIS PROJECT` in place so it
cannot be read in isolation.

**Consequences.**

- Composer operations no longer modify `CLAUDE.md`.
- Boost's tools (`database-schema`, `database-query`, `search-docs`,
  `last-error`, `browser-logs`) remain available.
- A manual `boost:update` will regenerate the block and may drop the inline
  override markers. The precedence section survives because it sits above the
  generated block, but it must be re-checked for new conflicts afterwards. This
  is noted in `CLAUDE.md` itself.

---

## D15 — Permission identifiers are code; role-to-permission assignment is data

**Date:** 2026-09-21 · **Phase:** 1 · **Status:** Accepted

**Context.** The specification requires editable roles *and* editable
permissions. Taken literally that would let an administrator rename
`user.manage` in a form, instantly breaking every route, policy and Blade check
that references the string, with no error until someone is wrongly allowed or
wrongly refused.

**Decision.** Split the two meanings of "editable":

| Editable at runtime | Fixed in code |
| ------------------- | ------------- |
| Which permissions a role holds | The spelling of a seeded permission |
| Creating and deleting custom roles | The seven seeded role names |
| Creating custom permissions | Super Admin being re-synced to the full catalogue |
| Assigning roles to users | |

Note what "Super Admin holding everything" means here: the seeder *grants it
every permission*. It is not an authorisation shortcut. See D19.

`App\Support\PermissionCatalog` holds the permission identifiers; `App\Support\RoleCatalog`
holds the seeded role names and the starting matrix. Seeded role names cannot be
renamed or deleted, and Super Admin's permissions cannot be edited away. Every
other assignment is fully editable and takes effect on the next request.

**Consequences.**

- The specification's requirement is met where it is useful and refused only
  where it would silently break authorisation.
- A rename of a permission is a code change, reviewed with the checks that
  depend on it.
- Deleting the Super Admin role cannot lock everyone out.
- Administrators can still add their own permissions and roles; the role editor
  lists non-catalogue permissions under a "custom" group so they stay assignable.

---

## D16 — One primary farm per business is enforced by the database

**Date:** 2026-09-21 · **Phase:** 1 · **Status:** Accepted

**Context.** Operational workflows resolve the primary farm automatically, so
two primary farms — or none — would be a silent data corruption rather than a
visible error. Application-level checks alone leave a race between two
concurrent promotions.

**Decision.** `farms` carries a generated column,

```sql
primary_farm_lock = CASE WHEN is_primary = 1 THEN business_id ELSE NULL END
```

with a unique index on it. MySQL treats NULLs as distinct in a unique index, so
any number of non-primary farms is allowed while a second primary for the same
business is rejected by the engine.

A naive `UNIQUE (business_id, is_primary)` would have been wrong: it also forbids
a *second non-primary* farm, because those rows share `(business_id, false)`.

The column is **VIRTUAL**, not STORED, because MySQL forbids `ON DELETE CASCADE`
on a base column of a stored generated column, which would make the `business_id`
foreign key impossible. This was discovered when the first migration failed with
error 1215.

`App\Actions\Farms\SetPrimaryFarm` demotes the current primary and promotes the
new one inside one transaction, in that order. `App\Services\BusinessContext` is
the only place that resolves the primary farm, so `Farm::where('is_primary', true)`
never spreads through controllers or views.

**Consequences.** The invariant holds even under concurrent writes, and a bug in
the action fails loudly instead of leaving two primary farms.

---

## D17 — `preventLazyLoading` is not enabled

**Date:** 2026-09-21 · **Phase:** 1 · **Status:** Accepted

**Context.** Laravel can throw on any lazy-loaded relation, which is a good way
to catch N+1 queries. Spatie's permission package, however, resolves roles and
permissions through lazy-loaded relations on every `can()` call.

**Decision.** `preventSilentlyDiscardingAttributes` is enabled;
`preventLazyLoading` is not.

**Consequences.** Enabling it would throw on ordinary authorisation checks
rather than surfacing our own N+1 problems, so query efficiency is handled by
explicit eager loading in the queries that feed list pages, and reviewed each
phase. Revisit if the package changes.

---

## D18 — The paginator is switched to Bootstrap 5

**Date:** 2026-09-21 · **Phase:** 1 · **Status:** Accepted

**Context.** Laravel's paginator renders Tailwind markup by default. Phase 0
removed Tailwind, so pagination links would have emitted class names no
stylesheet resolves — broken-looking controls with no error anywhere.

**Decision.** `Paginator::useBootstrapFive()` in `AppServiceProvider::boot()`,
covered by a test that renders a paginated list and asserts no Tailwind class
names appear.

**Consequences.** This is the most likely route by which Tailwind could
reappear, so it is asserted rather than assumed.


---

## D19 — Authorisation resolves only through permissions; roles are bundles, not shortcuts

**Date:** 2026-09-21 · **Phase:** 1 (correction) · **Status:** Accepted

**Supersedes** the `Gate::before` Super Admin hook introduced during Phase 1.

**Context.** Phase 1 registered a `Gate::before` callback that returned `true`
for any ability when the user held the Super Admin role. It was convenient: a
permission added by a later phase would work for Super Admin before the seeder
ran again.

It was also the one place in the codebase where a role *name* decided access,
which the specification rules out in favour of granular permissions. It was
unnecessary as well, because `RoleSeeder` already synchronises Super Admin with
the entire catalogue, so the hook only ever duplicated a grant that existed.

Worse, it made Super Admin untestable in the usual way. Any test asserting that
Super Admin could reach a page proved only that the bypass fired, not that the
permission was actually held.

**Decision.** The hook is removed. There is no `Gate::before`, no `hasRole()`
and no role-name comparison anywhere in the authorisation path.

**A system role is a default bundle of permissions, not an authorisation
shortcut.** Super Admin reaches everything because it *holds* every permission in
the catalogue, granted through the seeder — 65 of them when this decision was
taken, 70 as of Phase 4. Owner, Partner, Manager, Accountant, Data Operator and
Viewer are the same kind of object: starting bundles that an administrator may
re-cut at any time.

`RoleCatalog` is still referenced, but only for things that are not access
decisions:

| Use | Is it authorisation? |
| --- | -------------------- |
| Seeding the starting bundles | no — it writes grants |
| Re-syncing Super Admin to the catalogue | no — it writes grants |
| Refusing to rename or delete a seeded role | no — it protects a record |
| Locking Super Admin's permission editor | no — it protects a record |
| Assigning Super Admin in `dairy:create-admin` | no — it assigns a role |

**Consequences.**

- Every route, policy and Blade check resolves the same way, so there is one
  authorisation story rather than one plus an exception.
- A permission introduced by a later phase grants nothing to anyone, **including
  Super Admin**, until the seeder runs and adds it to the role. This is the
  intended trade: a capability becomes reachable when it is deliberately
  granted, not the moment its string appears in a catalogue. Each phase already
  ends by running migrations and seeders, so the window does not arise in
  practice.
- Super Admin is now genuinely testable. `tests/Feature/Admin/RolePermissionTest.php`
  strips the role's permissions directly and asserts the account loses access —
  which would have passed silently with the bypass in place.
- A source-scan test fails the build if `Gate::before`, `hasRole()`,
  `hasAnyRole()` or `hasAllRoles()` reappears anywhere under `app/`, so the
  shortcut cannot creep back in unnoticed.

---

## D20 — Every ledger posting carries a deterministic idempotency key

**Date:** 2026-09-22 · **Phase:** 2 · **Status:** Accepted

**Context.** A posting is the moment money moves. The ways one gets made twice
are ordinary rather than exotic: a double-clicked Save, a browser retry after a
timeout, a refreshed POST, a re-run job. Any of them silently doubles a balance,
and nothing in the resulting data says which of the two entries was the mistake.
Guarding with "check whether an entry exists, then insert" is not a guard at all,
because two concurrent requests both pass the check before either inserts.

**Decision.** `financial_ledger_entries.idempotency_key` is `NOT NULL` with a
unique index, and `FinancialLedgerService` is the only writer. Every posting
derives its key from the domain operation that caused it, not from the request:

| Operation | Key |
| --------- | --- |
| Expense funded from an account | `expense_funding:{allocation_id}` |
| Partner contribution into an account | `partner_contribution:{contribution_id}` |
| Reversal of any entry | `reversal:{original key}` |

`FinancialLedgerService::key($purpose, $id)` builds them, so the shape cannot
drift between call sites.

The service checks for an existing key first, and additionally catches MySQL
error 1062 and returns the row the winning request wrote. The check is the fast
path; the constraint is the guarantee.

**Consequences.**

- Posting the same effect twice is impossible, not merely unlikely.
- A repeat is not an error. The caller gets the existing entry, because that is
  the outcome they asked for; surfacing a constraint violation would turn a
  harmless retry into a visible failure.
- The key must derive from something stable. Deriving it from a request id or a
  timestamp would produce a new key per attempt and guard nothing, so keys come
  from database ids of the causing record.
- Keys are readable by design. `expense_funding:41` says what it is when found
  in a support query months later.

---

## D21 — A wrong posting is reversed, never edited or deleted

**Date:** 2026-09-22 · **Phase:** 2 · **Status:** Accepted

**Context.** Cancelling an expense or a contribution must stop it affecting
balances. Deleting its ledger entries would do that, and would also destroy the
record that the money was once believed to have moved — which is exactly what
someone auditing the account later needs to see.

**Decision.** Ledger entries are append-only. `FinancialLedgerService::reverse()`
writes an opposite-direction entry with `reverses_entry_id` pointing at the
original. Both rows remain. `reverses_entry_id` carries a unique index, so an
entry can be reversed at most once — enforced by the engine, not by the caller
remembering to check.

A reversal cannot itself be reversed; the service refuses it. Un-cancelling is
not an operation: the corrected figure is entered as a new record, so the
timeline reads as what happened rather than as what the books were edited to say.

`reverseAllFor($reference)` takes `lockForUpdate()` on the un-reversed entries
for a record, so two concurrent cancellations cannot each post their own set.

**Consequences.**

- The account's history explains itself, including its corrections.
- The derived balance is right without any row being mutated: a credit and its
  reversal sum to zero.
- Cancellation is idempotent end to end. Cancelling twice posts one set of
  reversals, because both the deterministic key (D20) and the unique index on
  `reverses_entry_id` reject the second.

---

## D22 — Funding is polymorphic on both sides, and only account money moves the ledger

**Date:** 2026-09-22 · **Phase:** 2 · **Status:** Accepted

**Context.** The specification requires that any expense be payable by any
combination of partners and business accounts, with no limit on how many, and
requires the same mechanism later for animal purchases, payroll payments and loan
disbursements. The obvious shapes are both wrong: columns such as
`partner_1_amount` cap the partner count in the schema, and a table per payable
type duplicates the split logic once per phase.

**Decision.** One `funding_allocations` table, polymorphic on both sides:

| Column | Holds | Aliases in use |
| ------ | ----- | -------------- |
| `payable_type` / `payable_id` | what is being paid for | `expense` (Phase 2); `payroll_payment`, `employee_loan_transaction` later |
| `source_type` / `source_id` | who paid | `partner`, `financial_account` |

Both store morph aliases, never class names (D10). `MorphMap::fundingSources()`
and `fundingPayables()` are the allow-lists, and `AllocateFundingSources` rejects
anything outside them, so a new payable type is a deliberate one-line addition
rather than whatever a request happens to post.

Two rules live in that action, inside the caller's transaction:

1. **The split is exact.** `SUM(allocations) == payable.amount`, compared with
   `bccomp` on decimal strings. Not "close enough", and not checked in the
   browser: the JavaScript running total is a convenience, and the server
   recomputes regardless of what it is told.
2. **Only business money moves an account.** An account-funded share debits that
   account. **A partner-funded share posts no ledger entry at all**, because the
   money never passed through a business account. It appears on the partner's
   ledger instead (D24).

**Consequences.**

- Unlimited partners per expense, with no schema change and no per-type code.
- Phases 6 and 7 fund through this same action by passing a different payable.
- The second rule is the one most easily got wrong, and getting it wrong invents
  money the business never had: an expense a partner paid for in full would show
  cash leaving an account that never held it. Tests assert the account balance is
  untouched by a partner-funded expense.
- An allocation is not an expense. One 10,000 expense split three ways is one
  `expenses` row and three allocation rows, and every total in the application
  must keep counting it once.
- A duplicate source within one split is rejected rather than merged: it is
  almost always a mis-click, and silently combining the rows would make the
  partner ledger read as two payments where the user meant one.

---

## D23 — A posted expense's money is corrected by cancelling, not by editing

**Date:** 2026-09-22 · **Phase:** 2 · **Status:** Accepted

**Context.** An expense's amount, date and funding split are what determined the
ledger entries already posted against them. Allowing a form to change those three
fields means either leaving the entries stale — so the expense, its allocations
and the account balances describe three different realities — or silently
reversing and re-posting money behind an innocuous-looking Save.

**Decision.** `UpdateExpenseRequest` accepts `expense_category_id`,
`description`, `payee_name` and `notes`. It does not accept `amount`,
`expense_date` or `allocations`, and because
`preventSilentlyDiscardingAttributes` is enabled, a stray one is an error rather
than a quiet no-op.

Correcting money means cancelling the expense, with a reason, and entering the
corrected one. Cancellation reverses the postings (D21) and both versions stay in
the record.

**Consequences.**

- What can be edited is exactly what has no financial effect.
- The audit trail shows a cancellation with its stated reason next to a
  replacement, rather than an amount that changed with no explanation.
- The same policy applies to partner contributions, for the same reason.
- It is more typing for the user than editing a number would be. Accepted: a
  balance that cannot be quietly rewritten is the point.

---

## D24 — The partner ledger is derived, and there is no partner ledger table

**Date:** 2026-09-22 · **Phase:** 2 · **Status:** Accepted

**Context.** Each partner needs a statement of everything they have put into the
business. The tempting implementation is a `partner_ledger_entries` table written
alongside contributions and allocations.

**Decision.** There is no such table. `PartnerLedgerService` derives every line
from records that are already the system's truth:

| Line | Derived from |
| ---- | ------------ |
| Contribution into a business account | `partner_contributions` where `status = active` |
| Expense the partner paid directly | `funding_allocations` where `source_type = partner`, on an active expense |

Phases 6 and 7 extend the same method, because animal purchases, payroll payments
and loan disbursements fund through `funding_allocations` too.

**Consequences.**

- One fact, one row. A ledger table would be a second copy of the same money,
  free to drift, with no way to say which copy is right when it does.
- Cancelled records vanish from the ledger automatically, because the queries
  filter on status rather than a sync process remembering to remove a line.
- The cost is query work per view instead of a stored total. Accepted: the
  aggregates are indexed, and the list page totals many partners in two grouped
  queries rather than one query per partner.
- This is the same reasoning as the absent `financial_accounts.current_balance`
  and the absent buyer outstanding column. Balances are computed; only the
  movements are stored.

---

## D25 — System master records have an immutable code and an editable name

**Date:** 2026-09-22 · **Phase:** 2 · **Status:** Accepted

**Context.** Payment methods, expense categories and sales channels are
administrator-editable data, and their names must be translatable. Later phases
also need to *find* particular ones: Phase 4 branches on the Mandali channel,
Phase 6 needs the animal-purchase category. Matching on a display name would
break the moment someone renames "UPI" or types the label in Gujarati.

**Decision.** Every such table carries a stable machine identifier — `code` on
`payment_methods` and `expense_categories`, `slug` on `sales_channels` — plus an
`is_system` flag. Seeders match on the identifier, so re-running never duplicates
a row and never resets a name an administrator has edited. A system row's
identifier is immutable and the row cannot be deleted; its display name, active
state and sort order are freely editable.

Model constants carry the identifiers that code depends on
(`SalesChannel::MANDALI`, `ExpenseCategory::ANIMAL_PURCHASE`,
`PaymentMethod::CASH`), so a reference is a code change, reviewed.

**Consequences.**

- Renaming is safe; renaming into another language is safe.
- Seeders are idempotent by construction rather than by a guard clause.
- Custom rows created by administrators are ordinary data with `is_system` false;
  they may be deleted while unused, and are deactivated once history exists.
- This is D15's distinction applied to master data: identity is code, presentation
  and assignment are data.

---

## D26 — Buyer authorisation is resolved from the buyer's sales channel

**Date:** 2026-09-22 · **Phase:** 2 · **Status:** Accepted

**Context.** The specification gives Mandali, vendors and direct customers
separate permission families (`mandali.*`, `vendor.*`, `customer.*`), while one
`buyers` table holds all three. Something must map a row to a family, and sales
channels are runtime data, so an administrator-created channel has no family of
its own.

**Decision.** `App\Support\BuyerPermissions` is the single mapping, and
`BuyerPolicy` asks it:

| Channel slug | Permission family |
| ------------ | ----------------- |
| `mandali` | `mandali.*` |
| `vendor` | `vendor.*` |
| `direct_customer` | `customer.*` |
| anything else | `customer.*` |

A custom channel falls back to `customer.*` because commercially it is a direct
buyer and the generic sale entry already treats it as one.

**Consequences.**

- The channel-to-permission decision exists once, instead of as `if` branches
  spread through controllers, policies and Blade.
- The fallback is deliberate in both directions: without it a custom channel is
  unreachable by everyone, and with a fallback to `mandali.*` it would silently
  inherit settlement rights that do not apply to it.
- The buyer list filters to the channels the user may see, so it never shows rows
  the server would refuse to open.
- A future channel needing its own family is a code change here plus its
  permissions in the catalogue — visible, not implicit.

---

## D27 — Price periods are opened forward only, under a row lock

**Date:** 2026-09-22 · **Phase:** 2 · **Status:** Accepted

**Context.** Milk prices are effective-dated history: a sale dated in September
must keep resolving to September's rate however often the price changes
afterwards. The invariant is that no two periods for the same owner and milk type
overlap. MySQL cannot express that as a constraint, and an application check
alone leaves a race in which two concurrent price changes both read "no later
rule exists" and both insert.

**Decision.** Three layers, each covering what the one above cannot:

1. A unique index on (`business_id`|`buyer_id`, `milk_type`, `effective_from`) —
   the part the database *can* enforce: two rules cannot start on the same day.
2. `SetMilkPrice` takes `lockForUpdate()` on the latest existing rule before
   deciding anything, inside a transaction.
3. **Forward only.** A new period must start strictly after the latest existing
   `effective_from`. The open period is closed at the day before the new one
   begins, and the new row is inserted.

Rule 3 is what makes overlap arithmetically impossible rather than merely checked
for: with every period starting later than the last, and the previous one closed
the day before, no arrangement is left that overlaps.

Only a period that has not started may be deleted (`DeleteFuturePriceRule`), and
deleting it re-opens the period it closed.

**Consequences.**

- History is append-only. Changing a price never rewrites a row, so past sales
  keep their rate.
- Back-dating a correction is refused. Accepted: allowing it would mean
  recomputing every sale already priced from the affected period, which is a
  Phase 5 settlement concern and not something a price form should do silently.
- `PriceResolver` resolves buyer override → business default → **explicit
  failure**. It throws rather than returning zero, because a zero rate would
  silently record a free sale. A missing price is a configuration error and says
  so.

---

## D28 — Audit records are diffs, redacted centrally, and never updated

**Date:** 2026-09-22 · **Phase:** 2 · **Status:** Accepted

**Context.** An audit log accumulates whatever it is given. Persisting request
payloads means the first form that posts a password, a token or a document's
contents puts it in a table built to be kept forever and readable by anyone
holding `audit.view` — which is the opposite of what the log is for.

**Decision.** `App\Services\AuditLogger` is the only writer, and domain actions
call intention-revealing methods (`created`, `updated`, `statusChanged`,
`cancelled`, `custom`) rather than building rows.

- **Diffs, not payloads.** `updated()` stores only attributes that actually
  differ, and returns `null` when nothing meaningful changed, so a resubmitted
  form adds no empty row.
- **Redaction is central.** `SENSITIVE_KEYS` matches whole field names;
  `SENSITIVE_FRAGMENTS` additionally matches substrings, so `smtp_password` and
  `webhook_secret` are caught without being listed. Values become `[redacted]` —
  the field's *presence* is still recorded, because "the password was changed" is
  exactly what an audit log should say. `NOISE_KEYS` drops attributes that change
  on every save and mean nothing.
- **Append-only.** `audit_logs` has `created_at` and no `updated_at`, and there
  is no update or delete path. A `subject` label is captured at write time, so
  the viewer can still say which partner or account was affected after the row is
  renamed or gone.
- The audit write shares the transaction of the change it describes, so a
  rolled-back change takes its audit record with it.

Never recorded: plaintext passwords, password hashes, password confirmations,
reset or remember tokens, application secrets, database credentials, or the
contents of private uploads.

**Consequences.**

- A newly sensitive field is protected everywhere by one entry in one array.
- `user_id` is nullable, so a seeder or console command can write an entry, and
  deleting a user does not delete the history of what they did.
- The trail begins when the service does. No synthetic records were backfilled
  for Phase 1 events — an invented history would assert facts nobody observed.

**Correction (2026-09-27).** Two defects in the first implementation, both found
by tests written in Pass 2:

1. IP address and user agent were gated on `runningInConsole()`, which conflates
   "console process" with "no HTTP context" — wrong in both directions, since a
   queued job has a console process *and* no request, while `artisan serve` is a
   console process serving real ones. Now keyed on the presence of `REMOTE_ADDR`,
   which is the thing actually being asked about.
2. The password-change entry used the key `password`, which its own redactor
   correctly stripped — defeating the entry's entire purpose, silently. The fact
   now travels under a neutral `security_event` key, which records *that* a
   password changed while carrying no part of it.

---

## D29 — The cashbook paginates, and carries a balance brought forward

**Date:** 2026-09-27 · **Phase:** 2 (correction) · **Status:** Accepted

**Context.** The cashbook was first built to render a whole period in one page.
MASTER_SPEC section 71 requires server-side pagination on list screens, and an
account with a year of entries would have loaded thousands of rows into the DOM.

**Decision.** The cashbook paginates. A running balance across pages needs two
figures rather than one:

- **opening** — the account's opening balance plus net movement before the
  period, so a date-filtered view starts from the right number;
- **brought forward** — the opening plus net movement over *the rows on earlier
  pages*, which is where page two must start from.

Brought-forward is one aggregate over a limited subquery, not a fetch of the
earlier rows, so paging deep into a long ledger costs what paging into its start
costs.

**Consequences.**

- Without brought-forward, every page after the first shows balances that are
  arithmetically wrong while looking entirely plausible. That is the defect
  pagination would have introduced, so it is asserted by a test.
- The page's closing figure is the closing balance *of that page*, and is
  labelled as such rather than as the period's.

---

## D30 — Production is one row per farm, date and shift, holding both milk types

**Date:** 2026-09-27 · **Phase:** 3 · **Status:** Accepted

**Supersedes** the `milk_productions` shape described in an earlier draft of
[DATABASE.md](DATABASE.md) and in the Phase 2 "exact next task" note, both of which
gave the table a `milk_type` column, a single `quantity`, and a unique key of
(`farm_id`, `production_date`, `shift`, `milk_type`).

**Context.** MASTER_SPEC section 14 specifies that for every date, farm and shift
the system records a cow milk quantity *and* a buffalo milk quantity, and that the
database prevent duplicates for `farm + date + shift`. The superseded plan
described a row per milk type instead, which is a different model and a weaker
constraint.

**Decision.** `milk_productions` holds **one row per farm, date and shift**, with
`cow_milk_quantity` and `buffalo_milk_quantity` as columns. There is no
`milk_type` column. The unique key is (`farm_id`, `production_date`, `shift`).

`MilkProduction::quantityFor(MilkType)` and `columnFor(MilkType)` map a milk type
to its column, so no caller reads `cow_milk_quantity` directly and a third milk
type is a change in two small methods.

**Consequences.**

- **"Has this shift been recorded?" stays one fact.** This is the real reason, and
  it matters more than the column count. With a row per milk type, a shift where
  somebody entered cow milk but not buffalo is indistinguishable from one where
  buffalo production genuinely was zero — there is a cow row and no buffalo row in
  both cases. With one row per shift, the row's existence answers the question for
  the whole shift, and `productionEntered` in the reconciliation result is
  therefore trustworthy. MASTER_SPEC section 15 requires "Production not entered"
  to be displayed rather than a zero, and that requirement is only satisfiable if
  the schema can express the difference.
- Saving a shift twice updates one row rather than up to one row per milk type, so
  the upsert has a single identity to lock.
- The entry screen is a matrix (types down, shifts across) while storage is a row
  per shift, so the screen saves up to two records in one transaction. The two
  shapes are deliberately different: the grid suits the person, the row-per-shift
  storage suits the reconciliation engine, which asks about one shift at a time.
- A quantity of zero and an absent row are never equivalent. Every layer preserves
  the distinction: the column defaults to zero *for a row that exists*, the
  service reports `productionEntered`, the `<x-litres>` component renders null as
  an em-dash rather than 0.000, and the reconciliation screen shows a warning
  panel.
- Animal-wise production, if it is ever wanted, attaches to this row rather than
  replacing it, which is what MASTER_SPEC section 14 asks for.

---

## D31 — Milk adjustments carry an explicit direction, not a signed quantity

**Date:** 2026-09-27 · **Phase:** 3 · **Status:** Accepted

**Supersedes** the `quantity DECIMAL(10,3) (signed)` note in an earlier draft of
[DATABASE.md](DATABASE.md).

**Context.** An authorised adjustment can add milk to a shift's available pool or
take milk out of it. MASTER_SPEC section 15 requires the capability, a mandatory
reason and an audit entry, but does not prescribe how the two directions are
stored. The Phase 0 plan said the quantity would be signed.

**Decision.** `milk_adjustments.direction` is `increase` or `decrease`, backed by
`App\Enums\AdjustmentDirection`, and `quantity` is always **positive**. The sign
exists only in arithmetic, through `AdjustmentDirection::sign()` and
`MilkAdjustment::signedQuantity()`, and never in a column.

**Consequences.**

- A form asks "increase or decrease" and "how much", rather than asking someone to
  type `-2.500` litres. A list shows a direction badge rather than a negative
  litre count.
- Every milk quantity column in the schema is non-negative, and the validation
  rule is the same everywhere: `gt:0`. A signed column would have meant permitting
  negative milk in exactly one table, which is the kind of exception that later
  gets copied by accident.
- Aggregation is per direction — `SUM(quantity) GROUP BY direction` — which is
  what the reconciliation screen wants anyway, because it shows increases and
  decreases as separate lines. Netting them into one figure would hide the fact
  that two exceptions were recorded when an increase and a decrease cancel out.
- `approved_by`, which the earlier plan also listed, is not built. The permission
  decides who may record an adjustment, and `created_by` plus the audit entry
  record who did. A separate approval column would describe a review step that
  does not exist.
- **Nothing creates an adjustment automatically.** No allocation workflow writes
  one to make room for itself, because that would be exactly the silent balancing
  record the specification forbids, and it would reduce the authorisation
  requirement to a formality. When milk runs short the allocation is refused and a
  person decides.
- Cancelling an increase is refused when allocations depend on the milk it
  granted, and a decrease may not remove milk already allocated. Otherwise either
  would produce a negative remaining figure through an ordinary save — the state
  the whole availability rule exists to prevent — against records whose own
  quantities were all valid.

---

## D32 — Reconciliation reaches sales through a replaceable allocator

**Date:** 2026-09-27 · **Phase:** 3 · **Status:** Accepted

**Context.** MASTER_SPEC section 15 defines allocated milk as sales plus internal
usage, and the reconciliation screen must show Mandali, Vendors, Direct Customers
and Other Sales as separate lines. Phase 3 owns production, usage, adjustments and
the engine; the workflows that record sales are Phase 4 (direct customers) and
Phase 5 (Mandali and vendors). So the engine has to be complete and correct before
half of its inputs exist.

Three options. Create `milk_sales` now and leave it empty: schema with no writer
and no reader, which nothing can verify. Have the engine return sales as a
hard-coded zero and add the query in Phase 4: then Phase 4 edits the engine, and
the engine is where the "remaining must not go negative" rule lives. Or put a seam
in.

**Decision.** `App\Contracts\MilkSalesAllocator` supplies the sales half, returning
a `SalesAllocation` value object with a per-channel breakdown, a total, and a
`subsystemExists` flag. Phase 3 binds `App\Services\Milk\NoMilkSalesRecorded`;
Phase 4 creates `milk_sales` and binds an aggregating implementation.
`CalculateMilkReconciliation`, `MilkReconciliation` and the reconciliation view are
written against the contract and do not change.

`milk_sales` is **not** created in Phase 3.

**Consequences.**

- The formula in the engine is already the final one. Phase 4 changes a container
  binding, not the arithmetic and not the rule that guards it.
- **The zero is honest rather than convenient.** With no `milk_sales` table, no
  milk can have been sold, so zero is the correct quantity — but
  `subsystemExists: false` records *why*, and the screen says the sale modules are
  not built yet. `NoMilkSalesRecorded::channels()` returns an empty array
  deliberately: returning the three seeded channel slugs would have the screen draw
  three rows of 0.000 L, which a reader would take for "nothing was sold today".
  That is the false impression this design exists to avoid.
- The channel breakdown is data-driven, so a fourth channel needs no view change.
- The cost is one interface and one small class for a single implementation, which
  is a real cost. It is accepted because the alternative puts a Phase 4 edit inside
  the Phase 3 safety rule.

---

## D33 — Milk records are corrected, not deleted; production has no delete path

**Date:** 2026-09-27 · **Phase:** 3 · **Status:** Accepted

**Context.** MASTER_SPEC section 60 says operational transactions are cancelled
rather than hard-deleted, and that cancelled records stop affecting milk allocation
while remaining auditable. The permission catalogue, however, contains
`milk.production.delete`, which implies a destructive route.

**Decision.** Three different treatments, each matching what the record is:

| Record | Correction path |
| ------ | --------------- |
| Production | **Updated in place.** Saving the shift again overwrites the quantities; `created_by` is kept and `updated_by` is set. No delete route exists. |
| Internal usage | **Cancelled** with a reason. No update route. |
| Adjustment | **Cancelled** with a reason. No update route. |

Production is updated rather than cancelled because it is a *statement of fact
about a shift*, of which there is exactly one — the unique key says so. Correcting
it to 18.750 litres does not create a second competing figure, and a cancelled
production row would leave the shift with no production while a row exists,
muddying the missing-versus-zero distinction D30 exists to protect. The audit log
carries the before and after quantities, so the earlier figure is not lost.

Usage and adjustments are cancelled because each is one event among many for a
shift, and each has already changed how much milk was left. Withdrawing one should
read as a withdrawal, with a reason, rather than as an edit that leaves no trace it
was ever claimed.

**`milk.production.delete` is seeded but nothing checks it.** The identifier stays
in the catalogue because MASTER_SPEC section 10 lists it and removing a specified
permission is a bigger change than leaving one unused; it is excluded from the
Owner role, so no seeded role holds it. If a destructive path is ever wanted, the
permission is already there to gate it.

**Consequences.**

- No screen can erase a figure the reconciliation once used, and no total can be
  made to balance by deleting the inconvenient row.
- `milk.usage.update` and an adjustment update permission do not exist, because
  the workflows do not.
- An unused seeded permission is a small wart. The alternative — exposing a delete
  route so the identifier has a purpose — would be letting the catalogue dictate
  the product.

---

## D34 — Internal usage gets its own permissions rather than borrowing one

**Date:** 2026-09-27 · **Phase:** 3 · **Status:** Accepted

**Context.** MASTER_SPEC section 15 requires internal usage entry — calf feeding,
home use, samples, wastage — but its permission catalogue (section 10) has no
permission for it. The nearest existing ones are `milk.production.*`, which govern
recording what the animals produced, and `milk.reconciliation.view`, which is a
read permission.

Borrowing `milk.production.create` would silently redefine it: everyone who may
record production would also be able to record wastage, and there would be no way
to separate the two without a migration and a re-grant.

**Decision.** Four permissions added, taking the catalogue from 65 to **69**:

| Permission | Governs |
| ---------- | ------- |
| `milk.usage.view` | reading the usage screen and its history |
| `milk.usage.create` | recording internal usage |
| `milk.usage.cancel` | withdrawing a recorded usage |
| `milk.adjustment.cancel` | withdrawing an authorised adjustment |

There is deliberately no `milk.usage.update`: usage is corrected by cancelling and
re-entering (D33), so an update permission would gate a route that does not exist.

`milk.adjustment.cancel` is separate from the existing `milk.adjustment.create`
because granting milk and withdrawing milk that has since been distributed are
different decisions with different consequences.

Starting grants, following the principle already set by the existing matrix:

| Role | Gets |
| ---- | ---- |
| Owner | all four, via its catalogue diff |
| Manager | `milk.usage.view`, `milk.usage.create` — running the farm includes recording wastage |
| Data Operator | `milk.usage.view`, `milk.usage.create` — daily entry is its whole job |
| Accountant, Partner, Viewer | `milk.usage.view` only |
| Nobody but Owner | either `cancel` permission |

**Consequences.**

- Cancellation stays with the Owner, consistent with the existing exclusions:
  Manager already holds none of `milk.sale.cancel`, `milk.adjustment.create` or
  the rate override, because those are the ways to depart from recorded truth.
- Super Admin receives all four through ordinary seeding. There is no role bypass,
  so they grant nothing until the seeder runs (D19).
- Adjustment *reading* is gated by `milk.adjustment.create` rather than a separate
  view permission, because the list is the record of exceptions somebody has
  claimed and there is no audience for it that should not also be able to record
  one. The audit log is the read-only view of that history, behind `audit.view`.
- Four new identifiers is four more things to keep in the role editor and the
  documentation. Accepted: the alternative was one permission quietly meaning two
  different capabilities.

---

## D35 — Availability is decided inside the transaction, and no ordinary workflow can leave a negative remainder

**Date:** 2026-09-27 · **Phase:** 3 · **Status:** Accepted

**Context.** MASTER_SPEC section 15 requires that allocation beyond available milk
be blocked for normal users, and that exceeding it be possible only through an
authorised adjustment carrying a reason. Phase 3 has one kind of allocation —
internal usage — but Phases 4 and 5 add customer deliveries, Mandali and vendor
sales. A rule re-implemented per module is a rule that will differ per module, and
the difference gets found by a farm distributing milk it never had.

There is also a race. "Read the remaining figure, then insert" is not a guard: two
people each see 2.000 litres left, each records 2.000, and the shift ends up
2.000 litres over-allocated with both saves looking legitimate.

**Decision.** One service, `App\Services\Milk\MilkAvailability`, decides whether
milk may be allocated, and **the check runs inside the writing transaction, after a
`lockForUpdate()` on the shift's existing rows** — never before the transaction
opens.

Four routes to a negative remainder are closed:

| Attempt | Outcome |
| ------- | ------- |
| Usage exceeding available milk | refused; nothing written |
| Usage against a shift with no production | refused, with a different message — the fix is to enter production, not to reduce the quantity |
| A decrease adjustment below what is already allocated | refused |
| Cancelling an increase that allocations depend on | refused, naming the usage that has to go first |

`MilkAvailability` **never creates an adjustment.** When milk runs short it refuses
and says so. Making more milk available is a separate, audited act by someone
holding `milk.adjustment.create`, with a stated reason.

**Consequences.**

- Phases 4 and 5 call `assertCanAllocate()` and inherit the rule, including the
  lock, rather than writing their own version of it.
- There is no "ignore availability" checkbox on the usage form. The override is a
  different workflow with a different permission, which is what makes the
  authorisation requirement mean something. An auto-created adjustment would
  reduce it to a formality and produce the silent balancing record the
  specification forbids.
- A negative remainder is still *representable*, and the reconciliation screen
  renders it in red. That is deliberate: historical data, a direct database fix, or
  a future import could produce one, and a screen that refused to display it would
  hide the problem rather than the state being impossible.
- The refusal distinguishes "no production entered" from "not enough milk" because
  the two need different actions from the user.
- **Tested limitation.** True concurrency is not reproducible in a single-connection
  Pest test that is itself wrapped in a `RefreshDatabase` transaction. The suite
  asserts the structural property instead — that a locking read of the shift
  precedes the insert, and that both sit inside one transaction — rather than
  weakening the lock to make a test easier. Recorded in
  [TESTING.md](TESTING.md).

---

## D36 — A missing production record is a distinct state from a recorded zero

**Date:** 2026-09-27 · **Phase:** 3 · **Status:** Accepted

**Context.** MASTER_SPEC section 15 requires the reconciliation screen to display
"Production not entered" rather than pretending production is zero. It is easy to
read that as a display rule. It is not: the two situations have the same quantity
and different meanings, and every layer between the table and the screen has a
chance to collapse them.

- Nobody has recorded the shift. Nothing may be allocated against it, and the
  person looking at the screen has work to do.
- Somebody recorded the shift and stated it produced nothing. That is a complete
  answer, and the screen should show 0.000.

**Decision.** The distinction is carried explicitly at every layer, and is never
inferred from a quantity.

| Layer | How |
| ----- | --- |
| Schema | The **presence of the row** is the fact. One row per farm, date and shift (D30), so it answers for the whole shift rather than per milk type. |
| Engine | `MilkReconciliation::$productionEntered`, a boolean sitting beside `$production`, so a caller cannot read the quantity without the flag being available. |
| Allocation | `MilkAvailability` refuses with a different message when production was never entered, because the remedy is different. |
| View | `<x-litres>` renders `null` as an em-dash, never `0.000`; the reconciliation screen shows a warning panel and the production matrix badges the shift. |

`productionEntered` is a property of the result object rather than something a
caller computes, specifically so that the Phase 4 sale validation cannot forget it.

**Consequences.**

- Entering zero production is a supported, meaningful action, and a shift may
  legitimately be zero for one milk type and positive for the other.
- Both milk types report `productionEntered = true` whenever the shift row exists,
  which is only expressible because production is one row per shift.
- A quantity of `0.000` in the database never means "unknown". Anything that needs
  to mean "unknown" must be the absence of a row.
- The rule is asserted as a matrix in `MilkReconciliationTest`, including the case
  where a missing row and a recorded zero produce identical quantities and opposite
  flags.

---

## D37 — Milk quantities are exact decimal strings, and a malformed quantity throws

**Date:** 2026-09-27 · **Phase:** 3 · **Status:** Accepted

**Context.** Milk needs what money already has: `DECIMAL` columns and exact
arithmetic. The reconciliation screen is the reason. Given 10.000 litres allocated
as 3.333 + 3.333 + 3.334, remaining must be exactly 0.000. In binary floating point
it is −0.00000000000000044409, and that residue makes the "remaining must not go
negative" rule reject a day that balances perfectly — while a residue the other way
would let 0.001 litres be allocated out of nothing.

**Decision.** `DECIMAL(10,3)` in the database, `decimal:3` casts on the models, and
`App\Support\Quantity` for all arithmetic: bcmath at scale 3, on strings. No litre
value is ever a PHP float. Deliberately a handful of static helpers rather than a
units library — the only unit is the litre and the only scale is three.

`Quantity::of()` treats `null` and `''` as absent and returns zero, because callers
legitimately pass an unset column or a `SUM()` over no rows. **Anything else
non-numeric throws** `InvalidArgumentException`.

**Consequences.**

- Three-decimal precision is enforced at the edge as well as the centre: the Form
  Requests reject a fourth decimal place rather than letting MySQL round 1.2345 to
  1.235, which would make the arithmetic exactly right about a figure nobody
  entered.
- A `-0.001` remainder stays `-0.001` all the way to the validation decision. It is
  never rounded to zero first.
- The JavaScript side of the production matrix is display only; every figure is
  recomputed server-side.

**Correction (2026-09-27).** `Quantity::of()` originally returned zero for *any*
non-numeric input. That was found in Pass 2 while pinning the helper's contract in
tests, and it was a real trap rather than harmless defensiveness: `'12,500'` — a
comma decimal separator, entirely plausible from an import or a pasted figure — would
have silently recorded 0.000 litres instead of twelve and a half. Every current call
site passes a validated numeric, a decimal column or a `SUM()`, so nothing was
misbehaving in practice; the exposure was the next caller. In a module built on the
rule that a missing quantity is not a zero quantity, quietly inventing a zero is the
same mistake wearing a different hat, so a malformed quantity now fails at the call
site.

---

## D38 — Grid sale idempotency is a generated column, so other sale sources stay unconstrained

**Date:** 2026-09-28 · **Phase:** 4 · **Status:** Accepted

**Context.** MASTER_SPEC section 20 requires re-saving a day in the Customer Daily
Entry grid to update the existing sales rather than duplicate them, for the identity
*customer + date + shift + milk type + grid source*. It names a deterministic unique
key as the way to guarantee it.

`milk_sales` is one table for every channel, though, and the constraint is only wanted
for grid rows. A plain unique index on those columns would bind every source — and a
Phase 5 generic sale form may legitimately record two separate vendor sales in one
shift. MySQL has no partial indexes, so "unique where source = 'customer_daily_grid'"
cannot be expressed directly.

**Decision.** A generated column holds the identity **only for grid rows** and NULL for
everything else, with a unique index on it:

```sql
daily_grid_key = CASE WHEN source = 'customer_daily_grid'
    THEN CONCAT_WS('|', farm_id, buyer_id, sale_date, shift, milk_type)
    ELSE NULL END
```

MySQL treats NULLs as distinct in a unique index, so grid saves are exactly idempotent
while no other source is restricted. It is the same technique the
one-primary-farm-per-business constraint uses (D16), and it is `VIRTUAL` for the same
reason: MySQL forbids some referential actions on the base columns of a stored
generated column.

**`farm_id` is part of the identity**, which extends the literal minimum the
specification names. Every operational record carries a farm and more farms are
supported, so two farms must be able to deliver to the same customer on the same day
without colliding. Leaving it out would make the constraint wrong the moment a second
farm exists, and widening a unique key later is a migration against live data.

**Consequences.**

- Re-saving a day updates rows rather than duplicating them, guaranteed by the engine
  rather than by the application remembering to check.
- A cancelled grid row still occupies its identity, so re-entering a cleared quantity
  **reactivates that row** rather than inserting a second one. The withdrawal and the
  re-entry both live in the audit log, which is where that history belongs; allowing a
  second row would also allow the duplicates the key exists to prevent.
- Phase 5's sources are unconstrained by default. If one of them wants the same
  guarantee, it gets its own generated column rather than loosening this one.
- The key's value is human-readable — `1|7|2026-10-10|morning|cow` — which makes a
  constraint violation in a log line immediately diagnosable.

---

## D39 — Quantity reminders are display metadata and nothing may read them as a quantity

**Date:** 2026-09-28 · **Phase:** 4 · **Status:** Accepted

**Context.** MASTER_SPEC section 16 gives each customer-and-milk-type preference a
morning and an evening quantity reminder, and says twice that these are reminders and
not contractual limits. Section 19 adds that they must never prefill the daily entry
fields.

This is easy to read as a UI instruction and it is not one. The moment any helper
returns a reminder as "the quantity for this customer", the next caller uses it, and
the system starts recording deliveries nobody made — invisibly, because the figures
look exactly like real ones.

**Decision.** The rule is enforced structurally rather than by documentation:

| Layer | How |
| ----- | --- |
| Columns | `morning_reminder_qty` / `evening_reminder_qty` — named reminder, not quantity |
| Model | `CustomerPreference::morningReminder()` / `eveningReminder()` and **no** `quantityFor()`, `defaultQuantity()` or `dailyQuantity()` |
| Eligibility | `DeliveryEligibility::reminderFor()` returns the *preference*, not a number |
| UI | The daily entry field starts empty; the reminder is helper text beside it |
| Tests | Reflection asserts no method with a quantity-shaped name exists on either class |

A reminder of 5 litres on a customer with no entry produces no sale, and a test says so
directly.

**Consequences.**

- Copy Previous Day (Pass 2) copies *yesterday's actual sales*, never reminders. The
  two are different data and only one of them describes something that happened.
- Nothing automatic writes a sale. There is no observer, no default and no prefill —
  every delivery exists because somebody typed a figure.
- The reflection test looks unusual for a business rule. It is there because this is a
  rule about what code must *not* offer, and the only way to assert an absence is to
  look for it.
- A reminder of zero is normal and means "no usual quantity", not "no milk".

---

## D40 — Withdrawing a payment needs its own permission

**Date:** 2026-09-28 · **Phase:** 4 · **Status:** Accepted

**Context.** MASTER_SPEC's permission catalogue has `customer.payment.create` for
recording money received. It has nothing for withdrawing a recorded payment, which
Phase 4 needs because a cheque bounces or a receipt is entered against the wrong
customer.

**Decision.** `customer.payment.cancel` is added, taking the catalogue from 69 to
**70**. Recording a receipt does not imply withdrawing one.

They are different decisions with different consequences. Recording a payment credits
an account and reduces what a customer owes; withdrawing one reverses money in the
ledger and raises the outstanding balance again, against a record somebody may have
already reconciled. The project already draws this line twice — the Accountant may
record an expense but not cancel one (D15's matrix), and `milk.usage.create` does not
imply `milk.usage.cancel` (D34).

Default grant: **Owner and Super Admin only.** The Accountant holds
`customer.payment.create` and is deliberately excluded from the cancel, which matches
its existing exclusion from `expense.cancel`.

`BuyerPolicy::cancelPayment()` additionally requires the buyer to be a direct customer,
because Mandali and vendor payment workflows arrive in Phase 5 and will need their own
permissions. Without that check the policy would silently pass for a channel whose
cancel permission does not exist yet.

**Consequences.**

- One more identifier to keep in the role editor and the documentation. Accepted: the
  alternative was `customer.payment.create` quietly meaning two capabilities, one of
  which reverses money.
- Phase 5 adds `mandali.payment.cancel` and `vendor.payment.cancel` when those
  workflows exist, and the policy stops needing its channel guard at that point.
- A payment is never deleted. Cancelling it preserves the record, posts exactly one
  reversing debit, and lets the derived outstanding rise again by itself (D21).

---

## D41 — A recorded sale keeps its rate when its quantity is corrected

**Date:** 2026-10-03 · **Phase:** 4 · **Status:** Accepted — **reverses Pass 1
behaviour**

**Context.** `milk_sales.unit_rate` is a snapshot, deliberately with no foreign key to
a price rule (D38's table, documented in DATABASE.md). Pass 1's
`SaveCustomerDailySale::update()` nonetheless **re-resolved** the rate every time a
quantity changed, and its docblock defended the choice: resolution is always by sale
date, so re-resolving the same date can only give the same answer.

That reasoning is wrong, and Pass 2 found the case. Price *periods* open forward only
(BUSINESS_RULES section 3), so a business default cannot be back-dated — but a
**buyer override** can be created with an `effective_from` covering a date that
already has deliveries. Nothing forbids it, and it is a reasonable thing to do when a
customer's agreed rate is entered late. Under Pass 1's behaviour, the next time
anybody opened that day to fix a typo in the litres, every edited delivery would
silently re-price.

**Decision.** An existing grid row keeps the rate stored on it. A quantity edit
recomputes `amount` from the row's **own** `unit_rate`; only a row that does not yet
exist asks `PriceResolver`.

This applies to reviving a cancelled row too. The sale date has not changed, so the
rate that applied to that date is the one already on the row, and the audit log
carries the withdrawal and the re-entry as separate events either way.

**Consequences.**

- A snapshot is now actually immutable, which is what the word was always claiming.
  Correcting the quantity of a billed delivery changes the quantity and nothing else.
- Correcting a **rate** is therefore a different operation, and one that does not
  exist. There is no screen for it, by design: re-pricing history needs its own
  workflow, its own permission and its own audit trail, and inventing one as a side
  effect of the daily grid would be the opposite of that.
- A sale whose price rule is later deleted stays editable and cancellable, because its
  own rate is enough to re-cost it. The grid relies on this — a row with no current
  price but an existing sale is not frozen.
- Morning and evening on one row can legitimately hold different rates. The grid shows
  both rather than picking one.

---

## D42 — A day's deliveries are applied releases-first, claims-second

**Date:** 2026-10-03 · **Phase:** 4 · **Status:** Accepted

**Context.** Save Day posts a whole grid, and every cell is checked against the milk
still unallocated for its shift (D35, through `MilkAvailability`). Checking cells in
payload order breaks on a case that is not exotic at all: a fully allocated shift
where the operator moves two litres from one customer to another. The day's final
allocation is unchanged and perfectly legal, but if the increase is applied first it
is measured against milk the decrease has not released yet, and the save is refused
for a reason the operator cannot act on.

Relaxing the per-cell check was the obvious fix and the wrong one — it is the rule
that stops a farm distributing milk it never had.

**Decision.** `SaveCustomerDailyDeliveries` classifies every changed cell by its
effect on allocated milk and applies them in two phases:

1. **releases** — cancellations and decreases;
2. **claims** — creations and increases.

Within each phase the order is buyer id, then milk type, then shift. Unchanged cells
are not touched at all.

**This does not weaken the rule.** Each claim is still checked against live figures,
and the last claim applied sees every other cell at its final value, so a day whose
total genuinely exceeds the milk is still refused — in full, since the whole request
is one transaction.

**Consequences.**

- A legal day always saves, whatever order the browser happened to send its rows in.
  Two tests assert exactly that, with the payload reversed.
- The deterministic within-phase order also fixes lock acquisition order, so two
  concurrent Save Day requests queue rather than deadlocking on rows taken in
  opposite sequences.
- Skipping unchanged cells is load-bearing rather than an optimisation: a no-op update
  would write an audit record saying a delivery changed when it did not, and a day
  re-saved unchanged would fill the trail with noise. Idempotency is asserted on the
  audit count, not just on the row count.
- The classification lives in the day action, not in `SaveCustomerDailySale`. A single
  cell has no ordering problem, and pushing phase logic into it would complicate the
  one piece every sale workflow shares.

---

## D43 — A grid row may show two rates, because it holds two sales

**Date:** 2026-10-03 · **Phase:** 4 · **Status:** Accepted

**Context.** A Customer Daily Entry row is one customer and one milk type, but it
holds **two** sale records — morning and evening — and D41 keeps each one's rate
exactly as it was recorded. Those two facts together mean the shifts can be priced
differently, and both be right.

It is not a contrived case. A customer-specific rate agreed late, entered with an
`effective_from` that covers a date already delivered on, produces it immediately:
the morning keeps ₹70.00 and a new evening resolves ₹72.00. Price *periods* open
forward only, but a buyer override is not a period on the same rule and nothing
forbids it covering a past date.

The original Pass 2 grid had one "Rate" column per row. With two rates that column
is wrong about half the row, and the row total stops being reproducible: 2.000 L
costing ₹142.00 when the column says ₹70.00 looks like an arithmetic bug.

**Decision.** The rate is held, shown and applied **per cell**, not per row.

- `rateFor($shift)` is the real accessor; `displayRate()` returns a single figure
  only when both shifts genuinely share one, and null otherwise.
- The Rate column shows one figure in the ordinary case and a compact `M … / E …`
  pair when they differ, so the column is never a claim about the wrong shift.
- The row total sums each shift priced by its own rate.
- The browser is given a rate per cell in integer paise, so the live totals add two
  different prices exactly.
- The customer statement already carried a `rates` set and renders "mixed" rather
  than choosing one; that behaviour is now covered by a test rather than assumed.

**Editability is per cell for the same reason.** A row can hold a morning sale whose
price rule has since been withdrawn: the morning is still correctable and clearable
against its own snapshot, while a new evening cannot be priced at all. Closing the
whole row would take away the ability to fix a recorded delivery; opening it would
invite an entry the server is bound to refuse. So the question is answered one cell
at a time, and Copy Previous Day stages nothing into a cell that could not be saved.

**Consequences.**

- The grid is slightly wider in a rare case and unchanged in the common one. The
  alternative — one rate per row — is narrower and sometimes false, which is not a
  trade worth making on a screen about money.
- Nothing normalises two historical snapshots to match each other. Editing one
  shift's quantity provably leaves the other shift's rate, amount and quantity
  untouched.
- `displayRate()` returning null now means "no single rate applies", which is two
  different situations — the rates differ, or one shift cannot be priced. The view
  distinguishes them; callers that want a figure must ask per shift.

---

## D44 — Fat and SNF are recorded, and nothing reads them

**Date:** 2026-10-03 · **Phase:** 5 · **Status:** Accepted

**Context.** A Mandali pays on milk quality, and the industry norm is a rate derived
from fat and SNF. MASTER_SPEC section 22 is explicit that V1 does **not** do this:
"Do NOT implement automatic fat/SNF pricing formulas in V1. Fat and SNF are recorded
for reference/reporting. Rate is manually entered."

The risk is not that somebody disagrees with that today. It is that a fat percentage
sitting next to a rate on the same form is an obvious invitation, and a future
contributor "finishing" the feature would silently change what every Mandali delivery
costs.

**Decision.** The readings reach the row and nothing else.

- `amount` is only ever computed as `quantity x unit_rate`, in `CreateMilkSale` and
  `UpdateChannelSale`. Neither takes a reading as an argument to that calculation.
- `MilkSale::expectedAmount()` — the method a test uses to re-derive an amount —
  takes the quantity and the rate, and its docblock says why it must never take more.
- The rate is manually entered for a Mandali, and that is not an override: it is the
  workflow, so it needs no special permission (see D45).
- A regression test named `mandali fat and snf never calculate the rate in v1` records
  two deliveries with identical quantity and rate and wildly different readings, and
  asserts the amounts are identical. A second changes the readings on a saved delivery
  and asserts the amount does not move. A third scans the pricing sources for
  arithmetic anywhere near a reading.

**Validation bounds**, which the specification does not give: fat and SNF are each
accepted from 0.00 to 15.00 at two decimal places. Cow milk runs about 3–5% fat and
buffalo about 6–8%; SNF is normally 8–9.5%. The ceiling is far above any real reading
on purpose — a range tight enough to catch every typo would also reject legitimate
outliers, and the two errors do not cost the same. A refused real reading blocks the
day's work; an implausible stored one is visible on screen and corrigible.

Fat is **required** for a Mandali collection, because it is the headline figure on the
dairy's own slip and a collection recorded without it cannot be checked against that
slip later. SNF is optional, because not every collection point measures it.

**Consequences.**

- The rate column is typed, not calculated, and the form says so.
- When fat/SNF pricing is wanted it will be a deliberate feature with its own rate
  bands, effective dates and audit — not a formula quietly added to an existing save
  path. The readings are already stored, so the history will be there when it arrives.

---

## D45 — Milk-sale rate overrides get their own permission

**Date:** 2026-10-03 · **Phase:** 5 · **Status:** Accepted

**Context.** A vendor has a configured rate, and MASTER_SPEC section 24 allows an
"authorized manual rate override". The catalogue already held
`milk.customer_delivery.override_rate`, which belongs to the Customer Daily Entry grid
and is deliberately unused there because re-pricing a customer delivery needs a
workflow nobody has designed.

Borrowing it for vendor work would have made one permission mean two capabilities in
two screens. Anyone granted it so they could agree a one-off vendor price would
silently also be able to re-price customer deliveries the day that screen appears.

**Decision.** `milk.sale.override_rate` is added. With `mandali.payment.cancel` and
`vendor.payment.cancel`, which follow D40's reasoning for their own channels, the
catalogue goes from 70 to **73**.

It governs departing from a resolved rate, and only that:

| Situation | Needs the permission? |
| --------- | --------------------- |
| A vendor sale with no typed rate, taking the configured one | No |
| A typed rate **equal** to the configured one | No — nobody departed from anything |
| A typed rate **different** from the configured one | **Yes**, plus a stated reason |
| A typed rate where **none** is configured | **Yes**, plus a reason |
| A Mandali or generic sale, where typing the rate is the workflow | No |
| Changing the rate on an already-recorded sale, any channel | **Yes**, plus a reason |

Two rows are worth justifying. The fourth: "no rate configured" is also what a
mistyped buyer looks like, so filling that gap is a decision about money rather than a
default. The sixth: a Mandali rate needs no permission at creation but does at
correction, because the first is entry and the second is a departure from what was
already agreed.

An override stores `resolved_rate` — what the resolver said — beside the applied
`unit_rate`, plus the reason. A null `resolved_rate` therefore *is* the "no override
happened here" flag, and the row is legible without reading the audit log.

Default grant: **Owner and Super Admin only**, consistent with
`milk.adjustment.create` and `customer.payment.cancel`, the other permissions that let
a user depart from the recorded truth.

**Consequences.**

- A vendor with no configured rate cannot be sold to by a Manager until somebody sets
  a rate in Settings. That is the intended friction: a standing rate is a decision,
  and recording sales against a buyer who has none is how prices get invented.
- `milk.customer_delivery.override_rate` stays seeded and checked by nothing.

---

## D46 — A finalized settlement is a snapshot, and closes its period

**Date:** 2026-10-03 · **Phase:** 5 · **Status:** Accepted

**Context.** A Mandali settles monthly: the dairy sends a statement, it is compared
with what the system recorded, and the difference is accounted for. MASTER_SPEC
section 23 settles the central question — "do not silently modify historical milk
rates... record an explicit buyer balance/settlement adjustment" — but leaves several
things open, and each is a way to lose money quietly.

**1. The figures are frozen at finalization.** `milk_quantity` and `expected_amount`
are computed from the period's active Mandali sales **at their own stored rates**, then
written onto the settlement. They are not a cache of a query that could be re-run:
they are what was agreed. A draft shows live figures instead, which is safe precisely
because a draft has no accounting effect.

**2. Periods may not overlap.** The specification does not address it. Left undefined,
two settlements covering the same day would each include that day's deliveries in
their expected amount, so the same milk could be settled, adjusted and paid for twice,
with nothing in the data to show why the balance was wrong. So: a Mandali may not have
two non-cancelled settlements whose periods overlap, inclusive at both ends. Cancelled
ones are ignored, which is what makes a mistaken settlement fixable. Enforced in the
creating transaction after a locking read, because MySQL cannot express "no
overlapping ranges".

**3. A settled period is closed to sale corrections.** Correcting a delivery inside a
finalized settlement has two possible outcomes and both are bad silently.
Recalculating the settlement changes an agreed figure behind the user's back and
orphans the adjustment already posted against the old one. Leaving it alone produces a
statement whose own underlying sales no longer add up to it — exactly what a reader
will try to check when they question it.

So the correction is **refused** until the settlement is cancelled, and the order of
operations becomes explicit and auditable: withdraw the settlement, fix the delivery,
settle the period again. The refusal names the period and says what to do. Adding a
delivery to a settled period is refused for the same reason.

**4. Cancelling a settlement does not reverse receipts.** A `BuyerPayment` records that
cash actually arrived. Cancelling a settlement is a statement about an agreement, not
about money received — so a settlement with active linked payments cannot be
cancelled; the receipts must be withdrawn first, through the workflow that exists for
that and has its own permission and ledger reversal. Its own adjustment, by contrast,
*is* cancelled with it: a correction for a settlement that no longer stands would be
money owed for a reason the ledger cannot explain.

**5. The two payment statuses are derived.** `PartiallyPaid` and `Paid` are not states
a user chooses; they follow from the sum of active receipts.
`SettlementStatus::allowedTransitions()` refuses to be handed either directly, and
`SettlementStatusSync` recomputes the stored value after every receipt and every
withdrawal. Cancelling a receipt therefore moves a settlement back from `Paid` to
`PartiallyPaid` on its own, and no stored total can drift from the receipts — the same
rule as every other balance in this project.

`Cancelled` is terminal. A period that needs settling again gets a new settlement, so
the history of what was agreed, withdrawn and then agreed differently survives.

**Consequences.**

- Finalizing twice is refused, and a conditional unique index on
  `buyer_balance_adjustments` refuses a second adjustment for one settlement even if a
  retry raced the status check.
- A payment is capped twice: at the buyer's outstanding and at what the settlement
  still owes. Without the second cap, a Mandali with two finalized months could have
  one recorded as overpaid while the overall balance still looked right.
- The seeded demo settlement is deliberately left as a draft, so a developer
  correcting seeded data is not blocked by a settlement they never created.

---

## D47 — A receivable adjustment is a direction and a positive amount

**Date:** 2026-10-03 · **Phase:** 5 · **Status:** Accepted

**Context.** `buyer_balance_adjustments` fills the third term of the outstanding
formula, which Phase 4 carried as a named zero. The question was how to persist a
correction that can go either way.

**Decision.** `direction` (`increase` / `decrease`) plus an always-positive `amount`,
exactly as D31 settled for milk adjustments — and deliberately **not** the same enum.
`AdjustmentDirection` is about litres: its labels come from the `milk.*` translations
and its sign feeds the reconciliation formula. Sharing it would mean a milk direction
quietly acquiring money semantics, and a form asking about "milk adjustment direction"
when the subject is a rupee difference on a statement. Two small enums with the right
vocabulary each beat one that has to be read twice.

The sign exists only in `signedAmount()` and in the outstanding query. No column ever
holds negative money, so no form asks for it and no validation rule has to permit it in
exactly one table.

**What an adjustment is not**, each enforced rather than merely intended:

- **Not cash.** It posts nothing to a financial account and appears in no cashbook. A
  test asserts zero ledger entries after one.
- **Not milk.** No litre, no reconciliation figure and no sale changes. A test compares
  the whole reconciliation result before and after.
- **Not automatic.** Nothing creates one to make a total come out even. In Pass 1 the
  only caller is settlement finalization, where the reason is the settlement.

**A decrease may not push a buyer into credit.** MASTER_SPEC section 27 permits a
negative balance only through an explicit authorised workflow, and none exists —
allowing it here would invent one by accident, and the buyer's ledger would show them
in credit for reasons nobody designed. Exactly the outstanding is allowed; a paisa more
is refused. An increase is never refused, because somebody owing more is always
representable.

**Consequences.**

- A wrong correction is withdrawn, not corrected by a second correction: cancelling it
  stops it counting, and the balance moves back by itself.
- The credit-balance workflow, if it is ever wanted, has a clear shape — it would relax
  exactly one check, in one place, with its own permission.

---

## D48 — Custom-channel buyers get one screen and the customer permission family

**Date:** 2026-10-04 · **Phase:** 5 · **Status:** Accepted

**Context.** Phase 5 Pass 1 gave Mandalis and vendors a channel list and a trade
profile, and gave the generic sale form the ability to sell milk to a buyer in an
administrator-created channel — a hotel, a sweet shop, a bulk buyer. Those sales raise
a receivable like any other. But a custom-channel buyer had nowhere to show it: the
Phase 2 `buyers.show` page is buyer master data and price rules, and the two channel
profiles each serve one fixed slug. The balance could be created and never seen,
never reconciled and never settled, while `buyers.payments.store` sat there with
nothing linking to it.

A receivable with no screen is worse than no receivable. It is money the business is
owed, recorded by the application, invisible in it.

**Decision.** One list, one profile and one statement for **every** administrator-
created channel, served by `OtherBuyerController`, and authorised by the `customer.*`
family.

Two parts, each with an alternative that was rejected:

**One screen for all custom channels, not one per channel.** A custom channel is
created by the user at runtime, so there is no slug to name in a controller and no
fixed number of them. `ChannelBuyerController` therefore reads a **null** channel slug
as "every channel that is not one of the three the specification names", using the
`customChannel()` scope that already defines that set negatively (and so picks up a
channel created tomorrow without a code change). The alternative — a dynamic route per
channel — would make the sidebar grow without limit and give each new channel a screen
nobody had reviewed.

**The `customer.*` family, not a new one.** This follows D26 rather than extending it:
a custom channel already resolves to `customer.view`, `customer.create` and
`customer.update`, so `customer.payment.create` and `customer.payment.cancel` are the
consistent continuation. The two alternatives are both worse:

- *A permission per administrator-created channel.* It cannot be seeded, because the
  channels do not exist when the roles are. Every new channel would need a role edit
  before anyone could use it, and a forgotten edit looks like a broken screen.
- *Borrowing the Mandali or vendor family.* It would hand settlement authority
  (`mandali.settlement.manage` sits in the same family) to whoever may be paid by a
  sweet shop. A permission family is a blast radius, and a commercial buyer with no
  settlement workflow has no business inside the one that has it.

Commercially these buyers *are* direct buyers — the generic sale form treats them
exactly as it treats a direct customer — so the family matches the domain rather than
merely being convenient.

**Enforced, not merely intended.** `PermissionEnforcementTest` asserts that every
catalogued permission is actually checked somewhere, so a family that stopped being
used would fail rather than quietly grant nothing; and
`BuyerPaymentChannelTest` asserts each channel's payment permission opens that channel
and no other — `mandali.payment.create` does not pay a sweet shop, and
`customer.payment.create` does not pay a Mandali.

**Settlements stay absent.** Only a Mandali settles a period. The shared profile draws
that section from the buyer itself, so the custom profile simply does not render it —
absent rather than present and empty.

**Consequences.**

- Three channel profiles now share one controller, one set of Blade partials and one
  ledger service. A fourth channel kind would declare four methods.
- A custom channel's buyers are reachable by anyone who can see direct customers, which
  is stated in `docs/PERMISSIONS.md` so it is a decision rather than a surprise.
- If a business ever needs a custom channel walled off from its direct customers, the
  change is one method — `BuyerPermissions::familyFor()` — and a seeded permission, not
  a new screen.

---

## D49 — The period statement is a view, and the ledger table is shared with it

**Date:** 2026-10-04 · **Phase:** 5 · **Status:** Accepted

**Context.** MASTER_SPEC section 23 asks for a Mandali period statement: the milk, the
expected amount, the corrections, the receipts and the balance for a period, with the
transactions behind them. Phase 5 already had two things in that area — the trade
profile's ledger and the settlement record — and the risk was building a third
implementation of the same arithmetic, or worse, a table to store a statement in.

**Decision.** The statement is **a view over canonical records**, sharing the ledger
table with the profile. Three distinct things, kept distinct:

| Concept | What it is |
| --- | --- |
| the **ledger** (on the profile) | the running account history, for answering "where does this balance come from" |
| a **settlement** | a business record freezing one period's expected sales and the dairy's statement amount, posting any difference |
| the **statement** | a readable report of one period: what was collected, what was corrected, what was received |

A settlement is a decision with accounting consequences. A statement is a way of
looking. They share `BuyerTradeLedger` and `BuyerOutstandingService` — the arithmetic
is the same arithmetic — and nothing else. There is **no statement table**: a second
place to store a total is a second place for it to be wrong, and a report-only balance
that disagrees with the profile would be impossible to argue with.

**The transaction table is literally the same partial.** `buyers/channel/_ledger`
takes three optional flags (`ledgerTitle`, `showFilter`, `showTotals`) so the statement
can head its own page and carry its own period controls while the rows render once, in
one place. A second copy of that table would have drifted at the first change to how a
cancelled sale or a settlement-linked receipt is shown.

**Two deliberate details.**

- **The summary shows the whole outstanding, not the period's movement.** A month that
  happens to be paid in full would otherwise read as "nothing is owed" while the
  previous month's balance sat untouched above it. The period figures and the balance
  are labelled differently for that reason.
- **The opening balance is always shown, even for an empty period.** A quiet month on
  an account that is still owed money must not render as an empty account, so the table
  is drawn with its opening and closing rows and a single "no transactions" line
  between them.

**Period selection is forgiving, deliberately.** `?month=YYYY-MM` is the normal way in,
with `from`/`to` for an awkward period; an unparseable month shows the current month
rather than a validation error, because these are navigation links and a 422 on a
chevron is user-hostile. The month part is matched as `01`–`12` rather than any two
digits, because Carbon rolls `2026-13` forward to January 2027 — a typo in the address
bar would otherwise have shown a different year's figures under the heading it was
given. When an explicit `from` is supplied without a month, the heading and the arrows
follow the dates actually shown.

**Export is Phase 9.** On-screen only, because a report that exists in one format is
more useful than one that waits for three.

**Consequences.**

- Vendors got the statement for free, which is correct: a vendor account is read the
  same way, it simply has no settlements section.
- Every figure on the statement is asserted against a hand calculation in
  `MandaliStatementTest`, not against the service that produced it.
- A PDF or Excel export in Phase 9 renders the same arrays; it does not recompute
  anything.
