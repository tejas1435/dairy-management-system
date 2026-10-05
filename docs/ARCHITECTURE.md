# Architecture

How the Dairy Management System is put together and why. Decisions that were
expensive to make are recorded separately in [DECISIONS.md](DECISIONS.md); the
schema is in [DATABASE.md](DATABASE.md).

This document describes the whole design, including parts not yet built. What
exists as of **Phase 4 Pass 1**:

| Section | Status |
| ------- | ------ |
| 1 Stack, 2 Request lifecycle, 3 Structure | in place |
| 4 Authentication, 5 Authorisation | in place |
| 6 Financial flow | in place for expenses, partner funding, the cashbook and direct-customer payments; Mandali and vendor settlement is Phase 5, salary and loans Phase 7 |
| 7 Milk flow | **production, internal usage, adjustments, reconciliation, direct-customer sales and the Customer Daily Entry grid all in place**; the allocator seam is bound to real sales; Mandali and vendor sales are Phase 5 |
| 8 Enter Once, Update Everywhere | in place for the expense and contribution rows of the table, and for a customer sale, which updates reconciliation, the customer ledger and outstanding from one entry |
| 9 File storage | **not built** — Phase 7, with the private disk |
| 10 Notifications | **not built** — Phase 8 |
| 11 Localisation | in place |
| 12 Reports and exports | **not built** — Phase 9 |
| 13 PWA | manifest and icons in place; **no service worker** — Phase 10 |
| 14 Auditing | in place |
| 15 Security posture | in place for the surface that exists |

Nothing unbuilt is stubbed, mocked or shown as "coming soon". A module is absent
from the navigation until it works.

---

## 1. Stack

| Layer        | Choice |
| ------------ | ------ |
| Framework    | Laravel 13.32 |
| Language     | PHP 8.4 (8.3 minimum) |
| Database     | MySQL 8.4 LTS, InnoDB, `utf8mb4` |
| Templating   | Blade |
| CSS          | Bootstrap 5.3 compiled from Sass |
| Icons        | Bootstrap Icons, self-hosted |
| JavaScript   | Vanilla ES modules |
| Bundler      | Vite 8 |
| Charts       | Chart.js 4 |
| Auth         | Laravel session authentication |
| Authorisation| Spatie Laravel Permission 8 |
| Tests        | Pest 5 on PHPUnit 13 |
| Style        | Laravel Pint |

No React, Vue, Inertia, Livewire, Tailwind, jQuery or microservices. All
front-end libraries are installed through NPM and bundled by Vite; nothing is
loaded from a CDN.

---

## 2. Request lifecycle

```
Route
  └─ Middleware            authentication, active-user check, locale, permission
      └─ Form Request      validation and authorisation of input
          └─ Controller    thin: resolve, delegate, redirect or respond
              └─ Action / Service   business rules, inside DB::transaction()
                  └─ Eloquent models
                      └─ MySQL
```

Controllers do not contain financial or milk calculations. They resolve the
request, hand it to a domain action, and choose a response. Blade templates
display values; they never compute them.

---

## 3. Application structure

```
app/
  Actions/        single-purpose write operations, one public method each
  Services/       multi-step or shared domain logic
  Enums/          backed PHP enums for every business enumeration
  Models/         Eloquent models, relationships, scopes, casts
  Policies/       per-model authorisation
  Console/        Artisan commands
  Http/
    Controllers/  thin controllers grouped by module
    Requests/     Form Requests, one per write operation
    Middleware/   locale, active user, permission checks
  Support/        catalogues, value objects and shared helpers
resources/
  scss/           Bootstrap configuration and application styles
  js/             ES modules, one per interactive screen
  views/          Blade, organised by module
lang/             en, gu, hi translation files (project root, Laravel 11+)
```

### Two resolvers worth knowing about

`App\Services\BusinessContext` is the single answer to "which business and which
farm". It is a singleton, so the lookup happens once per request, and it keeps
`Farm::where('is_primary', true)` out of controllers, actions and Blade. When a
farm selector eventually appears, there is one place to change.

`App\Support\PermissionCatalog` and `App\Support\RoleCatalog` hold the permission
identifiers and the seeded role matrix. They are code rather than only database
rows because routes and policies reference the strings — see
[DECISIONS.md](DECISIONS.md) D15.

