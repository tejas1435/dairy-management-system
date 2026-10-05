# Business Rules

The rules the application must enforce. Every one of these is enforced
server-side regardless of what the browser does.

**Status:** derived from the master specification in Phase 0. Each rule is
implemented and tested in the phase that owns its module.

Enforced and covered by tests as of **Phase 4 Pass 2**: **1** (milk reconciliation,
now including the sales half), **2** (Customer Daily Entry), **3** (price
resolution), **4** (customer pauses),
**5** (sales versus payments), **6** (buyer outstanding, for the direct-customer
channel), **7** (financial accounts), **8** (expense funding), **9** (partner
funding), **10** (milk money is business money — by construction, since no separate
wallet exists), **17** (cancellation), and the parts of **18** the built surfaces
reach.

Rule 2 includes the two requirements about the screen itself, which are the ones
MASTER_SPEC section 19 is most insistent about: the quantity fields start empty
whatever the reminder says, and Copy Previous Day runs only on an explicit click and
writes nothing until the day is saved.

**Phase 5** added the Mandali, vendor and other-channel rules as sections
**11a–11d** below, and filled in the receivable-adjustments term that rule 6 carried
as a named zero. Rules 12–16 belong to Phases 6 and 7 and are not yet implemented;
nothing in the application pretends otherwise.

---

## 1. Milk reconciliation

For each farm, date, shift and milk type:

```
available  = production + authorised adjustments
allocated  = sales + internal usage
remaining  = available − allocated
```

- If no production row exists, the screen shows **"Production not entered"**.
  Missing production is never treated as zero.
- Internal usage types: calf feeding, home use, sample, wastage, other.
- Allocation beyond available milk is **blocked** for normal users.
- An authorised user may exceed it only through an explicit milk adjustment
  carrying a reason, which is audited.
- The system never silently creates a balancing record.
- Cancelled sales, usage and adjustments stop counting immediately.

### 1a. The production record and its identity

**One record per farm, date and shift**, holding both the cow and the buffalo
quantity as columns. The database enforces that identity with a unique key on
(`farm_id`, `production_date`, `shift`); saving the same shift twice updates one
row and cannot produce a second.

There is no `milk_type` column on production. The reason is the rule below: with a
row per milk type, "has this shift been recorded?" stops being a single fact, and a
shift with cow entered but buffalo not yet entered becomes indistinguishable from
one where buffalo genuinely produced nothing.

The farm is resolved automatically from the primary farm. Operational screens never
ask which farm, and a farm id arriving in a request is not read.

### 1b. Missing production is not zero production

The hard rule of the module. Two situations share a quantity of 0.000 and mean
different things:

| Situation | Means |
| --------- | ----- |
| No production row for the shift | Nobody has recorded it. Nothing may be allocated against it. |
| A row exists stating 0.000 | Somebody recorded the shift and it produced nothing. A complete answer. |

The distinction is carried explicitly — by the presence of the row, by
`productionEntered` on the reconciliation result, by a different refusal message
when allocation is attempted, and by an em-dash rather than a figure on screen. It
is **never inferred from the quantity being zero**.

Entering zero is legitimate, including zero for one milk type and a positive figure
for the other. Whenever the shift row exists, both milk types report that production
was entered.

### 1c. Available milk and authorised adjustments

An adjustment is the only way available milk can differ from recorded production. It
carries an explicit **direction** — increase or decrease — and an always-positive
quantity; the sign exists only in the arithmetic. A **reason is mandatory**, at the
database level as well as in the form, and every adjustment is audited with its
direction, quantity and reason.

Increases and decreases are shown separately as well as netted, so an increase and
a decrease that cancel out still read as two recorded exceptions.

### 1d. No ordinary workflow may leave a negative remainder

Four routes are closed:

| Attempt | Outcome |
| ------- | ------- |
| Usage exceeding available milk | refused, to the millilitre |
| Usage against a shift with no production entered | refused, with a message saying to enter production first |
| A decrease adjustment below what is already allocated | refused |
| Cancelling an increase that allocations depend on | refused, naming what must be withdrawn first |

Availability is checked **inside the writing transaction**, after a row lock, so two
concurrent writers cannot both consume the same remaining milk.

There is no "ignore availability" option anywhere. If more milk genuinely was
available, an authorised person records an adjustment with a reason, and the
allocation then fits. **Nothing creates that adjustment automatically.**

