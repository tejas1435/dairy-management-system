# Progress

Continuity file. A new session should be able to read `CLAUDE.md`,
`docs/MASTER_SPEC.md`, this file and `docs/DECISIONS.md` and continue without
further context.

**Last updated:** 2026-10-04

---

## Current status

| | |
| - | - |
| **Current phase** | Phase 5 — Mandali, vendors, other buyers and settlement, **complete and committed** |
| **Next step** | Phase 6 — Animals |
| **Branch** | `main` |
| **Suite** | **1,668 tests, 6,311 assertions, all passing, 0 warnings** |

| Commit | What |
| ------ | ---- |
| `741365a` | baseline — clean Laravel 13.32.0 skeleton + specification |
| `b671c48` | Phase 0 — architecture and tooling |
| `0711e95` | Phase 1 — foundation |
| `ed7546b` | fix: enforce permission-only authorization |
| `0dd36d1` | Phase 2 — financial foundation |
| `b374509` | Phase 3 — milk reconciliation |
| `c73ec26` | Phase 4 — direct customers |
| *(this one)* | Phase 5 — Mandali, vendors and other buyer channels |

---

## Environment

PHP 8.4.25 · Composer 2.10.2 · Laravel 13.32.0 · MySQL 8.4.11 · Node 26.9.0 ·
npm 11.19.0 · Git 2.55.0. All commands run through `PATH` (D1).

---

## Database state

| Schema | Purpose |
| ------ | ------- |
| `dairy_management` | Development |
| `dairy_management_test` | Automated tests only, wiped every run |

Both `utf8mb4` / `utf8mb4_0900_ai_ci`.

**Migrations applied (32).** Phase 1 added 3 to Laravel's and Spatie's 4; Phase 2
added 13; Phase 3 added 3; Phase 4 Pass 1 added 5; Phase 5 Pass 1 added 4:

```
0001_01_01_000000_create_users_table
0001_01_01_000001_create_cache_table
0001_01_01_000002_create_jobs_table
2026_09_21_001127_create_permission_tables
2026_09_21_100000_create_businesses_table                 <- Phase 1
2026_09_21_100001_create_farms_table                      <- Phase 1
2026_09_21_100002_add_foundation_columns_to_users_table    <- Phase 1
2026_09_22_100000_create_audit_logs_table                 <- Phase 2
2026_09_22_100100_create_payment_methods_table
2026_09_22_100101_create_financial_accounts_table
2026_09_22_100102_create_financial_ledger_entries_table
2026_09_22_100103_create_expense_categories_table
2026_09_22_100104_create_partners_table
2026_09_22_100105_create_partner_contributions_table
2026_09_22_100106_create_expenses_table
2026_09_22_100107_create_funding_allocations_table
2026_09_22_100108_create_sales_channels_table
2026_09_22_100109_create_buyers_table
2026_09_22_100110_create_milk_price_rules_table
2026_09_22_100111_create_buyer_price_rules_table
2026_09_27_100000_create_milk_productions_table           <- Phase 3
2026_09_27_100001_create_milk_usages_table
2026_09_27_100002_create_milk_adjustments_table
2026_09_28_100000_add_customer_columns_to_buyers_table    <- Phase 4
2026_09_28_100001_create_customer_preferences_table
2026_09_28_100002_create_customer_pauses_table
2026_09_28_100003_create_milk_sales_table
2026_09_28_100004_create_buyer_payments_table
2026_10_03_100000_add_phase5_columns_to_milk_sales_table  <- Phase 5
2026_10_03_100001_create_buyer_settlements_table
2026_10_03_100002_create_buyer_balance_adjustments_table
2026_10_03_100003_add_settlement_to_buyer_payments_table
```

Verified from empty with `migrate:fresh --seed`.

**Seeded:** 70 permissions, 7 roles, 1 business, 1 primary farm, 5 payment
methods, 10 expense categories, 3 sales channels, 2 financial accounts,
3 development partners, 2 opening milk prices, 7 milk production records,
3 internal usages, 1 adjustment, 20 direct customers, 24 customer preferences,
3 pauses, 2 buyer price overrides, 42 customer deliveries (50.000 L) and
2 customer receipts with their ledger credits. Re-running `db:seed` produces
identical counts and identical money — asserted by a test, not merely observed.

**The deliveries go in through the real domain action**, not through `insert()`, so
availability, pricing, the rate snapshot, the grid identity and the audit record all
apply exactly as they would to an operator typing the day in. They sit on the two
dates the Phase 3 seed recorded both shifts for, well inside what was produced; the
deliberately unentered evening is left alone; paused, archived and unstarted
customers get nothing; and **every quantity differs from that customer's reminder**,
because a demo dataset where the two match makes the most important rule in the
phase invisible. `SeedIntegrityTest` asserts all of it.

No demo **financial** transaction data: invented expenses and contributions would
make a fresh installation look as though it had a financial history it does not
have. The Phase 3 milk data is a different case — it carries no money and no
counterparty, so a sample week makes the reconciliation screen explorable without
asserting anything about the books. It is internally consistent, and yesterday's
evening shift is deliberately left unentered so the "Production not entered" state
is visible on a fresh installation. No test reads any of it.

**Not created yet:** `animals` (Phase 6), `employees` and employee finance (Phase 7),
notifications (Phase 8). Nothing depends on them. `expenses.animal_id`,
`expenses.employee_id` and `expenses.attachment_path` arrive with their tables, as
nullable additions — the same deferral (D13) that held `buyer_payments.buyer_settlement_id`
back until Phase 5 built the table it points at, which it now has.

---

## Completed in Phase 2

### Audit foundation, built first

Everything else in Phase 2 writes through it. `App\Services\AuditLogger` is the
only writer: diff-only records, central redaction by whole key *and* substring,
append-only storage, and the audit write inside the transaction of the change it
describes (D28).

Wired into the Phase 1 mutations that were live and unaudited: role permission
changes, user activation, user role assignment, business settings, and both farm
actions. **No synthetic records were backfilled** — the trail begins where the
service does.

Viewer at `/admin/audit`, behind `audit.view`, with a detail page.

### Morph map

15 aliases registered, up from 1 at the end of Phase 1. The map is *enforced*, so
an unmapped model raises rather than quietly persisting a class name, and an alias
is added only when its model exists (D10). Tests assert that no future-phase alias
is registered early and that no alias is a PHP class name.

### Financial accounts and the ledger

- `financial_accounts` with `type`, `opening_balance` and **no stored balance
  column**. A balance is `opening + credits − debits`, derived.
- `financial_ledger_entries`, append-only, written by `FinancialLedgerService`
  alone.
- **Idempotency**: a deterministic key per domain operation with a unique index,
  plus duplicate-key recovery for the concurrent case (D20).
- **Reversal, not deletion**: `reverses_entry_id`, unique, so an entry is
  reversible at most once and a reversal cannot itself be reversed (D21).
- Cashbook with a running balance, date filtering, server-side pagination and a
  **balance brought forward**, so pages after the first are arithmetically right
  (D29).
- The opening balance locks once the account has entries.

### Expenses and split funding

- One `expenses` row per real expense, cancelled rather than deleted.
- `funding_allocations`, polymorphic on both sides, as the reusable multi-source
  payment mechanism Phases 6 and 7 reuse unchanged (D22).
- `App\Actions\Finance\AllocateFundingSources`: exact-sum validation with
  `bccomp`, duplicate-source refusal, cross-business and inactive-source checks,
  and the rule that **only a `financial_account` share posts a debit**.
- Split-funding UI with a live running total in vanilla JS using integer paise —
  feedback only; the server recomputes and decides.
- A posted expense's amount, date and funding cannot be edited; correcting them
  means cancelling with a reason and re-entering (D23).

### Partners

- `partners`, one row each, unlimited, with no ownership percentage or profit
  share.
- `partner_contributions`, which credit the destination account through the
  ledger and reverse it on cancellation.
- **Partner ledger derived**, not stored: `PartnerLedgerService` builds it from
  contributions plus partner funding allocations on active expenses (D24). The
  list page totals many partners in two grouped queries.
- `partner.finance.view` gates the ledger separately from `partner.view`, so a
  partner can be visible without their money being.

### Masters

`payment_methods`, `expense_categories`, `sales_channels` — each with an
immutable machine identifier and an editable, translatable name (D25). System rows
cannot be deleted or re-identified; rows in use cannot be deleted at all. Settings
screens for all three, plus milk prices.

### Buyers