`App\Services\PriceResolver` is a singleton too, and for a reason worth stating: it
memoises by buyer, milk type and date, and that memo is only worth having if every
collaborator in a request shares it. It was not bound until Phase 4 Pass 3, so each
injection quietly built its own — the daily entry grid primed every rate in two
queries, and then each cell's save resolved again through a different instance.
Binding it made the behaviour its own docblock already described.

### Actions and services

Actions are named for what they do, take explicit arguments, and own their
transaction boundary.

Built as of Phase 5:

| Action | Owns |
| ------ | ---- |
| `Farms\SetPrimaryFarm` | demote-then-promote in one transaction |
| `Farms\SetFarmActiveState` | refuses to deactivate the primary farm |
| `Milk\SaveMilkProduction` | upserts the one row per farm, date and shift, under a row lock; `forDay()` saves both shifts in one transaction |
| `Milk\RecordMilkUsage` | the availability check **inside** the transaction, then the usage row |
| `Milk\CancelMilkUsage` | cancellation with a reason; the milk returns to the pool with no compensating record |
| `Milk\RecordMilkAdjustment` | the authorised exception: mandatory reason, decrease limits, audit |
| `Milk\CancelMilkAdjustment` | withdrawal, refused when allocations depend on the milk it granted |
| `Finance\AllocateFundingSources` | the reusable split-funding core: exact-sum validation, source checks, and the account debits |
| `Expenses\CreateExpense` | the expense, its allocations, its ledger effects and its audit record, atomically |
| `Expenses\CancelExpense` | cancellation plus the reversal of only the account-funded shares |
| `Partners\CreatePartnerContribution` | the contribution and the account credit |
| `Partners\CancelPartnerContribution` | cancellation and the reversing debit |
| `Pricing\SetMilkPrice` | closes the open period and opens the next, under a row lock |
| `Pricing\DeleteFuturePriceRule` | withdraws a period that has not started, re-opening the one before it |
| `Milk\CreateMilkSale` | resolves and snapshots the rate, computes the amount, asserts availability inside the transaction after a locking read |
| `Milk\SaveCustomerDailySale` | the grid upsert: create, update, cancel-on-empty and reactivate for one farm, customer, date, shift and milk type |
| `Milk\CancelMilkSale` | cancellation with a reason, including the stable grid-removal reason |
| `Milk\SaveCustomerDailyDeliveries` | a whole day of the grid: which cells changed, the order they are applied in, the permissions they need, one transaction (D42) |
| `Buyers\RecordBuyerPayment` | the overpayment refusal, the payment row and its ledger credit, atomically |
| `Buyers\CancelBuyerPayment` | one cancellation only, reversing the credit rather than deleting it |
| `Milk\RecordChannelSale` | one sale through the Mandali, vendor or generic workflow: resolves or accepts the rate per that workflow's rules, authorises an override, stores the slip, asserts availability inside the transaction |
| `Milk\UpdateChannelSale` | a correction to a recorded channel sale: quantity, quality, notes and the slip, with the rate kept unless changing it is separately authorised (D41) |
| `Settlements\CreateBuyerSettlement` | opens a draft for a period, refusing an overlap; `updateDraft()` edits the statement amount while it is still a draft |
| `Settlements\FinalizeBuyerSettlement` | freezes the quantity and the expected amount from the period's own sales and posts the difference as one adjustment, atomically |
| `Settlements\CancelBuyerSettlement` | withdraws the settlement and its adjustment, refusing one with active receipts |
| `Buyers\CreateBuyerBalanceAdjustment` | a receivable correction: direction, positive amount, mandatory reason, no credit balance |
| `Buyers\CancelBuyerBalanceAdjustment` | withdrawal, restoring the balance with no compensating row |

| `Customers\SaveDirectCustomer` | assigns the direct-customer channel server-side and never reads a posted channel id |