A negative remainder is still displayed, in red, if data from outside these
workflows produces one — hiding it would conceal the problem rather than prevent it.

### 1e. Internal usage is not a sale

Usage records milk consumed without a buyer. It is counted as allocated because the
milk is equally gone, but no money, buyer or receivable is involved: recording calf
feeding as a zero-rate sale would invent a buyer and a line in the revenue reports.

### 1f. Correction and history

| Record | Corrected by |
| ------ | ------------ |
| Production | **Updated in place.** One shift has one production figure, so a correction replaces it; the audit log keeps the old and new values. `created_by` survives, `updated_by` changes. No delete route exists. |
| Internal usage | **Cancelled** with a reason, then re-entered. No update route. |
| Adjustment | **Cancelled** with a reason. No update route. |

A cancelled record stays in the table and stops counting towards allocation, so the
milk returns to the pool with no compensating record written. Cancelling twice is
refused and records nothing further.

### 1g. Exact three-decimal arithmetic

Quantities are `DECIMAL(10,3)` and every calculation runs through bcmath on decimal
strings. 10.000 litres allocated as 3.333 + 3.333 + 3.334 leaves exactly 0.000. A
fourth decimal place is rejected at input rather than rounded into the column, and a
remainder of −0.001 stays −0.001 all the way to the validation decision.

### 1h. Sales

Allocated milk includes sales. Phase 3 had no sale workflow, so reconciliation obtained
the sales half through a replaceable allocator that reported a true zero **and** that
no sales subsystem existed — the screen said so rather than displaying channel rows of
0.000 L that would read as "nothing was sold today".

**Phase 4 bound the real allocator.** `milk_sales` exists, `RecordedMilkSales`
aggregates it by channel, and the screen now draws a row per channel where a zero is a
real zero. The engine was not changed. A sale is still never fabricated: the
development seed creates none, because a seeded sale would either need matching
production or would over-allocate milk the farm never recorded.

Every sale is checked against available milk **inside** its write transaction, using
the same `MilkAvailability` rule that governs internal usage. Sales do not get their own
copy of the rule.

## 2. Customer Daily Entry

**Status:** built in Phase 4 Pass 2 — the grid, Copy Previous Day, the JSON bulk save
and the daily totals.

- All active direct customers appear as rows for the selected date. The customer
  is never selected per day.
- A row is one customer **and one milk type**, so a customer taking cow and buffalo
  has two rows. One row for both would mean one rate column for two rates.
- The grid is never paginated. It loads the whole round in a fixed number of queries
  regardless of customer count, which a query-count test holds to.
- Morning and evening are editable quantity fields.
- **Reminder quantities are reminders, not defaults.** They are displayed as
  helper text and must never prefill the input fields. This is a non-negotiable
  product requirement, and Phase 4 made it structural rather than a matter of
  discipline: `CustomerPreference` exposes no method that returns a quantity, so there
  is nothing for a grid to call by mistake ([DECISIONS.md](DECISIONS.md) D39).
- An empty field, `0` and `0.000` all mean **no delivery** and are the same input.
  None of them creates a zero-quantity sale, so no zero-rupee receivable can exist.
- "Copy Previous Day" runs only when the user clicks it. Nothing is ever
  auto-copied. It reads the **previous calendar day's active grid sales**, fills the
  form in the browser and **writes nothing** — no sale, no audit record, no
  receivable — until the operator presses Save Day. It copies quantities only:
  yesterday's rate and amount are never carried across, because a copied delivery is
  a new sale on the selected date and is priced by that date. Cancelled sales are not
  copied, and no quantity is staged into a row the save would refuse (paused,
  archived, not yet started, no active preference, or unpriced).
- **The day saves as one JSON request** rather than as nested form fields. A
  two-hundred-customer day exceeds PHP's `max_input_vars` and would be truncated
  silently, reporting success over a half-saved day
  ([DECISIONS.md](DECISIONS.md) D5).
- The day saves in one transaction, **all or nothing**: if any cell fails, every
  other change in that request is rolled back with it, audit records included.
  Partial-success saving is deliberately not implemented.
- Changed cells are applied **releases first, claims second** — cancellations and
  decreases before creations and increases — so moving milk between two customers in
  one save is judged on the day's final allocation rather than on the order the rows
  arrived in ([DECISIONS.md](DECISIONS.md) D42).
- Only cells that actually changed are written. Re-saving an unchanged day writes
  nothing and records no audit entry.