One `buyers` table for every channel, with no stored outstanding. Authorisation
resolves from the buyer's sales channel through `BuyerPermissions` and
`BuyerPolicy`, with custom channels mapping to the `customer.*` family (D26). The
list shows only permitted channels, and the server refuses the rest.

### Pricing

- `milk_price_rules` and `buyer_price_rules`, effective-dated, never overwritten.
- `SetMilkPrice`: closes the open period at the day before the new one, under a
  row lock, **forward only** — which makes overlap arithmetically impossible
  rather than merely checked for (D27).
- `DeleteFuturePriceRule` withdraws a period that has not started and re-opens the
  one before it.
- `PriceResolver`: buyer override → business default → **explicit failure**. It
  throws rather than returning zero, because a zero rate would silently record a
  free sale.

### Surface

16 models with factories, 9 actions, 5 services, 7 enums, 1 policy, 92 routes,
48 translation files across `en`/`gu`/`hi`. Every Phase 2 page renders in all
three languages with real records — asserted, not assumed.

---

## Defects found and fixed during Phase 2

Found by tests written to prove the invariants, not by inspection.

1. **`AuditLogger` gated IP and user agent on `runningInConsole()`**, conflating
   "console process" with "no HTTP context" — wrong in both directions. Now keyed
   on the presence of `REMOTE_ADDR` (D28).
2. **The password-change audit marker used the key `password`**, which the
   redactor correctly stripped, silently defeating the entry's whole purpose. Now
   a neutral `security_event` key carries the fact past the redactor (D28).
3. **The cashbook had no pagination**, against MASTER_SPEC section 71. Now
   paginated with a balance brought forward — pagination alone would have made
   every page after the first quietly wrong (D29).
4. **`tests/Unit` was declared in `phpunit.xml` but had been deleted**, which made
   `php artisan test --filter` a fatal configuration error while the full suite
   still passed — so nothing looked wrong until someone ran a single test.
   Restored with a `.gitkeep` (D8 correction).
5. **A Pass 2 self-assessment was wrong.** It recorded the expense detail page as
   having an N+1 on `fundingAllocations.source`. Measurement showed the eager load
   was already there and the page was fine. Query-count guards now assert exact
   equality for the expense list, the expense detail page and the partner ledger,
   so the question is answered by the suite rather than by opinion.
6. **`finance/partners-create`** was an inconsistent URL, caused by
   `partners/{partner}` being registered before the literal `partners/create`.
   Reordered; the URL is now `finance/partners/create`, like every other module.
7. **Four `use RuntimeException;` imports in global-namespace Pest files** had no
   effect and produced the suite's only 4 warnings. Removed; the suite is now
   warning-free.

---

## Completed in Phase 1

Summarised; the reasoning lives in [ARCHITECTURE.md](ARCHITECTURE.md),
[PERMISSIONS.md](PERMISSIONS.md) and [DECISIONS.md](DECISIONS.md).

- **Database and models.** `businesses` with regional settings as typed columns;
  `farms` with a generated-column unique index enforcing one primary farm per
  business (D16); `users` extended with `business_id`, `locale`, `is_active`,
  `last_login_at`. Enums `Locale` and `DateFormat`.
- **Services and actions.** `BusinessContext` as the only resolver of business and
  primary farm; `SetPrimaryFarm` (demote-then-promote in one transaction);
  `SetFarmActiveState` (refuses to deactivate the primary).
- **Authentication**, hand-rolled on Laravel's session guard rather than a starter
  kit, so no Tailwind, React, Vue, Inertia or Livewire entered the project. Login,
  logout, password reset, profile, password change. **No registration route
  exists**, asserted by a test.
- **Inactive-user enforcement in two layers**, because checking at login is not
  enough: the login request refuses a deactivated account, and `EnsureUserIsActive`
  runs on every authenticated request, so deactivating a signed-in user cuts off
  the session they already hold. Password reset is closed off too, and the
  forgot-password response is generic either way so it cannot enumerate addresses.
- **Users, roles and permissions.** The permission catalogue and 7 roles seeded
  idempotently — 69 permissions at the end of Phase 1, 70 now; grouped permission editor; accounts deactivated, never deleted;
  self-deactivation and self-promotion refused. **Authorisation resolves only
  through permissions** — no `Gate::before`, no `hasRole()`, no role-name
  comparison, held in place by a source-scan test (D19).
- **Business and Farm settings** behind `settings.manage`.
- **Localisation**: real Gujarati and Hindi, asserted by a test that fails if any
  string still matches the English original.
- **Application shell**: Bootstrap 5.3, Bootstrap Icons, vanilla ES modules,
  offcanvas sidebar, reusable components, skip link and `aria-current`. The
  sidebar lists only modules that exist — no "coming soon" pages.
- **Foundation dashboard** with real data only: no milk, revenue or animal
  figures, and no charts.
- **PWA** manifest and three project-owned icons. **No service worker** —
  registering an empty one would advertise offline support that does not exist.

### Defects found and fixed during Phase 1

1. **Migration failed with MySQL error 1215.** A `STORED` generated column on
   `farms` made the `business_id` foreign key impossible, because MySQL forbids
   `ON DELETE CASCADE` on a base column of a stored generated column. Switched to
   `VIRTUAL` (D16).
2. **Table collation did not match the documented design.** Laravel's config
   pinned `utf8mb4_unicode_ci` while the schemas were `utf8mb4_0900_ai_ci`.
   Aligned the config.
3. **The paginator would have reintroduced Tailwind.** Switched to
   `useBootstrapFive()` with a test asserting no Tailwind class names appear
   (D18).
4. **`preventLazyLoading` would have broken authorisation**, because Spatie
   resolves roles through lazy-loaded relations on every `can()`. Left disabled
   with the reasoning recorded (D17).
5. **A password-change guard was never exercised.** The "sign out other sessions"
   branch is conditional on the database session driver, and the suite ran on the
   array driver, so the test passed without testing anything.
6. **A role-name authorisation bypass**, corrected after review: a `Gate::before`
   hook granted every ability to Super Admin. Redundant, contrary to the
   specification, and it made Super Admin untestable. Removed (D19).

---

## Completed in Phase 3

### Corrected data-model assumption

The Phase 2 "exact next task" note in this file, and an earlier draft of
`DATABASE.md`, described `milk_productions` as one row per milk type with a unique
key of (`farm_id`, `production_date`, `shift`, `milk_type`). **That contradicted
MASTER_SPEC section 14** and has been corrected in both places rather than quietly
preserved.

The built table is **one row per farm, date and shift**, holding
`cow_milk_quantity` and `buffalo_milk_quantity` as columns, unique on
(`farm_id`, `production_date`, `shift`), with **no `milk_type` column**. The reason
it matters is not tidiness: with a row per milk type, "has this shift been
recorded?" is no longer a single fact, and a shift with cow entered but buffalo not
yet entered cannot be told from one where buffalo was genuinely zero — which is the
exact distinction MASTER_SPEC section 15 requires the UI to show (D30).

### Schema (3 migrations)

- `milk_productions` — as above; `DECIMAL(10,3)`, `DATE`, `created_by`/`updated_by`.
- `milk_usages` — one row per usage event, keyed by farm, date, shift and milk
  type, with `usage_type`, status and cancellation columns.
- `milk_adjustments` — explicit `direction` (`increase`/`decrease`) with a
  **positive** quantity, and `reason` `NOT NULL` at the database level (D31).

### Enums, models, support

- `Shift`, `MilkUsageType`, `AdjustmentDirection`; `MilkType` reused unchanged from
  Phase 2 pricing.
- `MilkProduction`, `MilkUsage`, `MilkAdjustment` with factories, and
  `quantityFor(MilkType)` / `columnFor(MilkType)` so nothing reads
  `cow_milk_quantity` directly.
- `App\Support\Quantity` — exact three-decimal bcmath arithmetic for litres, the
  milk equivalent of the money handling. No litre value is ever a PHP float.
- `App\Support\OperationalDate` — shared `?date=` resolution, falling back to today
  for a malformed navigation parameter.

### Reconciliation engine

- `CalculateMilkReconciliation` — `available = production + adjustments`,
  `allocated = sales + usage`, `remaining = available − allocated`, per farm, date,
  shift and milk type. Cancelled usage and cancelled adjustments are excluded by
  every query.
- `MilkReconciliation` value object carrying **`productionEntered`** beside the
  production quantity, so the missing-versus-zero distinction cannot be dropped by
  a caller that forgets to check.
- `MilkAvailability` — the one place that decides whether milk may be allocated,
  which Phases 4 and 5 reuse for customer deliveries and channel sales rather than
  reimplementing the rule per module.