| Service | Responsibility |
| ------- | -------------- |
| `BusinessContext` | the one answer to "which business, which farm" |
| `FinancialLedgerService` | the only writer of ledger entries; idempotency, reversal, derived balances, the cashbook |
| `PartnerLedgerService` | derives a partner's statement from contributions and allocations |
| `PriceResolver` | buyer override → business default → explicit failure |
| `AuditLogger` | the only writer of audit records; redaction and diffing |
| `Milk\CalculateMilkReconciliation` | `available = production + adjustments`, `allocated = sales + usage`, `remaining = available − allocated`, per farm, date, shift and milk type |
| `Milk\MilkAvailability` | the one place that decides whether milk may be allocated; Phase 4 sales reuse it unchanged, and Phase 5 will |
| `Milk\RecordedMilkSales` | the bound sales allocator: aggregates active `milk_sales` by channel in one grouped query |
| `Milk\NoMilkSalesRecorded` | retained but **no longer bound**; still the only implementation of "no sales subsystem exists", which a test can bind explicitly |
| `Customers\CustomerEligibilityService` | who may be delivered to on a date, with paused customers marked rather than dropped |
| `Customers\CustomerPauseService` | all pause date arithmetic, including a whole day's grid in one query |
| `Customers\CustomerLedgerService` | a customer statement, monthly summary and opening balance, pivoted per milk type |
| `Milk\CustomerDailyEntryGrid` | the daily entry view model: eligible rows, the day's saved sales, rates and totals, in a fixed number of queries whatever the customer count |
| `Buyers\BuyerTradeLedger` | a Mandali's or vendor's account history and period totals, one transaction per line with a running balance from a derived opening figure; every total comes from `BuyerOutstandingService` |
| `Buyers\MandaliSettlementCalculator` | the period's milk and expected amount from its own active sales, at the rates they were recorded at |
| `Buyers\SettlementGuard` | the one place that refuses a correction inside a finalized period, and the one that decides whether a new sale may be dated into one |
| `Buyers\SettlementStatusSync` | recomputes a settlement's payment status from its active receipts, so Partially Paid and Paid are never set by hand |
| `Milk\MilkSaleSlips` | the Mandali collection slip: a randomised name on a private disk, replacement, removal, and the rules a Form Request applies |
| `Buyers\BuyerOutstandingService` | outstanding derived as active sales **+ active receivable adjustments** − active payments, for one buyer or many, on every channel |

| Support | Responsibility |
| ------- | -------------- |
| `MorphMap` | the morph aliases, plus the funding source and payable allow-lists |
| `PermissionCatalog` / `RoleCatalog` | permission identifiers and the seeded role matrix |
| `BuyerPermissions` | channel → permission family (D26) |
| `ResolvedPrice` | a resolved rate with its provenance, or an explicit failure |
| `Quantity` | exact three-decimal litre arithmetic through bcmath; throws on a malformed quantity (D37) |
| `OperationalDate` | shared `?date=` resolution for operational screens, falling back to today |
| `Milk\MilkReconciliation` | one reconciliation unit, carrying `productionEntered` beside the quantity (D36) |
| `Milk\SalesAllocation` | sales by channel, plus whether a sales subsystem exists at all |

| Contract | Purpose |
| -------- | ------- |
| `MilkSalesAllocator` | the seam between reconciliation and the sale workflows of Phases 4 and 5 (D32) |

Still to be built, in the phase that owns them: `PurchaseAnimal`,
`RecordAnimalEvent` (6), `GeneratePayroll`, `RecordPayrollPayment`,
`DisburseEmployeeLoan` (7). All five fund through `AllocateFundingSources` unchanged.

`SaveCustomerDailySale` and `RecordBuyerPayment` were on this list until Phase 4 built
them, and Pass 2 gave the first of them its caller: `SaveCustomerDailyDeliveries` runs
it once per changed cell. That the grid turned out to be wiring rather than business
logic is the payoff for having built and tested the cell operation a pass earlier.

The settlement entry on that list read `FinalizeMandaliSettlement` until Phase 5 built
it as `FinalizeBuyerSettlement` — named for the table it writes, because a settlement
belongs to a *buyer* row whose channel happens to be `mandali` (D26). The Mandali-only
rule is enforced by the action and the policy, not by the class name.

### The sales seam

Reconciliation needed sales while the sale workflows were still a phase away. Rather
than leave a gap in the formula or an edit pending inside the engine, the sales half
arrives through a bound contract:

```
CalculateMilkReconciliation
  └─ MilkSalesAllocator (interface)
       ├─ RecordedMilkSales          bound: aggregates milk_sales by channel
       └─ NoMilkSalesRecorded        retained: total 0.000, subsystemExists false
```