- **The rate of an existing delivery is never re-resolved by a quantity edit.** The
  row keeps the rate it was recorded at; only a new row is priced from the selected
  date ([DECISIONS.md](DECISIONS.md) D41). A row holds **two** sales, so its morning
  and evening can legitimately carry different rates — a customer rate agreed late
  and dated over a day already delivered on does exactly that. The rate is therefore
  held, shown and applied **per shift**, the row total sums each shift at its own
  rate, and the statement reports both rather than picking one
  ([DECISIONS.md](DECISIONS.md) D43). There is no manual rate field on the grid,
  and `milk.customer_delivery.override_rate` stays unused until an override workflow
  with its own audit behaviour is designed.
- A new delivery whose rate cannot be resolved is blocked and the cell reads "Price
  not configured". It never shows ₹0 as though that were a price. An existing
  delivery stays editable and cancellable even if its price rule is later withdrawn,
  because its own snapshot is enough to re-cost it — so one shift of a row can be
  correctable while the other cannot be created at all, and the grid closes them one
  cell at a time rather than writing off the whole row.
- Every create and every increase passes the same `MilkAvailability` check that
  governs internal usage, **inside** the write transaction. Unentered production is
  reported as unentered, not as zero. There is no "save anyway", and no adjustment is
  ever created automatically to make room.
- Live totals in the browser are a **preview**, computed in integer thousandths of a
  litre and integer paise so they cannot drift. The server recomputes every figure
  and its response is what the screen then shows.
- Filtering the grid by search, area or milk type hides rows visually and nothing
  more. A hidden row keeps its value and is submitted unchanged; filtering can never
  be mistaken for clearing.
- Saving the same day twice updates the existing sales rather than creating
  duplicates, guaranteed by a unique key on farm, customer, date, shift and milk type
  that applies **only** to grid-sourced sales. It is a generated column, because MySQL
  has no partial unique indexes and Phase 5 sales must stay unconstrained by it
  ([DECISIONS.md](DECISIONS.md) D38).
- Clearing a previously entered quantity cancels the corresponding
  grid-generated sale under the cancellation rules. A stale sale is never left
  behind.
- Paused customers stay on the grid, visually marked, with their inputs disabled, and
  the server refuses a delivery for them whatever a hand-written payload claims. The
  specification permits an authorised override; **none is implemented**, because it
  was not required and inventing one would be functionality nobody asked for.
- A pause created later does **not** reach back and cancel or re-price a delivery
  already recorded on a date it covers. The historical transaction stands. It simply
  cannot be edited through the grid until the pause is withdrawn, which is its own
  audited act.
- Quantities may be decimal to three places and may never be negative. A fourth
  decimal place is rejected rather than rounded into the column.

## 3. Price resolution

For a given sale date and milk type, in order:

1. An active customer-specific rate effective on that date.
2. Otherwise the business default rate effective on that date.
3. If neither exists, **the save is blocked** with a message telling the user to
   configure the price. A rate is never guessed or defaulted to zero.

Price changes are effective-dated: the current rule is closed and a new one
inserted. Rules are never overwritten.

**New periods open forward only.** A period must start strictly after the latest
existing one, and the open period is closed the day before the new one begins.
That makes overlap arithmetically impossible rather than merely checked for, and
it is what a row lock plus a unique index cannot do on their own. Back-dating a
price is therefore refused: it would silently change what every sale already
priced from that period should have cost. Only a period that has not yet started
may be deleted, and deleting it re-opens the period it closed.

Every sale stores the applied `unit_rate` as a snapshot, so changing today's
price never alters a past sale.

Mandali rates are entered manually in V1. Fat and SNF are recorded for reference
and reporting only; no automatic fat/SNF pricing formula exists.

## 4. Customer pauses

A customer may be paused between a start date and an optional end date with an
optional reason. During a pause, no sale is generated through the normal
workflow.

As built in Phase 4:

- A null end date is an **open-ended** pause, covering every date from the start date
  onwards. Both ends of a closed pause are inclusive.
- Overlapping active pauses are refused, so "is this customer paused on this date" has
  exactly one answer.
- A pause is cancelled, never deleted, and a cancelled pause covers nothing — so
  resuming deliveries does not rewrite history.
- A paused customer is **marked, not removed** from the day's list (MASTER_SPEC 18).
  Dropping the row would make a pause indistinguishable from a customer who left.