- **Sales arrive through a seam.** `MilkSalesAllocator` supplies the sales half;
  Phase 3 binds `NoMilkSalesRecorded`, which reports a true zero *and* that no
  sales subsystem exists. `milk_sales` is deliberately not created. The screen says
  the sale modules are not built yet instead of showing channel rows of 0.000 L
  that would read as "nothing was sold today" (D32).

### Actions

`SaveMilkProduction` (`forDay` saves both shifts in one transaction; upsert on the
unique identity under a row lock), `RecordMilkUsage` (availability checked *inside*
the transaction, after a lock — checking before it would leave the obvious race),
`CancelMilkUsage`, `RecordMilkAdjustment`, `CancelMilkAdjustment`.

### Screens

Production matrix (types down, shifts across, saving up to two rows), internal
usage with the remaining-milk panel beside the form, adjustments framed as the
exception they are, and the reconciliation screen. All four with date navigation,
in `en`/`gu`/`hi`.

### Permissions

Four added, 65 → **69**: `milk.usage.view`, `milk.usage.create`,
`milk.usage.cancel`, `milk.adjustment.cancel`. No `milk.usage.update`, because
usage is corrected by cancelling and re-entering. Cancellation stays with the Owner
(D34). `milk.production.delete` remains seeded but **nothing checks it** — there is
no destructive production route, and production is corrected in place (D33).

### Over-allocation and the override model

Internal usage cannot exceed available milk; the save is refused rather than
producing a negative remainder. There is no "ignore availability" checkbox. If more
milk genuinely was available, an authorised person records an adjustment, with a
reason, which is audited — and **no workflow ever creates that adjustment
automatically.** A decrease that would remove already-allocated milk, and
cancelling an increase that allocations depend on, are both refused.
### Tests

**341 tests added in Pass 2**, on top of the 29 Pass 1 smoke tests, organised by
domain:

| File | Tests | Covers |
| ---- | ----: | ------ |
| `Unit/QuantityTest.php` | 32 | exact three-decimal arithmetic; the normalisation contract |
| `Milk/MilkProductionTest.php` | 38 | the record identity, schema lock, create, correct-in-place, duplicate protection, primary farm, validation |
| `Milk/MilkUsageTest.php` | 41 | usage types, creation, the production requirement, allocation boundaries, locking, cancellation |
| `Milk/MilkAdjustmentTest.php` | 40 | direction semantics, reason, creation, decrease safety, cancellation safety, audit |
| `Milk/MilkReconciliationTest.php` | 33 | the formula, the missing-vs-zero matrix, cow/buffalo, shift and date isolation, the sales seam |
| `Milk/MilkAuthorizationTest.php` | 34 | every read and write, the seeded role bundles, the unused delete permission |
| `Milk/MilkScreenTest.php` | 30 | what the four screens actually render, including localisation |
| `Milk/MilkSeederAndBoundaryTest.php` | 45 | seeder idempotency, morph aliases, the Phase 4/5 boundary |
| `Milk/MilkTransactionRollbackTest.php` | 9 | atomicity under injected failure |
| `Milk/MilkQueryCountTest.php` | 6 | no N+1 on any milk screen |
| `DatabaseConstraintTest.php` | +33 | the Phase 3 guarantees that live in the schema |

Headline results: `10.000 − 3.333 − 3.333 − 3.334 = 0.000` exactly; a `−0.001`
remainder stays `−0.001`; one reconciliation unit is exactly 3 queries and nothing
grows with row count.

---

## Defects found and fixed during Phase 3

1. **`Quantity::of()` silently turned any non-numeric input into zero.** Found in
   Pass 2 while pinning the helper's contract. `'12,500'` — a comma decimal
   separator, plausible from an import or a pasted figure — would have recorded
   0.000 litres instead of twelve and a half. Every current call site passes a
   validated numeric, a decimal column or a `SUM()`, so nothing misbehaved in
   practice; the exposure was the next caller. Now `null` and `''` mean absent and
   return zero, and anything else non-numeric throws (D37).
2. **A Pass 1 self-assessment about the production model was wrong before it was
   written** — the Phase 2 "next task" note and `DATABASE.md` both described
   `milk_productions` with a `milk_type` column and a four-column unique key, which
   contradicts MASTER_SPEC section 14. Corrected in both documents rather than
   silently preserved, with the reason recorded (D30).
3. **Two Pass 2 test assumptions were wrong, not the code.** A JSON column does not
   preserve key order, so an audit-payload assertion compared sorted keys instead;
   and `/milk/usage` is the URL of both the GET filter form and the POST store
   route, so matching the form action proved nothing — the assertion now targets a
   field unique to the create form.

Nothing else surfaced. The Pass 1 architecture was not changed by Pass 2 beyond the
`Quantity` fix.

---

## Completed in Phase 4 Pass 1

Pass 1 is the domain: identity, preferences, pauses, sales, payments, outstanding
and the customer profile screen. The Customer Daily Entry grid is deliberately
**not** in it — see "Exact next task".

### Schema (5 migrations)

| Migration | What |
| --------- | ---- |
| `add_customer_columns_to_buyers_table` | `delivery_note`, `start_date`, plus a delivery-eligibility index. No parallel customer table (MASTER_SPEC 16). |
| `create_customer_preferences_table` | one row per (`buyer_id`, `milk_type`); `morning_reminder_qty`/`evening_reminder_qty` `DECIMAL(10,3)`. |
| `create_customer_pauses_table` | `start_date`, nullable `end_date` for an open-ended pause, reason, cancellation columns. |
| `create_milk_sales_table` | the canonical sale for every channel. Rate snapshot, no price-rule FK. |
| `create_buyer_payments_table` | `payment_date`, `amount`, method, funding account `RESTRICT`, cancellation columns. |

`milk_sales` carries `fat_percentage`/`snf_percentage` nullable and unused — Phase 5
Mandali needs them and adding them later would rewrite a table with sales in it. It
carries **no** `buyer_settlement_id`, because `buyer_settlements` is Phase 5 and a
foreign key pointing at nothing is the D13 defect.

### Grid idempotency without a partial index (D38)

MySQL has no partial unique indexes, so the identity is a `VIRTUAL` generated column
that is `NULL` for every source except the daily grid:

```
daily_grid_key = CASE WHEN source = 'customer_daily_grid'
                 THEN CONCAT_WS('|', farm_id, buyer_id, sale_date, shift, milk_type)
                 ELSE NULL END
```

`farm_id` is in the identity because the same customer can be served by two farms on
the same shift once multi-farm arrives, and a key without it would forbid that.
Phase 5 sales are unconstrained by this key, which is the point.

### Domain

| Piece | What it settles |
| ----- | --------------- |
| `SaleSource` enum | one case only. Phase 5 adds its own; an enum case nothing can produce is a promise, not a fact. |
| `CreateMilkSale` | resolves and **snapshots** the rate, computes the amount server-side, asserts availability inside the transaction after `lockForUpdate()`. |
| `SaveCustomerDailySale` | the upsert Pass 2's grid calls: create / update / cancel-on-empty / reactivate, plus `previousDayQuantities()` for Copy Previous Day. Nothing calls it yet. |
| `CancelMilkSale` | cancel, never delete. Grid removals use the stable reason key `removed_from_customer_daily_entry`. |
| `RecordedMilkSales` | the real `MilkSalesAllocator`. One grouped query joined to `sales_channels`; includes any channel that has sales even if dropped from the list, so the parts equal the whole. |
| `CustomerPauseService` | `isPausedOn`, `pausedMapFor` (one query per grid), `timelineFor`, overlap and ordering assertions. |
| `CustomerEligibilityService` | paused customers come back **marked, not dropped** (MASTER_SPEC 18). |
| `BuyerOutstandingService` | sales − payments, with the receivable-adjustments term present and **named zero** rather than faked. Two grouped queries for any number of buyers. |
| `CustomerLedgerService` | statement, monthly summary, opening balance, milk pivoted per type so cow and buffalo litres never share a rate. |
| `RecordBuyerPayment` | locks the buyer's payments, refuses a paisa of overpayment, posts the ledger credit under key `buyer_payment:{id}`, audits — one transaction. |
| `SaveDirectCustomer` | assigns the `direct_customer` channel **server-side** and never reads a posted `sales_channel_id`. |

### Reminders are display metadata (D39)