**This has now been exercised.** Phase 4 created `milk_sales` and changed one
`singleton()` line in `AppServiceProvider`. The engine, the result object and the
reconciliation view are written against the interface and did not change — which
mattered because the engine is also where the "remaining must not go negative" rule
lives, and that rule was not edited by the phase that started feeding it sales.

`NoMilkSalesRecorded` is kept rather than deleted for two reasons: it is the only
implementation of `SalesAllocation::noSubsystem()`, and a test still binds it to prove
the engine reports an absent subsystem honestly. Its docblock says to delete it when
neither reason holds.

While no subsystem exists the screen says so, rather than drawing channel rows of
0.000 L that a reader would take for "nothing was sold today". Now that one does, the
screen draws a row per channel and a channel with no sales reads as a real zero.

There is no repository layer. Eloquent is used directly and cleanly.

### Where a rule is allowed to live

| Rule | Lives in |
| ---- | -------- |
| Shape and presence of input | Form Request |
| Which permission is required | route middleware, Form Request, policy |
| Money arithmetic, multi-table writes | action or service, inside a transaction |
| Invariants that must survive an application bug | the schema — unique indexes, FKs, `RESTRICT` |
| Display formatting | Blade |

A rule never lives only in JavaScript. The browser's running total on the split
funding form is feedback; the server recomputes it and is the authority.

---

## 4. Authentication

Email and password only. There is no public registration; administrators create
users.

- Sessions are database-backed and regenerated on login.
- Login is rate-limited.
- `users.is_active = false` blocks authentication even with correct credentials.
- Password reset is the standard Laravel flow.
- Each user carries a `locale`, applied by middleware on every request.

---

## 5. Authorisation

Granular permissions, never hard-coded role checks. Roles are collections of
permissions and both are editable at runtime. Permissions are granted through
roles rather than directly to users.

Enforcement is layered and always server-side:

1. Route middleware for coarse access.
2. Policies for per-record decisions.
3. Form Request `authorize()` for write operations.
4. Blade `@can` for *display only*.

Hiding a menu item is never the protection. Possessing an ID grants nothing; the
policy decides. The permission catalogue is in [PERMISSIONS.md](PERMISSIONS.md).

Two Phase 5 additions are worth naming here, because both answer "which permission?"
with something other than a constant:

- **`BuyerPolicy` resolves the family from the buyer's channel**, through
  `BuyerPermissions`, for view, create, update, receipts and withdrawals alike. One
  `buyers` table serves four channels, so the permission is a property of the record
  rather than of the route (D26, D48).
- **A channel-scoped list checks its own channel's view permission**, not `viewAny`.
  `viewAny` passes for anyone who can see *some* buyer family, which would let a user
  holding only `mandali.view` open the vendor list.

And one guard about the catalogue itself: a test asserts that **every** permission in
it is enforced somewhere, with the ones seeded ahead of their phase listed explicitly.
A permission nobody checks is worse than a missing one — it appears on the role screen,
an administrator grants or withholds it, and nothing changes.

---

## 6. Financial flow

Every rupee that touches a business account or partner-funded spend is
traceable to a source record.

```
Buyer payment received ─┐
Partner contribution  ──┼─▶ financial_ledger_entries (credit)
                        │
Expense paid from cash ─┤
Salary paid from bank  ─┼─▶ financial_ledger_entries (debit)
Loan disbursed         ─┘
```

Five rules hold everywhere:

1. **Balances are derived.** An account balance is
   `opening_balance + credits − debits`. No stored current balance exists, and
   ledger entries are written by `FinancialLedgerService` alone, never edited by
   hand.
2. **One expense, many funding sources.** A ₹90,000 animal purchase split across
   two partners and a bank account is one `expenses` row and three
   `funding_allocations` rows. Allocations are never counted as extra expenses,
   and their sum is validated against the payable inside the transaction.
3. **Only business money moves an account.** A partner-funded share posts no
   ledger entry, because the money never passed through a business account. It
   appears on the partner's ledger instead ([DECISIONS.md](DECISIONS.md) D22).