- `SaveCustomerDailySale` refuses a sale for a paused customer, so the rule holds even
  if a screen ever failed to disable the input.

## 5. Sales versus payments

Sales (revenue) and payments received (cash) are different figures and are
always displayed separately. Dashboards show both plus the difference. They are
never conflated.

## 6. Buyer outstanding

```
outstanding = sales + receivable adjustments − payments received
```

Always derived from transactions. There is no manually editable outstanding
field. Normal payment entry warns or blocks on accidental overpayment; a
credit balance requires an explicit authorised workflow.

As built in Phase 4, for the direct-customer channel:

- Active sales minus active payments. Cancelled rows on either side stop counting.
- **The receivable-adjustments term is present and zero.** `buyer_balance_adjustments`
  is Phase 5, and no adjustment record was invented to complete the formula. The term
  is named in the breakdown so the arithmetic is whole and the screen does not change
  when Phase 5 fills it.
- Paying **exactly** the outstanding is allowed. One paisa more is refused, and any
  payment against a zero outstanding is refused — so a credit balance cannot arise
  through the ordinary receipt screen. The check runs inside the transaction after a
  locking read, so two concurrent receipts cannot both pass it.
- Cancelling a payment reverses its ledger credit rather than deleting it, and restores
  the outstanding to the paisa. A payment can be cancelled once.

## 7. Financial accounts

```
balance = opening balance + credits − debits
```

Ledger entries are written by domain services, never edited by hand, and always
carry a reference to the record that caused them. No untraceable editable
balance exists.

Payments received into a cash or bank account generate ledger entries
automatically.

**A posting happens at most once.** Each entry carries a deterministic
idempotency key derived from the operation that caused it, with a unique index
behind it, so a double-clicked Save, a browser retry or a re-run job cannot
double a balance. A repeat is recognised and returns the existing entry rather
than failing.

**A wrong posting is reversed, not deleted or edited.** The reversal is an
opposite-direction entry pointing back at the original; both remain, so the
account explains its own corrections. An entry can be reversed at most once, and
a reversal cannot itself be reversed.

The cashbook paginates. Each page after the first opens with a **balance brought
forward** — the period's opening plus the net movement of the rows on earlier
pages — because a page that restarted from the period's opening would show
balances that are wrong while looking entirely plausible.

## 8. Expense funding

- One real-world expense is exactly **one** expense row.
- It may be funded by any number of sources: partners, business accounts, or a
  mix.
- Funding allocations must sum exactly to the amount being paid, validated
  inside the transaction.
- Allocations are **never** counted as additional expenses. Dashboards and
  reports see one expense.
- A business account is debited only by its own share.
- **A partner-funded share moves no business account at all.** The money never
  passed through one. Debiting an account for a partner's share would invent cash
  the business never held.
- The same source may not appear twice in one split. It reads as two payments
  where the user meant one, and is almost always a mis-click.
- Every source is re-checked server-side against the payable's business, and an
  inactive source is refused. An id arriving in a request proves nothing.

Example: a ₹10,000 feed expense funded ₹7,000 by Partner A and ₹3,000 by
business cash produces one expense, two allocations, a ₹3,000 cash debit and
₹7,000 on Partner A's ledger.

Once posted, an expense's amount, date and funding split cannot be edited —
they determined ledger entries that already exist. Correcting them means
cancelling with a reason and re-entering, so both versions stay in the record.
Category, description, payee and notes remain editable, because none of them
moves money.

## 9. Partner funding

Partners contribute money into business accounts or pay business expenses
directly. Both appear on the partner ledger, derived from contributions and
funding allocations. Nothing is entered twice.

The partner ledger is **derived on read**, not stored. There is no partner ledger
table: it would be a second copy of money the system already records, free to
drift from it. Cancelled contributions and cancelled expenses therefore leave the
ledger automatically, because the derivation filters on status rather than a sync
process remembering to remove a line.

A contribution credits the destination account when saved, and cancelling it
reverses that credit.

V1 has no ownership percentages, no profit sharing and no partner loan
contracts. The number of partners is unlimited.

## 10. Money earned from milk

Money earned from milk is business cash or bank money. Milk payments received
credit a business account; expenses debit it. There is no separate "milk
earnings wallet".

## 11. Animal lifecycle

Three independent state dimensions:

