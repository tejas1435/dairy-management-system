# Testing

Pest 5 on PHPUnit 13, running against **MySQL** (see
[DECISIONS.md](DECISIONS.md) D2). SQLite is not supported.

---

## Running the suite

```
php artisan test
```

Single file or filter:

```
php artisan test tests/Feature/Finance/FinancialLedgerTest.php
php artisan test --filter="idempotency"
```

`--filter` needs both suites declared in `phpunit.xml` to exist on disk. PHPUnit
treats a missing declared directory as a fatal configuration error, so
`tests/Unit` is kept with a `.gitkeep` even while nearly everything lives in
`Feature` — a business rule worth testing usually needs the database.

### Requirements

- A reachable MySQL 8.4 server.
- A `dairy_management_test` schema, created once:

  ```
  mysql -u <user> -p -e "CREATE DATABASE IF NOT EXISTS dairy_management_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
  ```

- Credentials come from `.env` (`DB_USERNAME`, `DB_PASSWORD`). `phpunit.xml`
  overrides only the connection and schema name.

> The test schema is **wiped on every run** by `RefreshDatabase`. It must never
> point at development or production data.

---

## How tests are set up

`tests/Pest.php` applies `RefreshDatabase` to the whole `Feature` suite:
migrations run once, then each test executes inside a transaction that is rolled
back. Every test starts from a migrated but empty schema and tests cannot leak
state into each other.

Factories create valid relationships so tests never depend on pre-existing local
data, and the suite is repeatable from an empty database.

---

## What is tested

Tests assert business behaviour. A test that only checks for HTTP 200 is not
considered coverage.

### Passing — 1,668 tests, 6,311 assertions, 0 warnings

Phase 1 delivered 139. Phase 2 added 402, almost all of them about money. Phase 3
added 378, almost all of them about litres — and a disproportionate share about the
difference between a quantity of zero and no quantity at all. Phase 4 Pass 1 added
236 — most of them in `Feature/Customers`, the rest from inverting the Phase 2 and
Phase 3 guards that pointed at Phase 4 — about who is owed what and about the one rule
the module turns on: a reminder quantity is never the quantity delivered. Pass 2
added a further **150** for the Customer Daily Entry grid, concentrated on the two
rules the screen exists to respect and on what happens when a day half-saves. Pass 3
added **39** more, every one of them written because the closing audit found
something rather than because it confirmed something.

Phase 5 added **324 tests and 1,384 assertions**, measured against the 1,344 and 4,927
the Phase 4 commit left behind. Pass 1 built the domain; Pass 2 added the statement,
the accounting edges, the slip lifecycle, the channel permissions, the rollback suite,
the query-count guards, the permission-coverage guard and the Phase 5 database
constraints — and extended the localisation parity guards from the Phase 1 and 2
language files to **every** phase's, which is where a large part of the count comes
from.

Phase 4's own eighteen files hold **423 tests and 1,973 assertions** between them —
roughly a third of the suite, for one module, which is what it costs to be sure
about a screen that turns typing into money. Phase 5's twelve hold **265 and 1,061**,
for four sales channels and a settlement.