4. **A posting happens at most once, and is undone by reversal.** Every entry
   carries a deterministic idempotency key with a unique index behind it, so a
   retry cannot double a balance; a wrong entry is cancelled by an opposite entry
   that points back at it, and both survive (D20, D21).
5. **Revenue is not cash.** Sales and payments received are separate figures and
   are displayed separately. Outstanding is the difference, always derived.

Money is decimal end to end: `DECIMAL` columns, `bcmath` string arithmetic in
PHP, integer paise in JavaScript. No money value is ever a PHP float.

---

## 7. Milk flow

```
milk_productions  +  milk_adjustments   =  available
milk_sales        +  milk_usages        =  allocated
available − allocated                   =  remaining
```

Computed per farm, date, shift and milk type, by
`App\Services\Milk\CalculateMilkReconciliation`.

- **Production is one row per farm, date and shift**, holding the cow and buffalo
  quantities as columns, unique on those three. There is no `milk_type` column on
  production (D30).
- A missing production row means "not entered" and is shown as such. It is never
  treated as zero, and the engine carries `productionEntered` beside the quantity
  so no caller can lose the distinction (D36).
- Allocation beyond available milk is blocked by `MilkAvailability`, **inside the
  writing transaction and after a row lock**, so two concurrent writers cannot
  consume the same milk. Authorised users may proceed only through an explicit
  `milk_adjustments` record carrying a reason, which is audited. **No balancing
  record is ever created silently** — nothing in the application writes an
  adjustment on a user's behalf (D35).
- Adjustments carry an explicit `direction` with a positive quantity; the sign
  exists only in the arithmetic (D31).
- Cancelled sales, usage and adjustments stop counting immediately, because the
  queries filter on active status rather than a sync process removing a line.
- Quantities are `DECIMAL(10,3)` and every calculation runs through
  `App\Support\Quantity` on decimal strings. No litre value is ever a PHP float
  (D37).

The request path for a milk write:

```
Controller (resolves the date; never the farm — BusinessContext does that)
  └─ Form Request           shape, range, three decimal places, permission
      └─ Action             DB::transaction
          ├─ lockForUpdate  on the shift's existing rows
          ├─ MilkAvailability::assertCanAllocate()
          ├─ the row
          └─ AuditLogger    inside the same transaction
```

The availability check sits after the lock and before the write on purpose:
checking before the transaction opens would leave the race where two people each
see the same milk remaining.

### Customer Daily Entry

The highest-value screen in the product and the one with the most specific
rules.

- Rows come from registered customers; the customer is never selected per day.
- Reminder quantities are displayed as helper text and **never prefill** the
  morning and evening inputs.
- "Copy Previous Day" runs only on an explicit click.
- The whole day saves in one transaction, as a JSON request body rather than a
  form POST (see [DECISIONS.md](DECISIONS.md) D5).
- Saving the same day twice updates the existing sales instead of duplicating
  them, guaranteed by a unique key rather than by application checks alone.
- Clearing a quantity cancels the grid-generated sale according to the
  cancellation rules; it never leaves a stale sale behind.
- Totals are shown live in the browser for feedback and recomputed server-side
  after save. The server's numbers are the ones that count.

### Channel sales and channel buyers (Phase 5)

Four kinds of buyer, two abstract controllers, one of everything underneath.

```
Buyers\ChannelBuyerController                Milk\ChannelSaleController
  ├─ MandaliController      mandali            ├─ MandaliDeliveryController   fat/SNF, slip, typed rate
  ├─ VendorController       vendor             ├─ VendorSaleController        resolved rate, override
  └─ OtherBuyerController   every custom       └─ OtherSaleController         typed rate, custom channels only
```

Each subclass declares what differs — the channel, the permission family, the route
and view prefixes, the wording — and nothing else. The shared base owns the list, the
trade profile, the period statement, the channel check and the authorisation; the
shared Blade partials own the ledger, the receipts, the settlements and the
adjustments. One table (`buyers`), one sale table (`milk_sales`), one receipt table
(`buyer_payments`), one outstanding service, one ledger service.

`OtherBuyerController` serves **every** administrator-created channel from one screen,
because a custom channel is created at runtime: the base reads a null channel slug as
"not one of the three the specification names" (D48).