`CustomerPreference` exposes `morningReminder()`, `eveningReminder()`,
`hasReminder()` and `reminderSummary()` — and no `quantityFor()`,
`defaultQuantity()`, `dailyQuantity()` or `quantity()`. The requirement that a
reminder never becomes the day's actual quantity is enforced structurally by the
absence of a quantity-shaped method, and a reflection test asserts that absence.

### The allocator swap (D32 paying off)

One line in `AppServiceProvider` moved reconciliation from "no sales subsystem" to
real sales:

```php
$this->app->singleton(MilkSalesAllocator::class, RecordedMilkSales::class);
```

The engine did not change. `NoMilkSalesRecorded` is retained, no longer bound, with
a docblock saying why and when to delete it.

### Surface

`customers.*` — index, create, store, show, edit, update, status, plus nested
`pauses`, `payments` and `prices`. No `can` middleware: `BuyerPolicy` decides per
record, because a direct customer and a Mandali share one table. Three languages,
real Gujarati and Hindi. Permission catalogue 69 → 70 (`customer.payment.cancel`,
D40). Morph map 18 → 22 aliases.

### Tests

| File | Covers |
| ---- | ------ |
| `DirectCustomerTest` | identity, channel assignment, preferences, the reminder-is-not-a-quantity rule |
| `CustomerPauseTest` | open-ended pauses, overlap refusal, ordering, timeline states |
| `CustomerMilkSaleTest` | rate snapshot, availability, idempotent re-save, cancellation, reactivation |
| `CustomerPaymentTest` | overpayment refusal to the paisa, reversal, outstanding derivation |
| `CustomerAuthorizationTest` | every customer route and action against the permission catalogue |
| `CustomerScreenTest` | the profile, statement, pause and payment partials |
| `CustomerTransactionRollbackTest` | injected failure at each write, nothing half-written |
| `CustomerQueryCountTest` | list, profile, statement, `outstandingForMany`, batch eligibility — cost independent of row count |

`DirectCustomerSeeder` creates 20 customers, 24 preferences, 3 pauses and 2 buyer
price overrides, including cow-only, buffalo-only, both, no-reminder, unstarted and
archived cases. Pass 1 deliberately seeded **no sales and no payments**, because the
workflow that creates one did not exist yet; Pass 3 closed that boundary with
`CustomerSalesSeeder`.

---

## Defects found and fixed during Phase 4 Pass 1

1. **`CustomerLedgerService` sorted on `kind`, which is alphabetical.** `'payment'`
   sorts before `'sale'`, so a payment appeared above the same day's deliveries and
   the running balance dipped before the charge that caused it (expected 140/210/110,
   got 140/40/110). Now sorted by date, then an explicit sale-before-payment rank,
   then milk type so cow precedes buffalo.
2. **A non-existent Gate ability.** The archive control checked
   `archive-customer`, which was never defined. Added `BuyerPolicy::archive()`,
   which routes to `customer.archive` for a direct customer and to the channel's
   update permission otherwise.
3. **Raw `DB::table` in the customer controller** for accounts and payment methods.
   Replaced with the Eloquent models.
4. **`seedProductionFor()` and `seedDefaultPrices()` were not idempotent**, so a test
   seeding overlapping date ranges failed on a unique constraint rather than on its
   subject. Both use `updateOrCreate` now: a helper that cannot be called twice is a
   trap.
5. **A "guest" test was not a guest** — the file's `beforeEach` signs in an admin.
   Added `Auth::logout()` and a session flush.
6. **An injected model-event failure stayed armed** for the rest of the test. Added a
   `stopFailing()` helper that flushes listeners and re-boots the traits.
7. **`previousDayQuantities()` returned keys in query order**, which is not
   deterministic. It iterates `MilkType::cases()` and `Shift::cases()` now, which the
   Pass 2 grid needs anyway.
8. **A placeholder left in a Blade label** (`__('milk.reconciliation.remaining') === ''
   ? …`). Replaced with a real `customers.fields.end_date` key in all three languages.
9. **A route-name guard matched the wrong route.** Filtering URIs on
   `str_contains('sale')` also matched `settings/sales-channels`; it matches on route
   name now.

Four Phase 2 and Phase 3 forward-looking guards failed once `milk_sales` existed, as
designed. Each was **inverted rather than deleted**, so it still protects the thing it
was written for:

| Guard | Was | Now |
| ----- | --- | --- |
| `BuyerTest` | no customer columns on `buyers` | reminders live in `customer_preferences`; the two buyer-level fields are the two the spec puts there |
| `MasterDataTest` | no customer workflow on the channel masters | no Mandali or vendor workflow has leaked into them |
| `MilkSeederAndBoundaryTest` | `milk_sales` does not exist | the milk seeder creates no sales, even though it now could |
| `PriceResolverTest` | nothing consumes the resolver yet | a sale snapshots the rate and holds no price-rule foreign key |

---

## Completed in Phase 4 Pass 2

The Customer Daily Entry grid — MASTER_SPEC section 19, and the screen the
specification calls one of the most important in the project.

### Surface

| Route | What |
| ----- | ---- |
| `milk.customer-entry.index` | the grid for one date |
| `milk.customer-entry.store` | Save Day, **JSON body** (D5) |
| `milk.customer-entry.copy-previous` | a read of yesterday's quantities; writes nothing |

All three are gated on `milk.customer_delivery.view` only. The write permissions are
checked per operation inside the action, because one save can mix a new delivery with
a correction and those are different acts of trust.

### Architecture

| Piece | Responsibility |
| ----- | -------------- |
| `Support\Milk\DailyEntryRow` | one customer, one milk type, two shifts: saved quantity, per-cell rate, status, totals. Exposes `savedQuantity()` and `reminder()` and deliberately nothing that merges them |
| `Services\Milk\CustomerDailyEntryGrid` | the view model: eligible rows, the day's sales, rates and totals in **7 queries** whatever the customer count |
| `Actions\Milk\SaveCustomerDailyDeliveries` | the day: what changed, the order it is applied in, the permissions it needs, one transaction |
| `Http\Requests\Milk\SaveCustomerDailyEntryRequest` | the narrow payload contract — buyer, milk type, two quantities, and nothing else is read |
| `resources/js/customer-daily-entry.js` | live totals, filters, dirty state, Copy Previous Day, the fetch |
| `PriceResolver::primeFor()` | resolves every buyer and milk type for a date in **2 queries**, filling the memo `resolve()` already reads |

The grid turned out to be wiring rather than business logic, which is the payoff for
having built and tested `SaveCustomerDailySale` a pass earlier. It runs once per
changed cell and nothing in the day action re-implements a sale rule.

### The two rules the screen exists to respect

**Reminders never prefill.** The inputs render from `savedQuantity()` and from
nothing else; the reminder is text in its own column. A source guard asserts the
templates and the JavaScript do not mention the reminder columns at all, and a
rendering test asserts every input is `value=""` when nothing was delivered, however
large the reminder.

**Copy Previous Day only on a click.** It reads the previous calendar day's *active*
grid sales, offers quantities only — no rate, no amount — and writes nothing. Rows
the selected date would refuse are left out entirely, so a value can never be staged
that the save would reject.

### Ordering, and why it is not a weakened rule (D42)

Changed cells are applied **releases first** (cancellations, decreases), then
**claims** (creations, increases), each phase ordered by buyer, milk type, shift.
Moving milk between two customers on a fully allocated shift is a legal day, and
payload order must not decide whether it saves. Two tests assert exactly that with
the rows reversed. The per-cell availability check is untouched: the last claim
applied still sees every other cell at its final value.

### Rate snapshots (D41)

Pass 1 re-resolved the rate on every update. Pass 2 **reversed that**: an existing row
keeps its stored rate and only a new row is priced. A buyer override can be created
covering a past date, and re-resolving would have silently re-priced a billed delivery
because somebody reopened the day to fix the litres. A consequence worth knowing: a
sale whose price rule is later withdrawn stays editable and cancellable, because its
own snapshot is enough to re-cost it.

### Totals

Integer arithmetic on both sides. The browser parses litres into thousandths and rates
into paise, applies the same half-up rounding as `Quantity::multiplyToMoney()`, and
shows a preview; the server recomputes everything and the response replaces what the
screen shows. 3.333 L at ₹85.00 reads ₹283.31 in both.

### Tests