| Dimension | Values |
| --------- | ------ |
| Life | active, sold, dead |
| Lactation | lactating, dry, not started |
| Reproductive | unknown, open, pregnant |

They are independent because reality is: a pregnant cow can still be lactating.

Events drive state:

| Event | Effect |
| ----- | ------ |
| Lactation Started | lactation → lactating |
| Lactation Stopped | lactation → dry |
| Pregnancy Confirmed | reproductive → pregnant |
| Calving | reproductive → open; user may mark lactation started in the same guided flow |
| Sold | life → sold |
| Died | life → dead |

History is append-only and never deleted. A sold or dead animal cannot be marked
lactating without an explicit valid reactivation workflow.


## 11a. Mandali deliveries and settlement (Phase 5)

**Status:** built in Phase 5.

### The delivery

- A Mandali is a **buyer in the Mandali channel** (D26). There is no `mandalis` table,
  and a vendor and a custom-channel buyer are the same kind of record in their own
  channels.
- Fields: date, shift, milk type, quantity, fat %, optional SNF %, a manually entered
  rate, the calculated amount, notes and an optional collection slip.
- **The rate is typed in every time.** It is not a `PriceResolver` result and it is
  not an override — it is the workflow (MASTER_SPEC section 22). It must be greater
  than zero, and the amount is `quantity × rate`, computed on the server.
- **Fat and SNF are reference data and nothing reads them.** V1 has no fat/SNF pricing
  formula, no rate bands and no quality-based calculation. Changing a reading on a
  recorded delivery provably does not change its amount
  ([DECISIONS.md](DECISIONS.md) D44). Fat is required for a Mandali collection; SNF is
  optional. Both are accepted from 0.00 to 15.00.
- The farm comes from `BusinessContext` and the sales channel from the buyer. A posted
  `farm_id`, `sales_channel_id`, `source` or `amount` is never read.
- Several deliveries to one Mandali in one shift are allowed. The one-row-per-shift
  identity belongs to the customer daily grid, which re-saves a whole day; two
  collection trips in one morning are a real thing (D38).
- Every delivery allocates real milk through the same `MilkAvailability` rule as
  everything else, inside the write transaction. Unentered production is reported as
  unentered rather than as zero. There is no "save anyway" and no automatic
  adjustment.

### The collection slip

Optional, one per delivery. Stored on the **private** disk with a randomised filename
and the original name kept only as metadata; validated on extension *and* media type,
at most 5 MB. There is no public URL — the only way to it is a route that checks the
same permission as viewing the delivery. Replacing one deletes the file it replaced.

### The settlement

- A settlement belongs to a Mandali and covers an inclusive period.
- **A draft has no accounting effect.** It changes no balance and creates no
  adjustment; its displayed figures are live, which is safe precisely because nothing
  depends on them yet.
- **Finalization freezes the figures.** The milk quantity and the expected amount are
  computed from the period's active Mandali deliveries **at the rates those deliveries
  were recorded at**, and then stored. They are never recomputed afterwards.
- `difference = statement amount − expected amount`. A non-zero difference creates
  **exactly one** receivable adjustment. No statement means no difference and no
  adjustment, and the amount due is the system figure. A statement equal to the
  expected amount creates no adjustment either.
- **No historical milk rate is ever rewritten.** That is the specification's explicit
  instruction and the reason adjustments exist.
- Finalizing twice is refused, and the database independently refuses a second active
  adjustment for one settlement.
- **Periods may not overlap** another non-cancelled settlement for the same Mandali,
  so the same milk cannot be settled twice. Cancelled ones are ignored.
- **A finalized period is closed**: a delivery inside it cannot be corrected,
  withdrawn or added until the settlement is cancelled. The refusal names the period.
- Cancelling a settlement withdraws its adjustment with it, but **never reverses a
  receipt** — a payment records that cash arrived, and it has its own withdrawal
  workflow. A settlement with active linked payments cannot be cancelled.
- `Draft → Finalized → Cancelled` are chosen; **Partially Paid and Paid are derived**
  from the sum of active receipts and cannot be set. Cancelling a receipt moves the
  status back on its own. `Cancelled` is terminal (D46).

## 11b. Vendor and other-channel sales (Phase 5)

- A vendor sale resolves the rate for the vendor, milk type and **sale date**, through
  the same `PriceResolver` as everything else: a buyer-specific rate wins over the
  business default.