The important consequence is negative. There is no `mandali_payments` table, no
`MandaliOutstandingService`, no second reconciliation path and no second copy of the
overpayment rule. A fourth channel kind would be four method bodies.

#### What differs per sale workflow, and where it is enforced

| | Mandali | Vendor | Custom channel |
| --- | --- | --- | --- |
| Rate | typed in; that **is** the workflow | resolved, override permissioned | typed in; no rules exist to override |
| Fat / SNF | recorded, required / optional | not offered | not offered |
| Collection slip | optional, private disk | **prohibited** by the Form Request | **prohibited** |
| Settlements | yes | no | no |
| Permission family | `mandali.*` | `vendor.*` | `customer.*` (D26, D48) |

"Not offered" is a Form Request rule, not only a missing field: posting a slip to the
vendor form is refused rather than dropped, because an upload against a sale that has
no such document means the operator is on the wrong screen.

#### The settlement, the ledger and the statement are three things

| | What it is | Mutates |
| --- | --- | --- |
| `BuyerTradeLedger` | the account history with a running balance | nothing |
| a settlement | a period's agreed figures, frozen, with the difference posted | the balance, once, through one adjustment |
| the period statement | a readable report of one period | nothing |

They share the arithmetic — `BuyerOutstandingService` — and nothing else. There is no
statement table and no stored settlement paid-total: what has been received is the sum
of its active receipts, so the two payment statuses are derived and cannot drift from
the receipts (D46, D49).

---

## 8. Enter Once, Update Everywhere

The product rule that drives the module boundaries. One entry propagates:

| Entered | Propagates to |
| ------- | ------------- |
| Customer daily milk | milk sale, distribution, customer ledger, receivable, dashboard, reports |
| Customer payment | buyer payment, receivable balance, account credit, cashbook, dashboard |
| Mandali delivery | milk sale, reconciliation, Mandali receivable, the period statement, the next settlement's expected amount, dashboard, reports |
| Vendor or custom-channel sale | milk sale, reconciliation, that buyer's receivable, its statement, dashboard, reports |
| Mandali settlement | the agreed figures, at most one receivable adjustment, the period lock, the buyer's balance, the statement |
| Buyer receipt | buyer payment, receivable balance, account credit, cashbook, the linked settlement's derived payment status |
| Animal purchase | animal, purchase event, expense, funding allocations, partner ledgers, account ledger, reports |
| Expense | expense, funding sources, account ledger, partner ledger, dashboard |
| Salary payment | payroll, payment, funding sources, financial ledger, partner ledger |

No workflow asks the user to re-enter something the system already holds.

---

## 9. File storage

Laravel's filesystem abstraction throughout, so switching to S3-compatible
storage later is a configuration change rather than a redesign.

- Employee documents, receipts and attachments live on a **private** disk and
  are never publicly reachable.
- Stored filenames are randomised; the original name is metadata only.
- Downloads go through a controller that checks the user, checks the permission,
  locates the file and streams it.
- Extension, MIME type and size are validated server-side. PDF, JPEG and PNG are
  accepted by default; the maximum size is configurable.

**The first implementation of all of that is the Mandali collection slip** (Phase 5),
in `Milk\MilkSaleSlips`: one optional file per delivery on the `local` disk, which
roots at `storage/app/private`, under a 40-character random name with an extension
derived from the file's own type. The name the browser sent survives only as metadata
for the download header, with any directory component stripped — a filename is
attacker-controlled, and it never becomes part of a path.

Both the extension and the MIME type are checked, so a `.pdf` that is really something
else is refused rather than stored. Replacement stores the new file *before* deleting
the old one, so a failure halfway leaves the old slip rather than none. A missing file
never blocks a correction: the record matters more than the attachment, and an orphan
is a smaller problem than a delivery nobody can edit.

Withdrawing a delivery **keeps** its slip, because a withdrawn sale is kept too (D21) —
deleting the document would make a withdrawn delivery unauditable exactly when somebody
asks why it was withdrawn.

The audit record carries no file bytes, no temporary path and no stored path, and
nothing anywhere exposes a storage URL for a slip. `tests/Feature/Buyers/MandaliSlipTest.php`
asserts the private disk, the randomised name, the refusals, the lifecycle and that no
other workflow accepts or serves one.

---

## 10. Notifications