| File | Covers |
| ---- | ------ |
| `CustomerDailyEntryScreenTest` | who appears, reminders never prefilling, saved values prefilling, paused UX, missing price, the rate column, filters, totals, three locales |
| `CustomerDailyEntrySaveTest` | the payload contract, create/update/cancel/reactivate, blank-and-zero, the availability matrix, aggregate availability, idempotency, atomicity, snapshots |
| `CustomerDailyEntryCopyPreviousTest` | the full copy matrix, including that it writes nothing |
| `CustomerDailyEntryAuthorizationTest` | create versus update versus no-op, mixed saves, the seeded role matrix |
| `CustomerDailyEntrySecurityTest` | hand-written payloads: wrong channel, wrong business, archived, paused, forged rate, amount, farm and source |
| `CustomerDailyEntryIntegrationTest` | production → grid → sale → reconciliation → ledger → outstanding, with nothing counted twice |
| `CustomerDailyEntryRollbackTest` | injected failures mid-day; nothing partial survives |
| `CustomerDailyEntryQueryCountTest` | the grid costs the same for 1 customer as for 30; priming is 2 queries; filters are visual only |

Four Phase 2–4 boundary guards were **inverted rather than deleted** now that the
screen exists: the two route guards, the sidebar guard (which now asserts every
sidebar link resolves to a real route), and the customer-screen guard (which now
asserts the link lives under Milk rather than Customers).

---

## Defects found and fixed during Phase 4 Pass 2

1. **The rate snapshot was not actually a snapshot.** Pass 1's update path
   re-resolved the price every time a quantity changed. Found by working through the
   case in section 3 of the Pass 2 brief rather than by a failing test — a buyer
   override covering a past date would have re-priced billed deliveries. Fixed, with
   regression tests both ways, and recorded as D41.
2. **Payload order could refuse a legal day.** Moving milk between two customers on a
   fully allocated shift failed if the increase happened to be processed first. Fixed
   by the two-phase ordering (D42) rather than by relaxing the availability check.
3. **`PriceResolver` was an N+1 waiting for this screen.** Two queries per row, and
   the grid draws every customer. Added `primeFor()`, which fills the same memo
   `resolve()` reads, so resolution stays in one place.
4. Test-authoring mistakes found and fixed while writing the suite: a stale
   `preferences` relation on a customer built in two steps, the wrong signature for
   `CreateCustomerPause::handle()` and `SetMilkPrice::forBusiness()`, `audit_logs.event`
   (the column is `action`), and the ledger statement's shape (`rows` of objects, not
   `entries` of arrays). None were product defects.
5. **A query-count ceiling was one too tight** — the grid is 7 queries, not 6. The
   equality assertion between 1 customer and 50 passed; only my stated bound was
   wrong, and it now names the seven.

---

## Completed in Phase 4 Pass 3

The closing pass: an edge-case audit of the two Pass 2 decisions, the demo
transactions Pass 1 deliberately held back, and the documentation reconciliation.

### The audit found three real defects

**1. `PriceResolver` was never bound as a singleton.** Its own docblock described a
per-request memo, but every injection built its own — so the daily entry grid primed
every rate in two queries and then each cell's save resolved again through a
different instance. Found by a test that added a price override between two writes
and watched the second one ignore it. One line in `AppServiceProvider`.

**2. Editability was decided per row, not per cell.** A row can hold a morning sale
whose price rule was later withdrawn: the morning is correctable against its own
snapshot while a new evening cannot be priced at all. The row-level answer was wrong
in both directions — it either froze a delivery that needed fixing or invited an
entry the server was bound to refuse. Now answered one cell at a time, and Copy
Previous Day stages nothing into a cell that could not be saved.

**3. One rate column for two rates.** A row holds two sale records, and D41 keeps
each one's rate as recorded, so the shifts can legitimately differ — ₹70.00 in the
morning and ₹72.00 in the evening after a customer rate is agreed late. The single
column was a claim about the wrong shift, and made the row total look like an
arithmetic bug: 2.000 L at a stated ₹70.00 costing ₹142.00. Rates are now held,
shown and applied per cell (**D43**).

### Demo transactions

`CustomerSalesSeeder` writes 42 deliveries (50.000 L) and 2 receipts **through the
real domain action**, not through `insert()`. Availability, pricing, the snapshot,
the grid identity and the audit record all apply as they would to an operator typing
the day in — which means the seed cannot over-allocate: if it ever tried,
`MilkAvailability` would refuse and the seed would fail loudly.

| Property | How it is held |
| -------- | -------------- |
| Dates | the two days Phase 3 recorded both shifts for; yesterday's unentered evening is left alone |
| Pauses | the two customers paused over those dates get nothing |
| Quantities | **every one differs from that customer's reminder** |
| Pricing | both resolver branches exercised — a business default and two buyer overrides |
| Receipts | one partial in cash, one settling in full by UPI, each crediting its account exactly once |
| Idempotency | sales skipped when already correct; payments identified by a stable reference |

`SeedIntegrityTest` runs the whole seeding path against the test schema and asserts
the arithmetic rather than the counts: no shift over-allocated, no delivery for a
paused or archived customer, outstanding equals active sales minus active payments
for all twenty customers, every payment credits its account exactly once, no sale
posts to the financial ledger at all, and running the seed three times changes
nothing — including the audit count.

### Documentation

Every document in the set was re-read against the final code. The pattern that
caught something each time was a forward-looking guard written in the past tense.
`TESTING.md` now carries exact per-file counts for all eighteen Phase 4 test files.

---

## Defects found and fixed during Phase 4 Pass 3

1. **`PriceResolver` was not a singleton** — see above. A documented behaviour that
   was not true, and a latent N+1 in the save path.
2. **Row-level editability** where the correct granularity was the cell.
3. **One rate column for two rates**, which misreported the rate and made the row
   total irreproducible.
4. **The customer seeder's docblock promised two price overrides it never created.**
   The overrides are now real, which also gives the demo data a buyer-override price
   to exercise.
5. **Six seeded quantities equalled the customer's reminder.** Caught by the new
   seed-integrity test, which exists precisely because a demo dataset that blurs
   reminder and delivery makes the phase's most important rule invisible. My own
   seeder docblock had claimed otherwise.

---

## Completed in Phase 5 Pass 1

Mandali, vendors, other buyers, settlement and receivable adjustments. The domain and
the core UI. Left uncommitted at the time, and shipped as part of the single Phase 5
commit after Pass 2.

### Schema (4 migrations, 28 → 32)

| Migration | What |
| --------- | ---- |
| `add_phase5_columns_to_milk_sales_table` | `slip_path`, `slip_name`, `resolved_rate`, `rate_override_reason`, and a `(source, sale_date)` index |
| `create_buyer_settlements_table` | the period settlement, with snapshot columns nullable until finalization |
| `create_buyer_balance_adjustments_table` | the receivable correction, with a conditional unique index on its settlement |
| `add_settlement_to_buyer_payments_table` | the `buyer_settlement_id` Phase 4 deliberately left out (D13) |

**No companion sales table.** `fat_percentage` and `snf_percentage` already existed,
nullable and unused, created with `milk_sales` in Phase 4 precisely so Phase 5 would
not have to alter a table holding live sales.

### Identity stays Buyer

No `mandalis`, `vendors` or `other_buyers` table. A Mandali, a vendor and a hotel are
buyers in their own channels (D26), with `scopeMandalis()`, `scopeVendors()` and
`scopeCustomChannel()` — the last defined as "not one of the three system channels",
because the point of a custom channel is that nobody knows its name in advance.

### Domain

| Piece | Responsibility |
| ----- | -------------- |
| `Milk\RecordChannelSale` | the three workflows' one entry point: each one's rate policy, the quality readings, the slip, the channel check |
| `Milk\UpdateChannelSale` | corrections: availability without double-counting, the preserved snapshot, the settlement guard |
| `Buyers\MandaliSettlementCalculator` | the period's figures from the sales' own stored amounts, plus the overlap rule |
| `Buyers\SettlementGuard` | refuses a correction inside a finalized period |
| `Buyers\SettlementStatusSync` | writes back the payment status derived from the receipts |
| `Buyers\BuyerTradeLedger` | a per-transaction account history, over the shared balance arithmetic |
| `Milk\MilkSaleSlips` | the private, randomised-name attachment; deliberately not a document subsystem |
| `Settlements\{Create,Finalize,Cancel}BuyerSettlement` | the three transitions |
| `Buyers\{Create,Cancel}BuyerBalanceAdjustment` | the receivable correction and its withdrawal |

`BuyerOutstandingService` gained the real adjustments term and stayed the **only**
implementation of the formula; `RecordBuyerPayment` and `CancelBuyerPayment` gained the
settlement cap and the status sync and were otherwise reused unchanged.

### The rules that took the most care