- A typed rate equal to the resolved one is not an override. A **different** one is,
  and needs `milk.sale.override_rate` plus a stated reason — as does typing a rate
  where none is configured, because "no rate configured" is also what a mistyped buyer
  looks like. The applied rate is stored as the snapshot and the resolved one beside
  it as provenance (D45).
- Correcting a quantity **keeps the stored rate**. Changing the rate on a recorded
  sale is a separate, permissioned act with its own reason (D41 applied to every
  channel).
- The generic form serves **only** administrator-created channels. Its rate is typed
  in, because a custom channel has no price rules, and the server refuses a buyer from
  any of the three system channels: each of those has a workflow with rules the
  generic form does not apply, and a filtered select list is not a control.
- All four channels feed one reconciliation engine and appear in their own bucket.
  No sale is counted twice.

## 11c. Buyer outstanding and receivable adjustments (Phase 5)

    outstanding = active sales + active receivable adjustments − active payments

- **One engine for every channel.** There is no `MandaliOutstandingService`: a Mandali,
  a vendor, a direct customer and a hotel all owe money the same way, and a second copy
  of the arithmetic would be a second copy to keep correct. Channel differences are
  presentation and permissions.
- Phase 4 carried the adjustments term as a named zero. Phase 5 filled it in, and the
  screens that already displayed the term did not change.
- An adjustment is a **direction plus a positive amount**, never signed money. It
  changes what is owed and nothing else: no litre, no reconciliation figure, no
  financial account. Nothing creates one automatically to make a total come out even.
- A reason is required at the database level, not only in the form.
- **A decrease may not leave a buyer in credit.** A credit balance is permitted only
  through an explicit authorised workflow, and none exists — so exactly the outstanding
  is allowed and a paisa more is refused. An increase is never refused (D47).
- Cancelling an adjustment restores the balance by itself. No compensating correction
  is written.
- **There is no screen for adjusting a balance by hand.** An adjustment exists to
  account for a reconciled difference — a Mandali statement that disagrees with the
  system figure — and settlement finalization is the only thing that creates one. A
  general "change this buyer's balance" form would be an unauditable way to make any
  figure in the application say anything, which is precisely what a receivable has to
  be defended against. Asserted structurally: a test enumerates every route whose path
  contains `adjust` and allows only the two Phase 3 **milk** adjustment routes.

## 11d. Buyer payments across channels (Phase 5)

- A Mandali, vendor or custom-channel receipt is the **same** `BuyerPayment`, through
  the same action, as a direct customer's. There is no `mandali_payments` or
  `vendor_payments` table: a second cash-transaction table would be a second place for
  the ledger credit, the overpayment rule and the reversal to live, and they would
  diverge.
- The permission comes from the buyer's channel family —
  `mandali.payment.create`, `vendor.payment.create`, or `customer.payment.create` for a
  custom channel, which maps to that family under D26.
- A payment may not exceed the buyer's outstanding, and when it is linked to a
  settlement it also may not exceed what **that settlement** still owes. Without the
  second cap, a Mandali with two finalized months could have one recorded as overpaid
  while the overall balance still looked right.
- A payment against a draft settlement is refused: a draft has agreed no amount, so a
  receipt against it would be money against nothing.
- Each receipt credits its chosen account exactly once, through the ledger, under a
  deterministic idempotency key. A milk sale, by contrast, posts **nothing** to a
  financial account: a delivery is a receivable, and the cash appears when the payment
  does.
- Cancelling a receipt reverses the credit exactly once, restores the outstanding, and
  keeps the record.

## 11e. The buyer period statement (Phase 5)

The on-screen report MASTER_SPEC section 23 asks for, available for a Mandali and for
a vendor, at `/mandalis/{buyer}/statement` and `/vendors/{buyer}/statement` — and for a
custom-channel buyer at `/other-buyers/{buyer}/statement`.

- **It is a view, never a stored total.** Every figure comes from the same
  `BuyerTradeLedger` and `BuyerOutstandingService` the trade profile uses. There is no
  statement table and no report-only balance column: a second place to store a total is
  a second place for it to be wrong, and a report that disagreed with the profile would
  be impossible to argue with (D49).
- The summary carries the five figures the specification lists for the period: **milk
  quantity, expected sales amount, receivable adjustments, payments received** and the
  **outstanding balance**.
- The outstanding is the **whole** balance, not the period's movement. A month that
  happens to be paid in full must not read as "nothing is owed" while last month's
  balance sits above it, so the period figures and the balance are labelled
  differently.