The per-file inventory below covers Phases 1 to 3; Phase 4's files are listed under
[Pass 1](#phase-4--direct-customers-pass-1),
[Pass 2](#phase-4--customer-daily-entry-pass-2) and
[Pass 3](#phase-4--edge-cases-and-demo-data-pass-3), and Phase 5's under
[Phase 5](#phase-5--mandali-vendors-and-other-buyers).

#### Foundation and environment

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `EnvironmentTest.php` | 5 | Driver is MySQL; schema is `dairy_management_test`; charset is `utf8mb4`; timezone is Asia/Kolkata; `DECIMAL(14,2)` and `DECIMAL(10,3)` survive a round trip without float drift |
| `AssetPipelineTest.php` | 4 | Pages render; CSS and JS load from the Vite manifest; nothing loads from a CDN; no Tailwind class names appear, including in paginator markup |
| `DatabaseConstraintTest.php` | 62 | The guarantees that live in the schema rather than in PHP — see below |
| `MorphMapTest.php` | 33 | Every model resolves to its alias; the map is enforced; **no alias resolves to a class-name string**; no future-phase alias is registered before its model exists, and every Phase 3, 4 and 5 alias **is** registered — including `buyer_settlement` and `buyer_balance_adjustment`, added one phase at a time (D10) |
| `RouteSmokeTest.php` | 34 | Every Phase 1, 2, 3 and 4 page renders, in all three locales, with real records rather than empty states; **no Phase 6 route exists yet**. The Phase 5 pages are covered by `Buyers/Phase5ScreenTest.php` instead, where the records they need already exist |

#### Authentication and access

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Auth/LoginTest.php` | 12 | Sign in, sign out, session regeneration, last-login recording, wrong password, unknown email, inactive user refused, rate limiting after five failures, **no registration route exists** |
| `Auth/InactiveUserTest.php` | 9 | A user deactivated mid-session loses access on their next request across every authenticated area, and the session is destroyed rather than merely redirected |
| `Auth/PasswordResetTest.php` | 8 | Reset link issued and usable; identical generic response for known and unknown addresses; deactivated users are never sent a link and cannot redeem a token issued while active; invalid token rejected |
| `ProfileTest.php` | 11 | Self-service update; email uniqueness and re-verification; **roles and active status cannot be self-changed**; current password required; other sessions for that user ended, others untouched |
| `Admin/UserManagementTest.php` | 14 | Permission enforcement on read *and* write; create, edit, role assignment, activate/deactivate; password hashed; blank password preserves the existing one; self-deactivation refused; no delete route; hashes never rendered |
| `Admin/RolePermissionTest.php` | 33 | All permissions and 7 roles seeded; **Super Admin's access comes from its permissions, not its name**; Viewer holds only non-sensitive `.view`; operational roles get no money permissions; a custom permission is not granted to Super Admin; re-seeding does not undo an administrator's edit to any other role; **a source scan fails the build if `Gate::before` or `hasRole()` reappears** |
| `Phase2AuthorizationTest.php` | 24 | Every Phase 2 page refused without its permission and allowed with it; every write refused without its own permission, not merely hidden; `partner.finance.view` gates the ledger separately from `partner.view`; the seeded Accountant, Data Operator and Viewer reach exactly what they should |

#### The ledger

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Finance/FinancialAccountTest.php` | 24 | **There is no stored current-balance column**; the derived balance is opening + credits − debits to the paisa; opening balance survives without float drift and locks once entries exist; a negative balance is represented rather than refused; the list query derives the same figure as the per-record method; no delete route |
| `Finance/FinancialLedgerTest.php` | 19 | One entry per posting; zero and negative refused; **the same domain effect posted twice yields one entry and one balance change**; the unique index catches a duplicate that slips past the pre-check; two genuinely different operations with identical amount, date and account both post; a posted entry cannot be updated or deleted; reversal leaves the original and posts one opposing entry; **the database refuses a second reversal**; a reversal cannot itself be reversed |
| `Finance/CashbookTest.php` | 14 | Running balance exact after every row; deterministic date-then-id order; a date range narrows rows while carrying earlier history into the opening; **page two starts from the balance carried out of page one**; the last page closes on the same figure as the unpaginated account balance; pagination and a date filter combine without corrupting the balance; an account from another business cannot be opened |

#### Expenses and split funding

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Finance/ExpenseFundingTest.php` | 9 | One expense row per expense; **the total counts the expense, never the expense plus its allocations**; a partner-and-cash split debits only the cash share; the partner ledger shows the partner's share; thirds that do not divide evenly still sum exactly; single-paisa amounts preserved; no animal is created by a Phase 2 animal-purchase expense |
| `Finance/FundingValidationTest.php` | 19 | Underfunding *and* overfunding by a single paisa both rejected with nothing written; negative, zero, empty, non-numeric and unknown-type rejected; a source from another business rejected; an inactive source rejected; **the same source listed twice rejected rather than silently doubled**; the same id under two different source types allowed, because they are different records; blank trailing form rows ignored rather than failing |
| `Finance/ExpenseCancellationTest.php` | 12 | Cancelling preserves the expense and its split while reversing **only the account share**; a cancelled expense leaves active totals and the partner ledger; cancelling twice adds no second reversal; a reason is required; **cancelling a partner-only expense touches no account at all**; descriptive fields remain correctable while amount, date and funding cannot be changed through the form |
| `Finance/ExpenseQueryCountTest.php` | 5 | The expense list, the expense detail page and the partner ledger each cost the same number of queries regardless of how many related records they show — asserted as exact equality, so a query per row fails the build |

#### Partners

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Finance/PartnerContributionTest.php` | 18 | A contribution credits the destination account and appears in the partner ledger, the cashbook and the balance at once; two genuine contributions of the same shape stay two records while a replayed ledger effect cannot double-credit; inactive partner, foreign account and inactive account all refused; cancelling reverses the credit and twice adds no second reversal; one partner's ledger never shows another's money; the bulk list totals agree with the per-partner totals; **there is no partner ledger table** |

#### Atomicity (finance)

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Finance/TransactionRollbackTest.php` | 7 | Each multi-step write rolls back *completely* when an injected failure hits the ledger posting, the audit write or the second allocation — no orphan expense, no partial split, no ledger entry without its record, and a failed price transition leaves the previous period open |

#### Masters, buyers and pricing

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Masters/MasterDataTest.php` | 29 | System codes and slugs seeded and stable; **renaming leaves the code alone**, so business logic is unaffected; a system row cannot be deleted; a row in use cannot be deleted; an unused custom row can be; a custom channel cannot claim a reserved slug; **every Phase 2 seeder run twice creates no duplicates** and does not reopen a price period an administrator has closed; settings writes refused without the permission |
| `Masters/BuyerTest.php` | 21 | One buyer table serves every channel; outstanding is not a stored column; **each channel maps to its own permission family**, and view, create, update and channel-moves are each checked against the right family; a custom channel resolves to the customer family; the list shows only permitted channels; **buyer authorisation never branches on a role name**; no Phase 4 or 5 buyer feature exists yet |
| `Pricing/MilkPriceTest.php` | 24 | The first price opens an open-ended period; a change closes the old one without rewriting it and **the old rate value is never altered**; same-day, earlier and inside-a-closed-period starts all refused; adjacent periods allowed; **the database refuses two rules starting on the same day even outside the action**; no date resolves to two rates; a future period can be withdrawn and reopens the one before it, while an effective period cannot; there is no way to edit a rate in place |
| `Pricing/PriceResolverTest.php` | 19 | Buyer override beats the business default; fallback when absent, expired or not yet effective; resolution follows the **sale date, not today**; boundaries inclusive at both ends; **a missing price is an explicit failure, never zero**, and reading its rate throws rather than returning a number; no leak between milk types, buyers or businesses; the resolution reports which rule it came from |

#### Audit

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Audit/AuditLoggerTest.php` | 42 | Actor, action, stable alias and id captured; a write with no HTTP context records no actor or IP **rather than inventing them**; only changed fields recorded; a no-op change writes nothing; save noise never recorded; **19 sensitive field names redacted, case-insensitively, in old values as well as new**; a real bcrypt hash never reaches the log; ordinary fields not redacted by accident; an audit record cannot be updated or deleted through the model, and no route exists to try |
| `Audit/AuditCoverageTest.php` | 14 | Every sensitive Phase 1 mutation is audited — user creation, activation, role changes, role permission changes, role deletion, business settings, primary-farm change, farm creation and deactivation; a password change is recorded **as a fact without the value**; `audit.view` is enforced by the server, not by a hidden menu; **no records are invented for changes that predate the audit service** |

#### Milk production

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Milk/MilkProductionTest.php` | 38 | **No `milk_type` column, and the unique key is exactly (farm, date, shift)** — read back from `information_schema`, along with `DECIMAL(10,3)` and `DATE`; one row per shift holding both types; the evening becomes its own second row; **re-saving updates one row and leaves the other shift alone**; a correction keeps `created_by` and moves `updated_by`; the audit keeps old and new quantities while an unchanged re-save writes nothing; the database rejects a duplicate inserted behind the application; the primary farm is resolved and a posted `farm_id` is not read; promoting another farm moves future production without moving history; negative, four-decimal and out-of-range quantities refused; **a recorded 0.000 is production entered** |
| `Milk/MilkQueryCountTest.php` | 6 | The usage, adjustment and reconciliation screens each cost the same number of queries however many records they show, asserted as exact equality; one reconciliation unit is exactly 3 queries |

#### Missing production versus a recorded zero

Covered inside `Milk/MilkReconciliationTest.php` as an explicit matrix, because it
is the rule the whole module is built around:

| Case | Asserted |
| ---- | -------- |
| No row at all | `productionEntered` false, quantity 0.000 |
| Row with cow 0.000 | `productionEntered` **true** for cow |
| Same row, buffalo 5.000 | `productionEntered` true, quantity 5.000 |
| Morning recorded, evening absent | the two shifts report differently |
| Cow zero, buffalo positive | **both** milk types report entered, because the shift row exists |
| A missing row beside a recorded zero | identical quantities, opposite flags |

#### Exact quantity arithmetic

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Unit/QuantityTest.php` | 32 | `10.000 − 3.333 − 3.333 − 3.334 = 0.000` **exactly**; the same sum in binary floats does not reach zero; `1.000 − 0.999 = 0.001`; `10.000 − 10.001 = -0.001` and stays negative; `0.100 + 0.200 = 0.300`; normalisation of integers, floats, leading dots and excess precision; `null` and `''` are absent and become zero; **`'12,500'`, `'abc'` and `'10 L'` throw rather than silently becoming 0.000** |

The only Phase 3 concern that needs no database, so it lives in the `Unit` suite
and runs in well under a second.

#### Internal usage

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Milk/MilkUsageTest.php` | 41 | The five usage types and no others; each persists its machine value, never the translated label, and has a real label in all three locales; 2.000 against 10.000 leaves 8.000 and a further 1.250 leaves 6.750 with an exact per-type breakdown; **usage is refused when production was never entered**, with a different message from "not enough milk"; a recorded zero refuses for the availability reason instead; production in the other shift or of the other milk type does not make this one allocatable; **exactly available succeeds, one millilitre more fails**, including at a 0.001 remainder; uneven thirds allocate to exactly 0.000; **a locking read precedes the insert, inside one transaction**; cancelling keeps the row, records the reason, leaves `available` unchanged and returns the milk; cancelling twice is refused and adds no second audit entry; a cancelled usage frees the milk for a fresh allocation; no `DELETE` route exists |

#### Adjustments

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Milk/MilkAdjustmentTest.php` | 40 | A `direction` column and a **positive** quantity on disk, with the sign only in `signedQuantity()`; `reason` is `NOT NULL` in the schema; increase 1.250 and decrease 0.500 net to +0.750; an increase and an equal decrease net to zero **while both stay visible**; zero, negative and invalid direction refused; a blank or whitespace-only reason refused by the form *and* by the action; **no adjustment appears because an allocation would have overrun**; a decrease below what is already allocated refused, exact to the millilitre, with the remaining figure named; **cancelling an increase that allocations depend on refused, and no false cancellation audit written**; once the dependent usage goes, the withdrawal succeeds; cancelling a decrease is always safe; the audit carries direction, quantity, reason, date, shift, milk type, actor and subject, as a stable alias, and **no request payload beyond the recorded fields** |

#### Reconciliation

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Milk/MilkReconciliationTest.php` | 33 | `available = production + net adjustments`, `allocated = sales + usage`, `remaining = available − allocated`; the missing-vs-zero matrix above; **cow and buffalo reconcile independently from the same shift row** (20.000/8.000 with their own usage and adjustments give remaining 16.000 and 5.500); morning and evening isolation; date isolation across three consecutive days on the business `DATE` column; one farm never sees another's records; the usage breakdown sums to 2.000 from five types and every type is present as a stable key; cancelled usage and cancelled adjustments excluded; an over-allocated shift reported as such with `-0.001`; `wouldOverAllocate` exact at the boundary |

#### The sales seam

Phase 3 built the seam while `NoMilkSalesRecorded` was bound. Phase 4 swapped the
binding, so these assertions were **inverted rather than deleted** — each still
protects what it was written for.

| Asserted | Where |
| -------- | ----- |
| `RecordedMilkSales` is the bound allocator and `subsystemExists` is **true** | `Milk/MilkReconciliationTest.php` |
| The allocator reports the seeded channels, so the breakdown is data-driven rather than hard-coded | same |
| Seeding the three sales channels does not fabricate channel rows | same |
| `milk_sales` is generic across channels — no channel-specific column | same |
| The retained `NoMilkSalesRecorded` can still be bound explicitly and still reports `subsystemExists` false | same |
| An empty allocation from a real subsystem **still reports that the subsystem exists**, so zero sales does not read as no sales module | same |
| The milk seeder creates no sales, even though the table now exists | `Milk/MilkSeederAndBoundaryTest.php` |
| **A replacement allocator feeds the engine without the engine changing** — a stand-in reports 5.000 Mandali and 3.000 direct, and allocated and remaining move accordingly | `Milk/MilkReconciliationTest.php` |

The replacement test is the one that mattered, and it has now been paid off in
production: Phase 4 changed one `singleton()` line in `AppServiceProvider` and the
reconciliation engine was not touched at all (D32).

#### Authorisation

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Milk/MilkAuthorizationTest.php` | 34 | Every milk page refused without its permission and allowed with it; guests redirected; saving production refused with only `.view`, and `milk.production.update` alone is not a way in through the create endpoint; **`milk.production.delete` grants access to nothing, and no `DELETE` verb exists under `/milk`**; `milk.usage.create` does not imply `milk.usage.cancel`; **production and usage rights do not confer adjustment rights**; `milk.adjustment.create` does not imply `milk.adjustment.cancel`; the four new identifiers are seeded in the `milk` group and there is no `milk.usage.update`; the seeded Manager records usage but cannot cancel or adjust; Data Operator enters production and usage only; Owner holds the exceptions; Viewer and Accountant read and change nothing; a revoked permission takes effect on the next request; the adjustments link is hidden from those who cannot use it |

#### Screens

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Milk/MilkScreenTest.php` | 30 | The reconciliation screen shows the engine's figures (20.000 production, 21.000 available, 17.750 remaining), the usage breakdown it has and not the types it does not, increases and decreases separately, **"Production not entered" where it applies and a real 0.000 where the shift was recorded**, and the sales explanation; quantities to exactly three places with a unit; shift and milk-type filters narrow the page and an unrecognised filter value is ignored; the production matrix shows stored quantities, row and column totals, the unentered badge and who entered the shift; date navigation; the usage list shows cancelled rows with their reason and an empty state; **the adjustment quantity field is never pre-filled**; a row per sales channel now that sales can exist, with no trace of the Phase 3 "not implemented" wording; all four screens render in Gujarati and Hindi with no raw dotted key and no machine enum value |

#### Atomicity (milk)

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Milk/MilkTransactionRollbackTest.php` | 9 | An injected audit failure rolls back a production save, a usage record, a usage cancellation, an adjustment record and an adjustment cancellation — completely, each time; **a whole-day save whose evening fails leaves neither shift**; a failed correction leaves the earlier figures intact; a failed write leaves the remaining figure exactly as it was. An adjustment without its audit record is the one row that must never exist, so that case is asserted directly |

#### Schema, seeding and the phase boundary

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `DatabaseConstraintTest.php` | 62 (+33 in Phase 3) | Milk quantities are `DECIMAL(10,3)` and dates are `DATE` on all three tables; enumerations are `VARCHAR`, never MySQL `ENUM`; production is unique on farm, date and shift **and nothing else**; a duplicate inserted behind the application is rejected; an adjustment cannot be inserted without a reason; a milk record cannot point at a missing farm; a farm with milk records cannot be deleted; deleting the recording user keeps the record and nulls the attribution; usage and adjustments have cancellation columns and **production deliberately does not** |
| `Milk/MilkSeederAndBoundaryTest.php` | 45 | Running the milk seeder three times creates no duplicates; **the deliberately unentered evening shift stays unentered across re-seeds**; no seeded shift is over-allocated; the three milk models resolve to their stable aliases and an audit record stores the alias rather than a class name; every Phase 3, 4 and 5 route exists — including the three statements, the custom-channel profile and the slip download — while **no Phase 6 route or table does**; the Phase 4 and 5 tables exist and the Phase 6 ones do not; the generic buyer master is not mistaken for the customer workflow; the sidebar advertises no unbuilt module. The forward-looking half of this file is **inverted rather than deleted** each phase: what was "no Phase 5 route exists" is now "every Phase 5 route exists", and the guard moved on to Phase 6 |
#### Localisation and PWA

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `LocalizationTest.php` | 28 | English default; per-user Gujarati and Hindi; guest switching; persistence; unsupported locale rejected; stale session value falls back safely; **key parity across all three locales** and **no locale file left as English text** |
| `PwaManifestTest.php` | 8 | Manifest content type and fields; icons at 192/512 plus maskable; **every declared icon exists at the declared size**; locale-aware; linked from pages; **no service worker is claimed** |

Several of these are guards rather than feature tests, and they are the ones most
worth keeping. `EnvironmentTest` fails if the suite is pointed back at SQLite. The
localisation parity test fails if a translation file is copied from English and
left untranslated, which a key-count check would not catch. The source scan in
`RolePermissionTest` fails if a role-name authorisation shortcut reappears.
`DatabaseConstraintTest` asserts the guarantees that must survive a bug in the
application layer: unique idempotency keys, one reversal per entry, `RESTRICT` on
every reference that would otherwise orphan a record, money columns that are
`DECIMAL` rather than `float`, and an `audit_logs` table with no `updated_at`.

---

### Phase 4 — Direct Customers (Pass 1)

Eight files under `tests/Feature/Customers/` — **233 tests, 718 assertions**.

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `DirectCustomerTest.php` | 30 | One `buyers` table serves the channel — **no second customer identity table**; the direct-customer channel is assigned **server-side** and a posted `sales_channel_id` is ignored on create *and* on update; `delivery_note` and `start_date` live on the buyer, reminders do not; a preference is one row per milk type and unique on it; a buffalo-only customer carries no cow row; `start_date` in the future makes a customer not yet deliverable; archiving is reversible and never deletes |
| `CustomerPauseTest.php` | 39 | An open-ended pause (`end_date` null) covers every later date; a closed pause is inclusive at both ends; overlapping pauses are refused; `end_date` before `start_date` is refused; a cancelled pause covers nothing; `pausedMapFor()` answers a whole grid in one query; the timeline separates current, upcoming, past and cancelled; **a paused customer is returned marked, not dropped** |
| `CustomerMilkSaleTest.php` | 50 | The rate is **snapshotted** on the sale and a later price change does not move a recorded sale; a missing price is a refusal, never a zero rate; the amount is computed on the server, half-up, and a posted amount is ignored; availability is asserted **inside** the write transaction and an over-allocating sale is refused; re-saving the same farm/customer/date/shift/milk type **updates** rather than duplicating; an emptied cell cancels and does not delete; a cancelled row reactivates in place; a non-grid source is not constrained by the grid key; a sale for an archived, unstarted or paused customer is refused |
| `CustomerPaymentTest.php` | 42 | Outstanding is **derived** — active sales minus active payments, with the adjustments term present and zero; paying exactly the outstanding is allowed and **one paisa more is refused**; any payment against zero outstanding is refused; a payment credits its funding account through the ledger under a deterministic key; cancelling reverses exactly once and a second cancellation is refused; the reversal restores the outstanding to the paisa; `outstandingForMany()` agrees with the per-customer figure |
| `CustomerAuthorizationTest.php` | 26 | Every customer route and every write refused without its own permission and allowed with it, including `customer.archive` and `customer.payment.cancel`; a Mandali buyer does not become reachable through the customer screens; authorisation never branches on a role name |
| `CustomerScreenTest.php` | 31 | The profile, statement, pause and payment partials render with real records; the statement runs in date order with **sale before payment on the same day** and a correct running balance; reminders are shown as help text beside a field, never inside one; three locales with no raw dotted key and no machine enum value |
| `CustomerTransactionRollbackTest.php` | 10 | An injected failure at each write leaves nothing half-written: no sale without its audit record, no payment without its ledger entry, no pause with a partial row |
| `CustomerQueryCountTest.php` | 5 | The customer list, the profile, the statement, `outstandingForMany()` and the batch eligibility query each cost the same number of queries for one record as for many |

---

### Phase 4 — Customer Daily Entry (Pass 2)

Eight files under `tests/Feature/Milk/CustomerDailyEntry*` — **151 tests, 568
assertions**.

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `CustomerDailyEntryScreenTest.php` | 26 | Thirty customers load with no pagination; a customer taking both milk types gets two rows and a buffalo-only customer gets no cow row; archived, not-yet-started and preference-less customers are absent while a customer starting today is present; no Mandali or vendor buyer reaches the grid. **Every quantity input renders `value=""` when nothing was delivered, however large the reminder**, with the reminder present as text beside it — plus a source guard that the templates and the JavaScript never mention the reminder columns. A saved day prefills its actual figures; a cancelled delivery leaves the field empty while its row survives. A paused customer stays visible, marked, with disabled inputs and no override control anywhere. A row with no resolvable price says so instead of showing ₹0, while an unpriced row that already has a sale stays editable on its own snapshot. The rate column shows the stored snapshot, not a later override, and flags a row whose shifts differ. No manual rate field exists. Date navigation, filters, remaining-milk summary, the empty state, the specification's six totals, and the whole screen in Gujarati and Hindi with no raw keys |
| `CustomerDailyEntrySaveTest.php` | 40 | The day saves from a JSON body, and the module posts `application/json` with a CSRF header and no Axios or jQuery. Posted amounts, rates, farm ids, channels, sources and statuses are **ignored entirely**. Four decimal places, negatives and duplicate buyer-and-milk-type rows are refused; an empty day is a legitimate save. Create, update, no-op, cancel and reactivate, with blank, `0` and `0.000` proven interchangeable and none of them creating a zero-quantity row. **Raising an existing 4.000 to 5.000 on a shift with 1.000 remaining succeeds**, 5.001 fails, 3.000 succeeds — the update replaces its own allocation rather than adding to it. Several customers fill a shift exactly and one millilitre more fails the whole day; milk moved between two customers saves whatever order the rows arrive in; a cancellation in the same request frees milk for another customer; cow and buffalo never borrow from each other. Missing production is reported as missing and a recorded zero is not. **A quantity edit keeps the sale's stored rate** while a new cell on the same day takes the current resolution; a new sale cannot be created without a price, and an existing one survives its price being withdrawn. Saving twice changes nothing the second time and writes no audit record; changing one cell of a saved day touches only that cell. One bad cell rolls back every good one. The response carries the server's own totals, and ₹283.31 agrees on both sides |
| `CustomerDailyEntryCopyPreviousTest.php` | 16 | Morning and evening, cow and buffalo independently; an absent shift stays absent rather than becoming zero; a customer with nothing yesterday is left out; a **cancelled** sale is not resurrected; only the previous calendar day and only grid sales are read. Nothing is offered to a customer paused, archived or no longer taking that milk type today, or to a row with no price. **The copy writes no sale, no audit record and no receivable**, returns quantities only — no rate, no amount — and nothing copies on page load. Copied quantities price at the selected date, leaving yesterday's sale untouched |
| `CustomerDailyEntryAuthorizationTest.php` | 20 | The grid needs `milk.customer_delivery.view` and another milk permission does not open it; the save and copy endpoints are closed without it. A viewer cannot write; `create` records a new delivery but cannot change or remove an existing one; `update` can remove one but cannot add one; a mixed save needs both and half of them saves nothing; an unchanged re-save needs no write permission. A viewer sees the day read-only with no controls. All seven seeded roles behave as the matrix says. No role-name branching |
| `CustomerDailyEntrySecurityTest.php` | 24 | Hand-written payloads: a Mandali or vendor buyer, a buyer from another business, a non-existent id, an archived, not-yet-started or paused customer, a milk type they do not take, a deactivated preference. Negatives, four decimals, out-of-range values, non-numerics and a comma decimal separator. A forged rate, amount, farm id and source are all discarded, and the forged source does not escape the grid identity. Reminder fields submitted as data are not read as deliveries. No invented flag — `force`, `save_anyway`, `skip_availability`, `create_adjustment` — authorises an over-allocation or conjures an adjustment. **A pause created later neither cancels nor re-prices a delivery already recorded**, though it does bar further edits. Errors carry no stack trace or SQL |
| `CustomerDailyEntryIntegrationTest.php` | 11 | Production 20.000, usage 2.000, two customers taking 12.500 through the grid gives allocated 14.500 and remaining 5.500. The grid feeds the direct-customer channel and no other; three saves of the same day count once. A grid entry becomes a receivable with no manual posting (3.500 L at ₹70.00 → ₹245.00 outstanding), appears in the ledger with its morning and evening split, moves to the corrected figure when edited, and disappears from reconciliation, ledger and outstanding when removed while keeping its history. Re-entering restores exactly one delivery everywhere. A payment settles it to the paisa. **A sale posts nothing to the financial ledger**, because a receivable is not cash |
| `CustomerDailyEntryRollbackTest.php` | 7 | Injected model-event failures on the third cell, the second cell, the audit write, an update partway through a correction, and a cancellation. Each time: nothing written, nothing changed, no audit record, the milk unallocated and the customers owing nothing. The rollback holds through the HTTP layer too |
| `CustomerDailyEntryQueryCountTest.php` | 7 | The grid costs the **same** for 1 customer as for 30; the row builder is **7 queries** for 1 customer and for 50; price priming is **2 queries** for 25 customers and resolution needs none afterwards; priming agrees with one-at-a-time resolution including overrides and invents no price where there is none; a day re-saved unchanged costs no per-row writes; and source guards that the save payload is built from editable rows rather than visible ones, so filtering can never clear a row |

---

### Phase 4 — Edge cases and demo data (Pass 3)

Two files — **39 tests, 687 assertions**. Both exist because of something the final
audit found rather than something it confirmed.

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `Milk/CustomerDailyEntryMixedRateTest.php` | 18 | A row holds **two** sales, so the two shifts can be priced differently and both be right. A morning recorded at ₹70.00 followed by a customer rate of ₹72.00 covering the same date: the morning keeps its snapshot, a new evening resolves ₹72.00, and entering 1.000 L in each gives a row total of **₹142.00** — not 2 L at either rate. The grid shows one figure only when both shifts share it and a labelled pair when they do not, and the browser is handed a rate per cell in integer paise. Two historical snapshots on one row both stand exactly as stored: changing either shift's quantity provably leaves the other's rate, amount and quantity untouched, and nothing normalises them to match. When the price for the date is withdrawn, the recorded morning stays correctable and clearable on its own snapshot while a new evening is refused and its input closed — per cell, not per row — and Copy Previous Day stages nothing into the closed cell. The statement reports both rates rather than inventing one. A pause created afterwards over the recorded day leaves both shifts, both rates and the outstanding balance untouched through loading, re-saving and copying, while still refusing a change |
| `SeedIntegrityTest.php` | 27 | The whole seeding path run against the test schema, asserting arithmetic rather than counts. Every seeded sale carries the source its own workflow owns, and **every channel has sales through its own workflow** — a grid sale for a direct customer, a Mandali delivery with its readings, a vendor sale at a resolved rate, a generic sale at a typed one. **No shift is over-allocated** and nothing allocates milk against production that was never recorded; the deliberately unentered evening has no sale; no delivery exists for a paused, archived or not-yet-started customer, or for a milk type they do not take. **No seeded quantity equals that customer's reminder.** Every amount is its own quantity times its own snapshot, every rate is what the resolver returns, and both resolver branches are exercised. Outstanding equals active sales minus active payments for all twenty customers with adjustments a named zero, nobody is in credit, and both a settled and an unsettled customer exist. Each payment credits its account exactly once, **a milk sale posts nothing to the financial ledger**, and account credits match receipts. Running the seed three times changes nothing — rows, litres, money, ledger and audit count alike — no duplicate reference or ledger entry appears, the seeders use stable identities rather than an empty-table check, and a hand-added customer does not disable them — the Phase 5 seeder included. Nothing cancelled is seeded.

Phase 5 added the Mandali, vendor and custom-channel sales, the draft settlement and its receipts to all of the above, plus what "idempotent" means about **dates**, stated precisely because it is the part that surprises people: every seeded business date is an *offset* from the day the seeder ran, so a fresh installation always shows the last four days. Re-seeding the same day writes nothing. Re-seeding five days later adds **that** window beside the first rather than duplicating it — the original dates survive untouched, the new ones reconcile exactly as they did, and a developer who seeds, works for a fortnight and seeds again gets more demo data rather than doubled demo data |

---

### Phase 5 — Mandali, vendors and other buyers

Twelve files under `tests/Feature/Buyers/` — **265 tests, 1,061 assertions**.
Pass 1 wrote five of them (the domain), Pass 2 added seven (the statement,
the accounting edges, the slip, the channel permissions, the rollback suite, the
query-count guards and the permission-coverage guard) and extended the rest.

| File | Tests | Asserts |
| ---- | ----: | ------- |
| `MandaliDeliveryTest.php` | 34 | A Mandali is a buyer in its channel with **no table of its own**. Fat and SNF are recorded and read by nothing: two deliveries with the same quantity and rate and different readings cost the same, changing a recorded reading leaves the amount, and a source scan proves no pricing code reads a quality column. The amount is quantity × the **manually typed** rate, which ignores the configured business price; an empty or zero rate is refused; 3.333 L × ₹85.00 is exact. A posted amount, source, channel or farm id is never trusted. A vendor or customer cannot be given a Mandali delivery. Several deliveries to one Mandali in one shift are allowed. The delivery allocates milk, reaches reconciliation, cannot take milk the shift does not have, and reports unentered production as unentered rather than as zero; raising one does not double-count its own allocation. It creates a receivable and **no ledger entry**; withdrawing it returns both. Quality readings outside 0–15, at three decimals or non-numeric are refused, both ends of the range are accepted, and fat is required while SNF is not. **A delivery may take the whole shift and not one thousandth more**, on creation and on correction |
| `VendorAndGenericSaleTest.php` | 22 | A vendor and a sweet shop are both just buyers. A vendor sale with no typed rate takes the configured one, a buyer-specific rate beats the business default, and retyping the configured figure is **not** an override. A different figure needs `milk.sale.override_rate` **and** a reason, as does typing a rate where none is configured — and `milk.customer_delivery.override_rate` does not authorise either (D45). A vendor sale with no rate and none configured is refused outright rather than priced at zero. Correcting a quantity keeps the rate it was sold at; changing the rate needs the permission and a reason, and re-submitting the same rate is not a change. A custom-channel sale takes a typed rate with no override permission, needs a rate, and the generic form **refuses every system channel**. The four channels land in their own reconciliation buckets, counted once; each sale creates a receivable and no cash entry; withdrawing one returns the milk and the receivable |
| `MandaliSettlementTest.php` | 40 | A draft has **no accounting effect at all** and can be opened with no statement; its statement amount is editable while a finalized one is not. Finalization snapshots the quantity and the amount **from the sales' own stored rates**, counts only this Mandali's active deliveries in the period, and rewrites no historical rate. A statement above or below the expected amount moves the receivable by exactly the difference through **one** adjustment; equal amounts and no statement create none. Finalizing twice is refused and the **database** refuses a second active adjustment, while a cancelled one does not block a replacement. Two non-cancelled periods may not overlap (a dataset of six arrangements), adjacent periods are fine, a cancelled settlement frees its period and another Mandali may settle the same one. A **finalized period is closed**: corrections, withdrawals and new deliveries inside it are all refused, a draft closes nothing, a customer sale is unaffected, and withdrawing the settlement reopens it. Cancelling withdraws the adjustment with it but never a receipt; a settlement with an active receipt cannot be withdrawn until the receipt is; `Cancelled` is terminal. Payment status follows the receipts, a receipt cannot exceed what the settlement still owes, cannot attach to a draft, cannot belong to another buyer, and credits its account exactly once |
| `SettlementAccountingTest.php` | 21 | The accounting edges rather than the lifecycle. **Which figure is due**: the statement amount when there is one (and the difference is not owed twice), the system figure when there is not, never below zero, and nothing at all while it is a draft. **The server owns every figure but the statement amount**: a finalize request posting `milk_quantity`, `expected_amount`, `difference` and `status` has all four ignored and recomputed; the expected amount follows the sales as they stand at finalization, not as the draft previewed them; a sale one day outside the period is not counted; and **a sale's date cannot be edited at all**, so nothing can be moved into a settled period. **A refused operation leaves nothing behind**: an overlapping settlement is not created, a refused finalization writes no adjustment and moves no balance, a second finalization duplicates neither, a refused payment leaves no receipt and no account movement. **The ceilings, to the paisa**: 2,833.31 may be paid and 2,833.32 may not; a settlement owing 0.01 takes 0.01 and refuses 0.02; an unlinked receipt is still capped by the whole outstanding. **Linked and unlinked are different things**: an unlinked receipt reduces the balance and moves no settlement's status, a receipt against September does not pay October, and withdrawing a linked one moves the status back. The adjustment history survives a withdrawal, and a zero difference leaves nothing to withdraw. Plus the status filter on the settlement list |
| `MandaliStatementTest.php` | 20 | Every figure hand-calculated. Three October collections at three rates — 40.000 × 72.00, 35.500 × 71.50 and 30.250 × 73.25 (= **2,215.81**, half-up) — totalling **₹7,634.06** over 105.750 L. The summary reports the milk, the expected sales, a +250.00 adjustment, a 5,000.00 receipt and an outstanding of **2,884.06**. The balance is the **whole** balance: a September that was collected and paid in full still shows October's 7,634.06 owing. A period with nothing in it shows its opening balance rather than an empty account. The detail shows three distinct rates and amounts, the fat and SNF beside them, and both shifts; adjustments and receipts appear as themselves with a running balance and are not folded into the sales; a withdrawn sale leaves. The period defaults to the current month with working arrows; an explicit `from`/`to` narrows it, puts the earlier sale in the **opening balance**, and relabels the heading; four malformed months — including `2026-13` — each show the current month rather than failing or jumping a year. A settlement covering the period is listed with its own figures, one for another period is not, and a vendor has no settlement section at all. Authorisation is the channel's own view permission, the wrong channel route is a 404, the profile links through with its period, and the whole page renders in Gujarati and Hindi with no raw keys |
| `BuyerPaymentChannelTest.php` | 17 | A custom-channel buyer has a list, a profile and a statement of its own — the receivable the generic sale form raises has somewhere to be seen and settled (D48). The list holds **every** custom channel and only those; a system-channel buyer 404s there and a custom-channel buyer 404s on the Mandali and vendor routes; with no custom channel the list says so rather than breaking. A receipt is recorded and withdrawn through the profile of all three non-customer channels, each time restoring the balance exactly. **The families are independent in both directions**: each channel resolves to its own payment permission, `mandali.payment.create` pays only a Mandali, `customer.payment.create` pays only the customer family (which is where a custom channel belongs), and a Mandali's cancel right does not withdraw a vendor's receipt. The other-buyers screens need `customer.view` and nothing wider, the payment form is absent for a user who may only read, the sidebar link appears only for whoever may follow it, and the screens render in Gujarati and Hindi |
| `MandaliSlipTest.php` | 19 | The collection slip, against a faked disk. An upload named `../../september statement.pdf` is stored inside the one directory under a **random** basename with the file's own extension, no traversal sequence and no part of the submitted name; the submitted name survives only as the display label. The disk is private, roots outside the document root, and nothing hands out a URL. An executable, a spreadsheet, an oversized file and a `.pdf` that is really HTML are each refused and nothing is written. The download works for a user who may view the Mandali and is **forbidden** to one who may only see milk sales; a sale with no slip and a slip whose file has vanished are both 404s rather than errors. Replacing stores the new file and deletes the old, a correction that does not mention the slip keeps it, removing clears the columns and the file, and **withdrawing a delivery keeps both** — a withdrawn sale is kept too, and the document that supported it is the reason it was withdrawn. No other workflow accepts a slip (the Form Request **prohibits** it) or serves one, and a Mandali slip cannot be fetched through another workflow's route. The audit log records attaching, replacing and removing — each would otherwise be an empty diff and no record at all — with the display name and an attached flag, and **never the stored path**, including when a whole model is audited |
| `BuyerOutstandingTest.php` | 28 | **One** arithmetic for every channel: the same figure for a Mandali, a vendor, a custom channel and a direct customer, asserted as a dataset, with a source scan that no per-channel outstanding service exists. The bulk figures agree with the per-buyer ones and cost **three** grouped queries whatever the buyer count. There is no screen for adjusting a balance by hand — every route whose path contains `adjust` is enumerated, and only the two Phase 3 milk routes are allowed. An adjustment changes no milk, no reconciliation figure and no account; the amount is always positive with the direction carrying the sign; a reason and a positive amount are required; **a decrease cannot push a buyer into credit** while an increase is never refused; withdrawing one restores the balance with no second correction and cannot be done twice; one buyer's settlement cannot carry another's adjustment. There is no per-channel payment table; a payment from any channel credits its account exactly once; withdrawing one reverses the credit once; a payment may not exceed the outstanding on any channel, and an adjustment raises that ceiling. A direct customer with no adjustments behaves exactly as it did in Phase 4 |
| `Phase5ScreenTest.php` | 39 | Every Phase 5 page renders with real records rather than empty states. The Mandali profile shows its ledger, outstanding and settlements and ₹3,600.00; the vendor profile has **no** settlement section rather than an empty one; the settlement screens render through the whole lifecycle, draft help and finalize control giving way to the snapshots and the recorded difference. A sale edits only through its own workflow — crossing them is a 404 — and a buyer is not reachable through the wrong channel's profile. Fat, SNF and the slip field appear on the Mandali form and **nowhere else**; each form explains its own rate rule; the generic form lists only custom-channel buyers. Authorisation: the channel lists need their own channel's view permission (`mandali.view` does not open the vendor list), viewing sales does not imply recording them, settlements need both the permission and a Mandali, and all six seeded roles reach exactly what the matrix says — including the statement. A source scan over ten Phase 5 classes fails on `hasRole`, `hasAnyRole`, `hasAllRoles` or any seeded role name. Each profile links to its own sale workflow with the buyer pre-selected, a buyer from another channel in the query string selects nobody, and the link is hidden from whoever may not record a sale. The sidebar links to every Phase 5 screen and advertises nothing unbuilt; **no Phase 6 route or table exists**; every screen renders in Gujarati and Hindi with no raw dotted key |
| `BuyerQueryCountTest.php` | 7 | Exact equality between a page showing one related record and the same page showing many, caches warmed first: the Mandali list for 1 and 20 Mandalis each with sales and receipts, the other-buyers list for 1 and 20, the trade profile and the period statement for 1 and 40 transactions, the settlement list for 1 and 12 settlements each with a receipt — the place where a derived paid-total would turn into a query per row — and the channel sale list for 1 and 30 sales. The baselines deliberately contain one receipt, because with none Eloquent skips the payment eager loads entirely and the comparison would measure that instead |
| `Phase5RollbackTest.php` | 9 | Atomicity, with failures injected through Eloquent model events registered inside the test, so no test-only failure switch ships. **Finalization is the one that matters**: with its adjustment failing, and again with its audit record failing, the settlement stays a draft with all four snapshot columns null and the balance untouched — a settlement that froze ₹7,500.00 without its adjustment would claim an agreed figure while the balance still said ₹7,200.00, and the difference would exist nowhere. A settlement that fails to open leaves no row and no audit record. A withdrawal whose adjustment cannot be cancelled leaves both standing, rather than a cancelled settlement whose difference stays in the balance for ever. A settlement receipt whose ledger credit fails leaves no receipt, no entry, no account movement and no status change; a withdrawal whose reversal fails leaves the receipt active and the money in the account, because a withdrawal without its reversal is cash in the books nobody received. An adjustment that cannot be audited is not written at all. A channel sale that fails to audit allocates no milk and owes nothing, and a correction that fails leaves the quantity, the amount and the reading exactly as they were |
| `PermissionEnforcementTest.php` | 9 | **Every permission in the catalogue is enforced somewhere** in `app/`, `routes/` or `resources/views/` — directly, through a `PermissionCatalog` constant, or through the channel-family suffix `BuyerPermissions` composes at runtime. The exceptions are listed explicitly with the phase that owns them, and the test fails in **both** directions: an identifier that starts being checked must leave the waiting list, and one that stops being checked fails outright rather than quietly granting nothing. The seven Phase 5 permissions are additionally asserted by name |

---

### The three money scenarios worth stating in full

These are the cases where an error would be both invisible and expensive.

**A ₹90,000 expense split three ways.** ₹40,000 from Partner A, ₹30,000 from
Partner B, ₹20,000 from the bank. The assertions: **one** `expenses` row of
₹90,000, three `funding_allocations`, **one** ledger entry debiting the bank
₹20,000 and nothing else, the bank balance down by exactly ₹20,000, ₹40,000 and
₹30,000 on the two partner ledgers, and an expense total that reports ₹90,000
rather than ₹180,000. The invariant underneath: allocations are not expenses, and
partner money never moves a business account.

**A ₹50,000 partner contribution.** Saving it credits the bank once, and the same
₹50,000 then appears in the partner's ledger, in the cashbook, and in the derived
account balance — from one record, entered once. Replaying the ledger effect
cannot credit it twice. Cancelling reverses the credit while leaving both the
contribution and its reversal visible.

**Cancelling a split expense.** The expense and all three allocations survive with
`status = cancelled`; exactly one reversal is posted, against the bank share only;
the bank balance returns to its prior figure to the paisa; the expense leaves
active totals and both partner ledgers; cancelling again adds no second reversal.
A partner-only expense, cancelled, posts no reversal at all, because it posted no
debit in the first place.

### The three milk scenarios worth stating in full

These are the cases where an error would be silent and would corrupt a day's
records rather than announce itself.

**A shift nobody entered, beside a shift entered as zero.** Both have 0.000 litres
of production. The first reports `productionEntered = false`, shows "Production not
entered", and refuses any allocation with a message telling the user to record
production. The second reports `true`, shows a real 0.000, and refuses allocation
with a message about available milk instead. Nothing anywhere infers the state from
the quantity, and the assertion that pins it compares the two side by side: identical
figures, opposite flags.

**Ten litres allocated in uneven thirds.** 3.333 + 3.333 + 3.334 against 10.000
leaves exactly 0.000 — and the shift then refuses a further 0.001. The same sum in
binary floating point leaves −4.4409e-16, which would have made a balanced day look
over-allocated. Every litre figure is a decimal string through bcmath for this
reason.

**An adjustment the allocations came to depend on.** Production 10.000, an
authorised increase of 5.000, then 14.000 litres of usage — legitimate, because the
milk was there. Cancelling that increase is **refused**, because it would leave
14.000 allocated against 10.000 available; the refusal names the figure and says the
usage has to go first. Cancel the usage and the withdrawal succeeds. No cancellation
audit entry is written for the refused attempt, so the log never claims something was
withdrawn that was not.

### Required by later phases

Customers — **delivered in Phase 4 Pass 1**, listed here because the list is the
contract each phase inherits:

- Customer registration
- **Reminder quantities never prefill the daily entry fields** — enforced
  structurally, since no method returns a reminder as a quantity (D39)
- Pause behaviour suppresses sales
- Morning and evening sale creation
- Removing or changing an existing quantity cancels or updates correctly
- A sale snapshots the rate the resolver returned, and later price changes do not
  alter it
- **Every sale passes `MilkAvailability::assertCanAllocate()`**, so the Phase 3 rule
  governs deliveries without being reimplemented
- The real `MilkSalesAllocator` reports sales by channel, and the reconciliation
  screen shows real figures instead of its "sales are not recorded yet" notice
- Buyer payment and outstanding derivation, for the direct-customer channel

Customers — **delivered in Phase 4 Pass 2**:

- Daily grid bulk save, as a JSON body (D5)
- **Grid idempotency through the screen** — saving the same day twice changes nothing
  the second time, asserted on the audit count as well as the row count
- Copy Previous Day fills the form and writes nothing until saved
- Whole-day atomicity, the two-phase apply order (D42) and the preserved rate
  snapshot (D41)

Buyers — **delivered in Phase 5**:

- Mandali manual rate, fat and SNF as reference readings only, collection slips
- Vendor sales at the configured rate, with a permissioned override and a reason
- Sales to any administrator-created channel, with a typed rate
- Monthly settlement: frozen snapshots, the difference as one adjustment, the overlap
  rule and the closed period
- Payment and outstanding derivation for every channel, through the **same** Phase 4
  services rather than duplicated ones — asserted by a test that the same arithmetic
  answers for all four channels and that no per-channel service exists
- An on-screen period statement whose every figure is hand-calculated in the test

Animals

- Purchase is atomic across animal, event, expense, allocations and ledgers
- Split funding on purchase, through the Phase 2 funding action unchanged
- Lifecycle transitions
- Current lactating count
- Sold or dead animal restrictions

Employees

- Employee creation
- **Private document authorisation** — an unauthorised user cannot download
- Payroll calculation
- Partial and full salary payment
- Employee loan balance, and the rule that only a disbursement carries funding
- Payroll loan deduction
- Recoverable employee charge

System

- Export authorisation
- Report filter smoke tests
- Service worker registration and offline behaviour

---

## Conventions

- One behaviour per test, named for the behaviour rather than the method.
- Assert the database state that matters, not just the response code.
- Test the server boundary: send the request, check what the server allows.
- For authorisation, always test the **negative** case — a user without the
  permission must be refused.
- Prefer factories over fixtures; prefer explicit data over randomness where the
  value is part of the assertion.
- **Compare money with `expectMoney()`, never with `==` on floats.** The helper
  in `tests/Pest.php` compares decimal strings through `bccomp`. A float
  comparison would let a test pass while the books were wrong, which is the exact
  failure the `DECIMAL` columns exist to prevent.
- When a new test fails, decide which of three things is true before changing
  anything: the test's assumption is wrong, the implementation contradicts the
  specification, or the implementation has a real defect. Production code is
  never adjusted to make an incorrect test pass.
- Query-count guards assert exact equality between a page showing one related
  record and the same page showing many, and warm the per-request caches first.
  Comparing against an *empty* page measures Eloquent skipping eager loads on an
  empty collection, not a query per row.