**Fat and SNF price nothing** (D44). The regression test is named
`mandali fat and snf never calculate the rate in v1` and compares two deliveries with
identical quantity and rate and wildly different readings. A source guard looks for
arithmetic near a reading.

**A rate override is its own permission** (D45). `milk.sale.override_rate`, separate
from the deferred `milk.customer_delivery.override_rate`. Typing a Mandali rate is not
an override; typing a vendor rate that differs from the configured one is; retyping the
configured one is not; and changing a recorded rate is, on any channel.

**A finalized settlement is a snapshot and closes its period** (D46). Its figures are
frozen from the sales' own rates; periods may not overlap; a delivery inside it cannot
be corrected, withdrawn or added until it is cancelled; cancelling it withdraws its
adjustment but never a receipt; and the two payment statuses are derived.

**An adjustment is a direction and a positive amount** (D47), moves money owed and
nothing else, and may not leave a buyer in credit.

### Surface

22 routes. Two channel-scoped buyer lists and a trade profile (ledger, receipts,
adjustments, settlements); three sale-entry screens over one shared template; the
settlement lifecycle; a private slip download; and generic buyer receipts.

Create and edit for a Mandali or vendor **reuse the Phase 2 buyer form** with the
channel pre-selected, rather than carrying a third and fourth copy of it.

### Tests

| File | Tests | Covers |
| ---- | ----: | ------ |
| `Buyers/MandaliDeliveryTest.php` | 21 | the fat/SNF rule, the manual rate, exact amounts, availability, the receivable-not-cash split |
| `Buyers/MandaliSettlementTest.php` | 40 | drafts, snapshots, differences, idempotency, overlap, the closed period, cancellation, derived status |
| `Buyers/VendorAndGenericSaleTest.php` | 22 | rate resolution, override authorisation, the preserved snapshot, system-channel rejection, the four-bucket reconciliation |
| `Buyers/BuyerOutstandingTest.php` | 27 | one engine for four channels, adjustments, the credit-balance refusal, payments and reversals |
| `Buyers/Phase5ScreenTest.php` | 33 | every screen in three locales, channel authorisation, the role matrix, the phase boundary |

Plus `SeedIntegrityTest` grew from 21 to 24 tests as its Phase 4 guards were inverted
for the new channels.

### Demo data

`ChannelSaleSeeder`: one Mandali, two vendors, one sweet shop, five sales across the
three workflows, two receipts and **one draft settlement**. Written through the real
domain actions, so it cannot over-allocate — if it ever tried, `MilkAvailability` would
refuse and the seed would fail loudly.

The settlement is a draft on purpose. A finalized one would post an adjustment nobody
asked for *and* close its period, so a developer correcting a seeded delivery would be
refused until they cancelled a settlement they never created. Its statement amount is
set above the system figure, so finalizing it demonstrates a real difference in one
click.

---

## Defects found and fixed during Phase 5 Pass 1

1. ~~**`PriceResolver` was not bound as a singleton.**~~ **This item was a reporting
   error and is retracted.** Pass 2 checked it against the baseline rather than against
   the Pass 1 narrative:

   - `git show c73ec26:app/Providers/AppServiceProvider.php` already contains
     `$this->app->singleton(PriceResolver::class);`, with the docblock as written;
   - `git log -S 'singleton(PriceResolver::class)'` names **c73ec26** as the commit
     that introduced it — Phase 4 Pass 3, where it was correctly reported;
   - `git diff c73ec26` for both `AppServiceProvider.php` and `PriceResolver.php` is
     **empty**, so Phase 5 changed neither file.

   The fix was real, but it was Phase 4's. Pass 1 restated it as its own discovery.
   Nothing was reverted because there was no Phase 5 diff to revert, and the behaviour
   is now pinned by `the resolver is one shared instance per request, so its memo is
   actually shared` in `Pricing/PriceResolverTest.php` — asserted rather than left to a
   docblock, because the binding is one line whose deletion nothing else would catch.
2. **One shared controller cannot bind two route parameter names.** `mandalis/{mandali}`
   and `vendors/{vendor}` both pointing at a `show(Buyer $buyer)` gave a silent 404.
   Both routes now use `{buyer}`.
3. **The channel lists authorised too broadly.** `authorize('viewAny', Buyer::class)`
   passes for anyone who can see *some* buyer family, so a user holding only
   `mandali.view` could open the vendor list. Now each list checks its own channel's
   view permission through `BuyerPermissions`.
4. **`outstandingForMany()` crashed on the adjustment direction.** The raw-selected rows
   come back as models, so `direction` was already cast to the enum and the string cast
   threw. Normalised both ways.
5. **The Phase 4 seeder's summary counted Phase 5 sales.** `MilkSale::active()->count()`
   with no source filter reported 47 customer sales when there were 42. Scoped to its
   own source, and the payment count scoped to direct customers.
6. **I overwrote `lang/en/buyers.php`.** It already existed from Phase 2 and my first
   write clobbered it. Restored from HEAD and merged additively instead; the Phase 2
   keys are intact and parity across the three locales is verified at 191 keys each.

---

## Completed in Phase 5 Pass 2

Finalization: the period statement, the custom-channel trade surface, the exhaustive
suite, and the documentation reconciliation. Committed as the single Phase 5 commit.

### The reporting error in the Pass 1 defect list, resolved first

Pass 1 claimed to have fixed the `PriceResolver` singleton binding. It had not: the
binding was Phase 4's, introduced in `c73ec26`, and Phase 5 changed neither
`AppServiceProvider.php` nor `PriceResolver.php`. Checked against the baseline rather
than against the narrative, retracted in place in the defect list above with the
`git log -S` and `git diff` evidence, and **no code was reverted because there was no
diff to revert**. The behaviour is now pinned by a test rather than a docblock.

### The period statement (D49)

MASTER_SPEC section 23, on screen, for a Mandali and — because it is the same account
read the same way — for a vendor and a custom-channel buyer too.

| Decision | Why |
| -------- | --- |
| A view, not a table | A stored statement total is a second place for a balance to be wrong, and a report that disagreed with the profile would be impossible to argue with |
| The ledger table is **the same partial** | `buyers/channel/_ledger` took three optional flags instead; a second copy of that table would have drifted at the first change to how a cancelled sale is shown |
| The summary shows the whole outstanding | A month paid in full would otherwise read as "nothing is owed" with last month's balance sitting above it |
| Opening and closing rows always drawn | A quiet month on an account still owed money must not render as an empty account |
| Month navigation is forgiving | These are chevrons, and a 422 on a chevron is user-hostile. `2026-13` is treated as unparseable rather than rolled into 2027 |
| Export deferred to Phase 9 | A report in one format beats one that waits for three |

### Custom-channel buyers got the surface their receivables needed (D48)

Pass 1 could sell milk to a hotel or a sweet shop and raise a balance, but that balance
had nowhere to be seen or settled: the Phase 2 buyer page is master data, and the two
channel profiles each serve one fixed slug. `buyers.payments.store` had nothing linking
to it for those buyers.

`OtherBuyerController` now serves **every** administrator-created channel from one list,
profile and statement. `ChannelBuyerController` reads a null channel slug as "not one of
the three the specification names", so a channel created tomorrow is included without a
code change. Authorisation is the `customer.*` family — the continuation of D26, not an
extension of it — and the tests assert the families stay separate in both directions.

### Also in this pass

| What | Why it was owed |
| ---- | --------------- |
| The settlement list's **status filter** | Pass 1 listed it as outstanding and left a `settlementStatuses()` method on the wrong class with no caller. The filter is now on the controller that owns the list, and the orphan method is gone |
| **Eager-loaded settlement receipts** | The trade profile derived each settlement's paid total and remaining amount from its receipts, one query per settlement — invisible at one and growing every month a Mandali settles. `paidAmount()` now reads a loaded relation when there is one, through `Quantity::sumMoney()` so the in-memory path adds decimal strings rather than floats. A query-count guard covers a profile with twelve settlements, and a test asserts the loaded and queried paths give the same figure to the paisa |
| A **channel column** on the other-buyers list | One list holds every administrator-created channel, so the channel is the only thing distinguishing a hotel from a sweet shop. Drawn only on that screen |
| The **rollback suite** (`Phase5RollbackTest`) | Pass 1's next-task list owed it: injected failures at finalization, cancellation, the receipt, the reversal, the adjustment, the sale and the correction |
| The **permission-coverage guard** (`PermissionEnforcementTest`) | Phase 5 added the sixth and seventh unused-looking permissions, which is the point at which "is every permission actually checked?" stops being answerable by eye |
| **Database-level Phase 5 constraints** in `DatabaseConstraintTest` | The conditional unique index, the `NOT NULL` reason, the `RESTRICT` references and the decimal types, asserted by writing behind the application rather than through it |
| The localisation parity tests extended to **every** phase's language file | They covered the Phase 1 and 2 files only, so a Phase 3, 4 or 5 key could have drifted between locales unnoticed. `SNF %` is now an explicit exemption: it is written in Latin letters on the collection slips themselves |