- The transaction detail shows, per row: date, shift, milk type, quantity, fat, SNF,
  the **rate snapshot** the sale was recorded at, the amount, and — for the other two
  kinds of row — the settlement adjustment or the receipt, each with the running
  balance.
- **Opening and closing balances are always shown**, including for a period with no
  transactions in it. A quiet month on an account that is still owed money must not
  render as an empty account. The opening balance is what was owed the day before the
  period, so a filtered page reconciles with the one before it.
- Rows are ordered date, then kind (sale, then adjustment, then receipt), then id, so a
  day reads as "what was delivered, what was corrected, what was received" and the
  running balance never dips before the charge that caused it.
- A **settlement** covering any part of the period is listed beside the summary with
  its own figures — expected, statement, difference, due, remaining, status — and never
  merged into them. A settlement freezes what was agreed for its own period; the
  summary follows the deliveries themselves.
- Cancelled sales, adjustments and receipts are excluded, exactly as they are from the
  balance.
- **Period selection is forgiving.** `?month=YYYY-MM` is the normal way in and
  `from`/`to` override it; an unparseable month shows the current month rather than a
  validation error, because these are navigation links. Month `13` is treated as
  unparseable rather than rolled forward into the next year.
- Authorisation is the buyer's own channel view permission — the same as the profile,
  because it is the same data read differently.
- **Export is Phase 9.** The statement is on-screen only in Phase 5.

Every figure on this screen is asserted against a hand calculation in
`tests/Feature/Buyers/MandaliStatementTest.php`, not against the service that produced
it.

## 12. Current milking count

```
life_status = active AND lactation_status = lactating
```

Sold and dead animals never appear. Pregnant and lactating counts may overlap,
which is expected and correct.

## 13. Animal purchase — entered once

Creating a purchased animal saves, in **one** transaction: the animal, a
Purchased event, an Animal Purchase expense, the funding allocations, the
business account debits, the partner ledger effects and the audit records.

The user never re-enters the purchase in Expenses. An existing or opening animal
can be added without generating a purchase expense when explicitly marked as
such.

## 14. Employee salary

- One payroll per employee, year and month.
- The base salary is snapshotted onto the payroll, so later salary changes never
  alter past payrolls.
- `net payable` is computed server-side. JavaScript calculations are for display
  only.
- Statuses: draft, finalized, partially paid, paid, cancelled.
- A payroll may have several payments, each funded by cash, bank, partners or a
  mix through funding allocations.
- Paid amount may not exceed net payable without an authorised correction.

## 15. Employee loans

Loan balance derives from transactions — disbursement, manual repayment, payroll
deduction, authorised adjustment — never from an editable field. A payroll
deduction may not exceed the applicable outstanding balance. Disbursements can
be funded from business accounts, partners, or both.

## 16. Recoverable employee expenses

An employee-related expense is treated as a company expense, an employee benefit
or recoverable from the employee. When recoverable, the expense is created
normally **and** an employee charge is raised, recovered later through payroll.
The item is entered once.

## 17. Cancellation

Financial and operational transactions are cancelled, not deleted, recording
`cancelled_at`, `cancelled_by` and a reason. A cancelled record stops affecting
balances, reports, milk allocation and dashboard totals, and remains auditable.

Cancellation and its ledger reversals happen in one transaction, and cancelling
twice has the effect of cancelling once. There is no un-cancel: a corrected
figure is entered as a new record, so the timeline reads as what happened rather
than as what the books were edited to say.

Master data with history is deactivated rather than deleted. A system master row
— a seeded payment method, expense category or sales channel — cannot be deleted
or have its machine identifier changed at all, because code resolves it by that
identifier; its label, active state and ordering stay editable.

## 18. Validation floor

Enforced server-side in every case:

- No negative milk quantities or expense amounts.
- Rates must be positive.
- No zero-value financial records unless explicitly valid.
- Fat and SNF within configured sensible limits.
- Duplicate production for the same farm, date, shift and milk type is rejected.
- Duplicate daily-grid records for the same customer, date, shift and milk type
  are rejected.
- Distribution exceeding available milk is caught.
- Funding allocations must equal the required payment amount.
- An animal purchase payment split must equal the purchase amount when marked
  fully paid.
- Payments must not silently exceed outstanding.
- Uploaded documents must match permitted MIME types and size limits.