Laravel database notifications with a bell and unread count, mark-one-read and
mark-all-read.

Notifications are produced by domain events worth acting on — approaching
calving, finalised salary still pending, settlement due, customer pause
starting or ending — and deliberately kept quiet enough to stay meaningful.

The dispatch layer sits behind an interface so WhatsApp or SMS channels can be
added later. Neither is implemented in V1.

---

## 11. Localisation

English (default), Gujarati and Hindi. Every user has their own `locale`,
persisted on the user record and applied by middleware.

UI text lives in `resources/lang/{en,gu,hi}` and is never hard-coded in Blade.
User-generated data — customer names, notes, animal names — is stored and shown
exactly as entered and is never translated.

Regional defaults: INR (₹), litres to 3 decimals, money to 2 decimals,
DD-MM-YYYY display, Asia/Kolkata.

---

## 12. Reports and exports

Reports are read-only queries with filters, driven by SQL aggregation rather
than in-PHP loops.

Excel, PDF and print output share one report definition, so filters, totals and
permissions cannot drift between formats. Exports honour the caller's
permissions and the selected filters. The chosen libraries and the reasoning are
in [DECISIONS.md](DECISIONS.md) D11.

**The first report in the product arrived in Phase 5**, before the module: the buyer
period statement (MASTER_SPEC section 23). It is on screen only, and it sets the shape
the rest will follow — a *view* over canonical records, reading the same services the
operational screens read, with no stored totals and no report-only table. The Phase 9
export of it renders the same arrays rather than recomputing anything (D49).

---

## 13. PWA

Installable, with a manifest, icons, theme configuration and a service worker.

The service worker caches the application shell and static assets only.
Authenticated business pages and data are network-first. There is deliberately
no offline write queue: if a save is attempted without a connection the user
sees a clear failure rather than a false success. Details in [PWA.md](PWA.md).

---

## 14. Auditing

A first-party audit log records who did what to which record, with old and new
values, IP and user agent. `App\Services\AuditLogger` is the only writer, so
redaction, actor resolution and diffing happen uniformly rather than being
remembered at each call site.

Three properties matter more than the field list:

- **Diffs, not payloads.** Only the attributes that actually changed are stored,
  so the log cannot accumulate whatever else happened to be posted alongside.
- **Central redaction.** Sensitive names are matched as whole keys and as
  substrings, so `smtp_password` is caught without being listed. The value
  becomes `[redacted]` while the field's presence is kept — "the password was
  changed" is exactly what the log should say.
- **Append-only.** No `updated_at`, no update path, no delete path.

The audit write shares the transaction of the change it describes, so a
rolled-back change takes its audit record with it.

Audited as of Phase 4 Pass 1: user creation, activation and role changes; role creation,
permission changes and deletion; password changes (as a fact, without the value);
business settings; farm creation, primary change and activation; financial
accounts; partners and contributions; expenses and their funding splits;
cancellations with their reasons; master data; price period changes; **milk
production creation and correction, internal usage creation and cancellation, and
milk adjustments with their direction, quantity and reason**; and, from Phase 4,
direct-customer creation and archiving, preference changes, pauses and their
cancellation, sale creation, update and cancellation with the reason, and customer
payments with their cancellation.

The adjustment entries matter most of the set: an adjustment changes how much milk
the system believes existed, so an adjustment without its audit record is the one
row that must never exist. A test asserts the record and the audit entry roll back
together.

Later phases add: delivery corrections, rate overrides, payments received,
settlements, animal purchases and lifecycle changes, salary and loans.

Never recorded: plaintext passwords, password hashes, password confirmations,
reset or remember tokens, application secrets, database credentials, or the
contents of private uploads. The viewer is restricted to `audit.view`.

The trail begins where the service does. **No synthetic records were backfilled**
for events that predate it — an invented history would assert facts nobody
observed. See [DECISIONS.md](DECISIONS.md) D28.

---

## 15. Security posture

Security is built in per phase, not bolted on at the end: CSRF protection,
session security, login throttling, server-side validation, server-side
authorisation, escaped Blade output, mass-assignment protection, safe uploads,
private file access, security headers, and database transactions around every
multi-record operation.

Raw SQL is avoided; where genuinely needed it uses bindings. IDs from forms are
never trusted without an authorisation check.