### Defects this pass found

| # | Defect | Caught by |
| - | ------ | --------- |
| 1 | **`BuyerPayment` has no `financialAccount` relation.** Four places eager-loaded that name; the relation is `account()`. It never fired in Pass 1 because Eloquent skips eager loads when the parent set is empty, and no test had a payment on a Mandali profile. The trade profile, the statement, the settlement page and the trade ledger all 500'd as soon as one existed | the new statement test |
| 2 | **A custom-channel receivable was unreachable.** No list, no profile, no statement, no way to record the receipt the route already accepted | writing the §17–§20 payment tests and finding no screen to test |
| 3 | **An empty period rendered as an empty account.** The ledger showed its empty state instead of the opening and closing balances, so a quiet month on an account owed ₹7,634.06 looked settled | the opening-balance test |
| 4 | **`?month=2026-13` showed January 2027's figures** under a heading that said 2026. Carbon rolls an out-of-range month forward; the regex accepted any two digits | the malformed-month dataset |
| 5 | **A slip replacement or removal left no audit record at all.** `AuditLogger::updated()` writes nothing when the diff is empty, and the payload carried no slip fields — so swapping the document that supports a delivery was invisible. The display name and an attached flag are now diffed; the stored path is a noise key so a future whole-model audit cannot leak it either | writing the slip lifecycle tests |
| 6 | **A statement period filter mislabelled its own heading.** With `from` and no `month`, the heading and the arrows followed today rather than the dates shown | the explicit-period test |
| 7 | **The demo seeder keyed its buyers on their display name.** Renaming one — `Shree Amul Mandali` became `Shree Sagar Mandali`, because shipping a real dairy's brand in seeded data is not demo data — created a *second* Mandali on the next seed, whose deliveries were then refused for milk the first one already held. Now keyed on the mobile number, with `updateOrCreate`, so a rename lands on the row the seeder owns | re-seeding the development database after the rename |
| 8 | **The demo settlement could not be re-seeded the next day.** Every seeded date is an offset from the day the seeder runs, so seeding on Tuesday asks for a period one day later than Monday's — which overlaps it, and the overlap rule refuses an overlapping settlement. The seeder checked only for a settlement starting on the same day, so the whole seed died on a demo record. It now asks the same question the rule does. The Pass 2 date-stability test travelled **five** days and so missed it; a one-day case was added | re-seeding the development database on the following day |

Items 1 and 5 are the two that mattered in the product: the first was a 500 on four
screens waiting for the first real receipt, and the second was a silent gap in the
audit trail of a commercial document. Items 7 and 8 are demo-data defects, and both
were found by doing the thing a developer does — running the seed twice, on two
different days — rather than by reading the seeder.

A **local** consequence of item 7 is left behind on the development database: the first
failed run created the renamed Mandali beside the old one, so `dairy_management` now
holds two Mandalis and a few extra channel sales. The seeder is correct from empty —
`SeedIntegrityTest` proves it, and the re-seed now writes zero rows — but this machine's
demo data has debris. `php artisan migrate:fresh --seed` clears it; that is destructive
to local data, so it is left for whoever owns the machine rather than done here.

### What this pass deliberately did **not** build

- **No manual balance-adjustment screen.** Pass 1's own "exact next task" listed one; it
  is now a decision not to have one. An adjustment accounts for a reconciled difference
  and settlement finalization is its only caller — a general "change this balance" form
  would be an unauditable way to make any figure in the application say anything. A test
  enumerates every route whose path contains `adjust` and allows only the two Phase 3
  milk-adjustment routes, and `BUSINESS_RULES.md` records the decision.
- **No fat/SNF pricing.** Still recorded and still read by nothing (D44).
- **No export.** Phase 9.
- **No Phase 6.**

---

## Verification results

As of the end of Phase 5 Pass 2, which is the Phase 5 commit.

| Check | Result |
| ----- | ------ |
| `composer validate --strict` | valid |
| `composer check-platform-reqs` | all satisfied |
| `php artisan migrate --seed` | 32 migrations applied, 13 seeders, non-destructive on the development database |
| `php artisan db:seed` again | identical counts and identical money — 0 rows written on the second run |
| Fresh test schema | `migrate:fresh --seed` from empty, then the suite |
| Seeding against the test schema | `SeedIntegrityTest` runs the whole path and asserts the arithmetic, not the counts |
| `php artisan test` | **1,668 passed**, 6,311 assertions, **0 warnings** — up from the 1,344 and 4,927 of the Phase 4 commit |
| `vendor/bin/pint --test` | passed |
| `npm run build` | passed, 0 warnings, icon fonts emitted |
| `php artisan route:list` | 150 routes, no `/register`, 37 Phase 5 routes, no Phase 6 route |
| Permission catalogue | 73 in the catalogue, 73 in the database, and **every one of them enforced or explicitly waiting for its phase** |
| `milk_productions` unique key | (`farm_id`, `production_date`, `shift`) — verified in MySQL |
| `milk_sales.daily_grid_key` | `VIRTUAL`, unique; duplicate grid row rejected, non-grid rows `NULL` — verified in MySQL |
| `buyer_balance_adjustments.active_settlement_key` | generated, unique; a second **active** adjustment for one settlement rejected by the database, a cancelled one does not block a replacement — verified in MySQL |
| Milk quantities | `DECIMAL(10,3)`; money `DECIMAL(14,2)`; business dates `DATE` |
| Money rounding at the litres→rupees boundary | half-up, explicit: 3.333 × 85.00 → 283.31, 30.250 × 73.25 → 2,215.81 |
| Mandali fat/SNF | recorded only; two deliveries with identical quantity and rate and different readings cost the same, and a source scan proves no pricing code reads a quality column |
| Mandali rate | typed in; not an override, and not a `PriceResolver` result |
| Vendor rate | resolved for the sale date; a different figure needs `milk.sale.override_rate` **and** a reason, as does typing one where none is configured |
| Custom-channel rate | typed in, with no override permission, because those channels have no price rules |
| Rate on correction | preserved; changing it is a separate permissioned act with its own reason |
| Finalized settlement | figures frozen from the sales' own rates; a posted `expected_amount`, `milk_quantity`, `difference` or `status` is ignored and recomputed |
| Finalized period | refuses sale corrections, withdrawals and new sales inside it; a draft closes nothing; withdrawing the settlement reopens it |
| Settlement overlap | two non-cancelled periods for one Mandali cannot overlap; adjacent periods can |
| Settlement amount due | the statement amount when one was entered, the system figure otherwise; never below zero |
| Settlement payment status | derived from active receipts; a withdrawn receipt moves it back; no stored paid-total exists |
| Payment ceilings | exactly the outstanding is allowed and one paisa more is refused, on every channel; a settlement-linked receipt is additionally capped by what that settlement still owes |
| Unlinked receipts | reduce the balance and move no settlement's status |
| Receivable adjustments | direction plus positive amount; no cash, no milk; a decrease cannot create credit; **no screen creates one by hand** |
| Sales versus cash | no channel's sale posts to a financial account; a receipt credits one exactly once |
| Period statement | every figure hand-calculated in the test; no statement table and no stored total; opening and closing balances shown even for an empty period |
| Collection slips | private disk, randomised filename, authorised download only, prohibited on the other workflows, kept when a delivery is withdrawn |
| Audit records | carry `slip_attached` and the display name, never the stored path — asserted including for a whole-model audit |
| Transaction atomicity | injected failures at finalization, cancellation, the receipt, the reversal, the adjustment, the sale and the correction each leave nothing behind |
| Buyer identity | one `buyers` table; no mandalis, vendors, other_buyers or per-channel payment tables |
| Outstanding arithmetic | one service for all four channels, asserted as a dataset, with a source scan that no per-channel service exists |
| Query cost | equality for 1 and for many on seven screens, including a trade profile with twelve settlements and the settlement list |
| Role-name authorisation | none anywhere in app code |
| Locales | 211 buyer keys at parity across en/gu/hi; every Phase 5 screen renders in all three with no raw dotted key |
| Seeded demo transactions | written through the real domain actions; the settlement is a **draft**, so it closes no period for a developer |
| Seed date stability | every business date is an offset from the day the seeder ran; re-seeding the same day writes nothing and re-seeding later adds that window beside the first |
| Tailwind in active stack | absent |
| Development DB | `dairy_management` |
| Test DB | `dairy_management_test` only |
| `.env`, `vendor/`, `node_modules/`, private uploads staged | no — `storage/app/private` is ignored in full and empty |
| Tracked secrets | none |

---

## Known issues

1. **`database/database.sqlite` still exists locally**, held open by a VS Code
   process. Gitignored, untracked, absent from every commit, referenced by
   nothing. Delete manually when the lock clears.
2. **A manual `php artisan boost:update` will regenerate the guidelines block in
   `CLAUDE.md`** and may drop the inline override markers. The precedence section
   survives because it sits above the generated block. Re-check afterwards.
3. **Validation messages in `gu`/`hi` cover only the rules reached so far.**
   Others fall back to English. Extend as later phases add rules.
4. **Email verification is not enforced.** `email_verified_at` is cleared when a
   user changes their own email, but nothing requires verification to sign in. The
   specification does not ask for it; revisit if that changes.
5. **A custom permission granted to Super Admin directly does not survive a
   seed.** The seeder syncs that role to the seeded catalogue, which is the safe
   direction. Grant custom permissions through a custom role. Asserted by a test
   and documented in [PERMISSIONS.md](PERMISSIONS.md).
6. **`preventLazyLoading` stays disabled** (D17), so N+1 problems are caught by
   explicit eager loading and the query-count guards rather than by the framework.
7. **`milk.production.delete` is seeded but checked by nothing.** There is no
   destructive production endpoint; production is corrected in place. Documented as
   an interpretation in `PERMISSIONS.md` rather than resolved by adding a delete
   route (D33).
8. **True concurrency is not directly tested.** A single-connection Pest test
   inside a `RefreshDatabase` transaction cannot reproduce two simultaneous
   writers. The suite asserts the structural property instead — a locking read
   precedes the insert, both inside one transaction. The lock was not weakened to
   make a test easier (D35).
9. **`CalculateMilkReconciliation::forDate()` reads the production row once per
   milk type rather than once per shift**, so a whole-day view issues two more
   production queries than strictly necessary. Constant overhead, not an N+1 —
   measured and left alone rather than optimised speculatively.
10. **The Pass 1 smoke file overlaps the Pass 2 business tests.**
    `MilkSmokeTest.php` is now largely subsumed by the domain files. Kept rather
    than deleted, because removing coverage is a decision for a human; it costs
    roughly 25 seconds of duplicate work per full run.
11. **Six catalogued permissions are seeded ahead of the feature that uses them**, and
    that is now asserted rather than assumed. `PermissionEnforcementTest` fails if any
    *other* permission goes unchecked, and fails equally if one of the six starts being
    checked without leaving the waiting list. The six are `permission.manage` (there is
    no permission editor; roles carry permissions), `finance.payment.view` and
    `finance.payment.create` (outgoing payments arrive with salaries and suppliers),
    plus the animal, employee and report families. See
    [PERMISSIONS.md](PERMISSIONS.md).
12. **A statement for a custom period labels itself with the month its `from` date
    falls in.** A period spanning two months is shown correctly and totalled correctly;
    only the heading has to pick one. Stated rather than solved, because the alternative
    — a heading that names a range — duplicates the period line directly beneath it.
13. **The grid has no pause-override workflow.** MASTER_SPEC section 19 permits an
    authorised override of a paused customer; it does not require one, and none was
    built. A paused row is read-only and the server refuses the delivery. Revisit only
    if somebody actually needs it.
14. **The full suite takes well over an hour** against MySQL. Narrow runs by path or
    `--filter` during development; run the whole suite at a phase boundary.
15. **The grid's JavaScript is not unit-tested.** Its behaviour is asserted by source
    guards — that the payload is built from editable rows rather than visible ones,
    that the filter writes no values, that the copy never POSTs — plus the server-side
    tests behind every rule it appears to enforce. A browser test runner is not part
    of this stack, and the alternative was asserting nothing at all.
16. **Live totals and server totals are two implementations of the same rounding.**
    They are kept in step by a test that checks the same figures on both sides
    (3.333 L at Rs 85.00 to Rs 283.31). A third place that multiplies litres by a rate
    would need the same treatment.
17. **The development database carries demo-data debris from a rename.** Two Mandalis
    and a few extra channel sales, from the first seed run after `Shree Amul Mandali`
    was renamed while the seeder still keyed buyers on their name. The seeder is fixed
    and correct from empty; `migrate:fresh --seed` clears the local debris and is left
    to whoever owns the machine, because it destroys local data.

Two entries left this list in Phase 5 rather than being renumbered: *receivable
adjustments are a named zero* and *`buyer_payments` has no `buyer_settlement_id`* were
both statements about something Phase 5 would build, and it built them.

---

## Deferred, with the phase that owns it

| Item | Phase |
| ---- | ----- |
| Fat/SNF rate bands. The `milk_sales` columns exist and are **written** as of Phase 5; nothing reads them, and the specification defers quality-based pricing past V1 (D44) | post-V1 |
| Credit balances for buyers, if ever wanted. Both a decrease adjustment and a payment refuse to go below zero; the workflow that would allow it needs one check relaxed and its own permission (D47) | unscheduled |
| Statement export to PDF and Excel. The on-screen statement is Phase 5; the export packages are not installed (D11) | 9 |
| `animals`, `animal_events`, `expenses.animal_id`, `PurchaseAnimal` | 6 |
| `employees`, payroll, loans, `expenses.employee_id` | 7 |
| Loan disbursement funding constraint (D13) | 7 |
| Private disk for employee documents and attachments | 7 |
| Notification bell and notification module | 8 |
| Operational dashboard, Chart.js wired into the bundle | 8 |
| Export packages installed and wired (D11) | 9 |
| Service worker, caching, security headers | 10 |
| Optional Docker setup | 10 |

---

## Exact next task

**Phase 6 — Animals.** Phase 5 is committed and the whole suite is green. Nothing from
it is outstanding.

Read `docs/MASTER_SPEC.md` sections 24–30 before starting, then:

1. **Schema.** `animals`, `animal_events`, and `expenses.animal_id` — the column Phase 2
   deliberately left out, with the `PurchaseAnimal` action that fills it. The migration
   list in **Database state** above is the dependency-safe order; `docs/DATABASE.md`
   Group C carries the planned shape.
2. **The purchase is one entry.** An animal purchase creates the animal, its purchase
   event, an expense and that expense's funding allocations in one transaction, through
   `Finance\AllocateFundingSources` **unchanged** — the Phase 2 action has done split
   funding since then and must not be forked for animals.
3. **Lifecycle is independent of events.** The specification keeps the animal's state
   and its event timeline separate; do not derive one from the other.
4. **The morph map grows by exactly the aliases Phase 6 needs** (D10), and
   `MorphMapTest`'s forward-looking half moves to Phase 7.
5. **Invert the boundary guards rather than deleting them.** `MilkSeederAndBoundaryTest`,
   `RouteSmokeTest` and `MorphMapTest` currently assert that no Phase 6 route, table or
   alias exists. Each becomes its positive form, and the guard moves on to Phase 7 — the
   pattern every phase has followed since Phase 3.
6. **Then the single Phase 6 commit**, after the full verification set.

### What Phase 5 deliberately left alone

- **No fat/SNF pricing**, no rate bands, no quality-based calculation. The readings are
  stored, written by the Mandali workflow, and read by nothing (D44). This is the rule
  most likely to be "finished" by mistake.
- **No manual balance-adjustment screen.** A decision, not an omission: settlement
  finalization is the only thing that creates an adjustment, and a test enumerates every
  `adjust` route to keep it that way. See `BUSINESS_RULES.md` 11c.
- **No customer-delivery rate override.** `milk.customer_delivery.override_rate` is still
  seeded and checked by nothing, and is deliberately **not** the permission that
  authorises a vendor override (D45).
- **No credit-balance workflow.** A decrease adjustment and a payment both refuse to take
  a buyer below zero, and the workflow that would allow it has a clear shape if it is
  ever wanted (D47).
- **No statement export.** The period statement is on screen only; PDF and Excel are
  Phase 9 and will render the same arrays rather than recomputing anything (D49).
- **No Phase 6.** No animals, employees, payroll, notifications or exports.
