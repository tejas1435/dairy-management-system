# Roles and Permissions

Authorisation uses granular permissions through Spatie Laravel Permission 8.
Roles are collections of permissions; both are editable at runtime. Permissions
are assigned to users through roles rather than directly.

**Status:** implemented in Phase 1. All **73** permissions and 7 roles are seeded
and covered by tests. Module permissions are enforced by their routes as each
module lands; the identifiers already exist so later phases only attach checks.

Enforced on a real surface as of **Phase 5**: `expense.*`, `partner.*`,
`finance.account.manage`, `finance.cashbook.view`, `settings.manage`,
`audit.view`, the three buyer families (`mandali.*`, `vendor.*`, `customer.*`)
through the channel mapping below, and the milk permissions
`milk.production.view`, `milk.production.create`, `milk.reconciliation.view`,
`milk.usage.*`, `milk.adjustment.*` and `milk.customer_delivery.view`,
`milk.customer_delivery.create`, `milk.customer_delivery.update`, plus the
direct-customer surface:
`customer.view`, `customer.create`, `customer.update`, `customer.archive`,
`customer.payment.create` and `customer.payment.cancel`; and, as of Phase 5, the
`mandali.*` and `vendor.*` families in full, `mandali.settlement.manage`, and the
`milk.sale.*` family including `milk.sale.override_rate`. The remainder are seeded
identifiers awaiting the module that will check them.

The catalogue grew from 65 to 69 in Phase 3, to 70 in Phase 4 and to 73 in Phase 5 —
see the "Permissions added in" sections below.

The catalogue lives in `app/Support/PermissionCatalog.php` and the starting
matrix in `app/Support/RoleCatalog.php`. See [DECISIONS.md](DECISIONS.md) D15 for
why the identifiers are code while the assignments are data.

---

## Enforcement

Server-side, always, in four layers:

| Layer | Purpose |
| ----- | ------- |
| Route middleware | coarse access to a module |
| Policy | per-record decisions |
| Form Request `authorize()` | write operations |
| Blade `@can` | **display only** |

Hiding a menu item is never the protection. A user who knows a URL or an ID must
still be refused by the server. Possessing an ID grants nothing.

### Permissions only — roles are bundles, not shortcuts

**Every authorisation decision resolves through a permission.** There is no
`Gate::before`, no `hasRole()` and no role-name comparison anywhere in the
authorisation path.

A system role is a **default bundle of permissions**, not an authorisation
shortcut. Super Admin reaches everything because the seeder grants it every
permission in the catalogue, not because its name is recognised. Owner, Partner, Manager,
Accountant, Data Operator and Viewer are the same kind of object: starting
bundles an administrator may re-cut at any time.

`RoleCatalog` is still referenced in a few places, but never to decide access:

| Use | Authorisation decision? |
| --- | ----------------------- |
| Seeding the starting bundles | no — it writes grants |
| Re-syncing Super Admin to the catalogue | no — it writes grants |
| Refusing to rename or delete a seeded role | no — it protects a record |
| Locking the Super Admin permission editor | no — it protects a record |
| Assigning Super Admin in `dairy:create-admin` | no — it assigns a role |

Two tests hold this in place: one strips Super Admin's permissions directly and
asserts the account loses access, and one scans every file under `app/` and
fails the build if `Gate::before`, `hasRole()`, `hasAnyRole()` or
`hasAllRoles()` reappears. See [DECISIONS.md](DECISIONS.md) D19.

One consequence worth knowing: a permission added by a later phase grants nothing
to anyone, including Super Admin, until the seeder runs and adds it to the role.
Every phase ends by running seeders, so this does not arise in practice.

---

## Default roles

Editable after seeding. The list below is the starting allocation, not a
hard-coded hierarchy.

| Role | Intent |
| ---- | ------ |
| Super Admin | Everything, including roles, permissions and settings |
| Owner | Full business visibility and operations; not user administration |
| Partner | Own ledger, financial visibility, limited operations |
| Manager | Day-to-day milk, animal and employee operations |
| Accountant | Finance, payments, expenses, settlements, reports |
| Data Operator | Daily entry: production, customer grid, deliveries |
| Viewer | Read-only |

---

## Permission catalogue

### Dashboard
`dashboard.view`

### Milk

| Permission | Grants |
| ---------- | ------ |
| `milk.production.view` | See production records |
| `milk.production.create` | Enter production |
| `milk.production.update` | Correct production |
| `milk.production.delete` | Seeded, but **checked by nothing** — see below |
| `milk.customer_delivery.view` | Open the Customer Daily Entry grid and read the day |
| `milk.customer_delivery.create` | Record a delivery where there was none |
| `milk.customer_delivery.update` | Change or remove a delivery already recorded |
| `milk.customer_delivery.override_rate` | Depart from the resolved rate — **seeded but checked by nothing**, see below |
| `milk.sale.view` / `.create` / `.update` / `.cancel` | Channel sales |
| `milk.reconciliation.view` | See the reconciliation screen |
| `milk.usage.view` | See internal usage and its history |
| `milk.usage.create` | Record internal usage |
| `milk.usage.cancel` | Withdraw a recorded usage, with a reason |
| `milk.adjustment.create` | Authorise milk beyond recorded production, and read the adjustment history |
| `milk.adjustment.cancel` | Withdraw an authorised adjustment, with a reason |

`milk.adjustment.create` and `milk.customer_delivery.override_rate` are the two
permissions that let a user depart from the recorded truth. Both are always
audited and both require a reason.

#### How the delivery permissions divide a single save

Phase 4 Pass 2 enforces the first three on the Customer Daily Entry grid. The route
itself is gated only by `view`, because one Save Day can contain both a brand-new
delivery and a correction to an existing one, and those are different acts of trust —
an operator entrusted with this morning's round is not automatically entrusted with
rewriting last week.

So the real check is made per operation, over the whole request, before anything is
written:

| What the cell does | Permission |
| ------------------ | ---------- |
| A quantity where there was none | `milk.customer_delivery.create` |
| A different quantity on an existing delivery | `milk.customer_delivery.update` |
| Clearing an existing delivery | `milk.customer_delivery.update` — removing a delivery is a correction to the day, not a creation |
| Re-entering after a removal | `milk.customer_delivery.create` — there is no active delivery to correct |
| An unchanged cell | none; nothing is written |

A save the actor is only half entitled to make is refused outright, and the whole
request rolls back. A day re-submitted with nothing changed needs no write permission
at all.

#### `milk.customer_delivery.override_rate` is seeded and checked by nothing

The grid shows the rate read-only: a new delivery takes the rate resolved for the
selected date, and an existing one keeps the rate it was recorded at
([DECISIONS.md](DECISIONS.md) D41). There is no manual rate field.

The identifier stays in the catalogue because MASTER_SPEC lists it, and it is left
unused deliberately. Re-pricing a recorded sale needs its own workflow, its own reason
and its own audit behaviour; building a rate box into the daily grid to give a seeded
permission something to gate would be letting the catalogue dictate the product — the
same reasoning applied to `milk.production.delete` in D33.

### Permissions added in Phase 3

The specification requires internal milk usage (section 15) but its original
catalogue had no permission for it. Borrowing `milk.production.create` would have
silently redefined that permission — everyone who may record production would also
be able to record wastage, with no way to separate the two afterwards. Four
identifiers were added instead, taking the catalogue from 65 to 69:

| Permission | Grants |
| ---------- | ------ |
| `milk.usage.view` | See the internal usage screen and its history |
| `milk.usage.create` | Record internal usage |
| `milk.usage.cancel` | Withdraw a recorded usage, with a reason |
| `milk.adjustment.cancel` | Withdraw an authorised adjustment, with a reason |

There is deliberately **no `milk.usage.update`**: usage is corrected by cancelling
it and entering the right figure, so an update permission would gate a route that
does not exist.

`milk.adjustment.cancel` is separate from `milk.adjustment.create` because granting
milk and withdrawing milk that has since been distributed are different decisions
with different consequences. Reading the adjustment list is gated by
`milk.adjustment.create` rather than a separate view permission: the list is the
record of exceptions somebody has claimed, and there is no audience for it that
should not also be able to record one. The read-only view of that history is the
audit log, behind `audit.view`.

See [DECISIONS.md](DECISIONS.md) D34.

### Permissions added in Phase 4

Recording money received and unrecording it are different acts of trust. Reusing
`customer.payment.create` for both would mean anyone who can take a receipt can also
make one disappear, and no role could be given the first without the second. One
identifier was added, taking the catalogue from 69 to 70:

| Permission | Grants |
| ---------- | ------ |
| `customer.payment.cancel` | Withdraw a recorded customer payment, with a reason, reversing its ledger credit |

It is granted to Super Admin, which holds the whole catalogue, and to Owner, which is
defined as a diff against it — and to no other seeded role. See
[DECISIONS.md](DECISIONS.md) D40.

`customer.archive` already existed in the catalogue and now has a real check behind
it: `BuyerPolicy::archive()` routes a direct customer to `customer.archive` and any
other buyer to its own channel's update permission. There is no customer delete
permission, because there is no delete route.

### Permissions added in Phase 5

Three identifiers, taking the catalogue from 70 to 73. Each exists because an existing
permission would otherwise have had to mean two different things.

| Permission | Grants |
| ---------- | ------ |
| `milk.sale.override_rate` | Depart from the rate the resolver returned on a vendor or generic sale, or change the rate on an already-recorded sale |
| `mandali.payment.cancel` | Withdraw a recorded Mandali receipt, reversing its ledger credit |
| `vendor.payment.cancel` | Withdraw a recorded vendor receipt, reversing its ledger credit |

All three are granted to **Super Admin** (which holds the whole catalogue) and to
**Owner** (defined as a diff against it), and to no other seeded role. That matches
`milk.adjustment.create` and `customer.payment.cancel` — the other permissions that
let a user depart from the recorded truth or unrecord money.

#### Why the rate override is not `milk.customer_delivery.override_rate`

That permission belongs to the Customer Daily Entry grid, where it stays seeded and
checked by nothing until somebody designs a re-pricing workflow with its own reason
and audit behaviour. Borrowing it for vendor sales would have made one identifier mean
two capabilities in two screens: a user granted it to agree a one-off vendor price
would silently also be able to re-price customer deliveries the day that screen
exists.

A **Mandali** rate needs no override permission at all when the delivery is created.
Typing it is the workflow, not a departure from anything (MASTER_SPEC section 22).
Changing it afterwards does need the permission, because by then there is something to
depart from. See [DECISIONS.md](DECISIONS.md) D45 for the full table of when it
applies.

#### Why the two payment cancellations were needed

Phase 4's `BuyerPolicy::cancelPayment()` had to refuse every non-customer channel
outright, because `customer.payment.cancel` was the only cancel permission in the
catalogue and passing on a permission that does not exist would have been worse. With
these two added, the policy asks the question the same way every other buyer
permission is asked — through the channel's family — and a custom channel maps to
`customer.*` under D26, which is the family its receipts were recorded under anyway.

#### Phase 5 permissions that already existed

`mandali.view`, `mandali.create`, `mandali.update`, `mandali.payment.create`,
`mandali.settlement.manage`, `vendor.view`, `vendor.create`, `vendor.update`,
`vendor.payment.create`, and the `milk.sale.*` family, were all seeded from Phase 1 and
are **enforced on a real surface** as of Phase 5. The Mandali and vendor lists check
their own channel's view permission rather than the generic `viewAny`, because the
lists are channel-scoped: a user holding only `mandali.view` must not be able to open
the vendor list.

`mandali.settlement.manage` additionally requires the subject buyer to be a Mandali.
That is not redundant with the permission — a settlement against a direct customer
would be a routing bug, and refusing it is cheaper than finding it in the data.

### `milk.production.delete` is seeded but checks nothing

The identifier appears in the catalogue because MASTER_SPEC section 10 lists it.
**Phase 3 exposes no destructive production endpoint**, and nothing in the
application checks the permission — there is no `DELETE` route anywhere under
`/milk`, asserted by a test.

Production is a statement of fact about a shift, of which there is exactly one, so
it is corrected by saving the shift again; the audit log keeps the previous figures.
A user holding this permission and nothing else can therefore do nothing with it.

Leaving a specified identifier unused is a small wart. The alternative — exposing a
delete route so the permission has a purpose — would be letting the catalogue
dictate the product. If a destructive path is ever wanted, the permission is
already there to gate it. See [DECISIONS.md](DECISIONS.md) D33.

### Customers and buyers

`customer.view`, `customer.create`, `customer.update`, `customer.archive`,
`customer.payment.create`, `customer.payment.cancel`

`mandali.view`, `mandali.create`, `mandali.update`, `mandali.payment.create`,
`mandali.settlement.manage`

`vendor.view`, `vendor.create`, `vendor.update`, `vendor.payment.create`

#### Which family governs a given buyer

Mandali, vendors and direct customers have separate permission families but share
one `buyers` table, so the family is resolved from the buyer's **sales channel**.
`App\Support\BuyerPermissions` is the single place that mapping exists, and
`BuyerPolicy` asks it:

| Channel slug | Permission family |
| ------------ | ----------------- |
| `mandali` | `mandali.*` |
| `vendor` | `vendor.*` |
| `direct_customer` | `customer.*` |
| any administrator-created channel | `customer.*` |

The fallback is deliberate in both directions. Without one, a custom channel's
buyers would be unreachable by every user; falling back to `mandali.*` instead
would hand out settlement rights that do not apply. A custom channel is
commercially a direct buyer and the generic sale entry already treats it as one,
so it borrows that family. See [DECISIONS.md](DECISIONS.md) D26.

That fallback now covers **receipts and the trade profile too**, not only view, create
and update. Phase 5 Pass 2 gave administrator-created channels their own list, profile
and period statement — a balance raised by the generic sale form had nowhere to be
seen or settled before it — and the whole surface is behind `customer.*`:

| Screen or action for a custom-channel buyer | Permission |
| ------------------------------------------- | ---------- |
| `/other-buyers`, the profile and the statement | `customer.view` |
| Record a receipt | `customer.payment.create` |
| Withdraw a receipt | `customer.payment.cancel` |
| Record a sale | `milk.sale.create`, through the generic Other Sales form |

The two alternatives were both worse. A permission per administrator-created channel
cannot be seeded, because the channels do not exist when the roles do, so every new
channel would need a role edit before anybody could use it. Borrowing `mandali.*`
would hand settlement authority to whoever may be paid by a sweet shop. See
[DECISIONS.md](DECISIONS.md) D48.

`BuyerPaymentChannelTest` asserts the families stay separate in both directions:
`mandali.payment.create` does not pay a vendor or a sweet shop, and
`customer.payment.create` does not pay a Mandali.

A business that ever needs a custom channel walled off from its direct customers
changes one method — `BuyerPermissions::familyFor()` — and seeds one permission. No
screen changes.

### Every catalogued permission is either checked or explicitly waiting

A permission nobody checks is worse than a missing one: it appears on the role screen,
an administrator grants or withholds it believing the decision has an effect, and
nothing changes. `tests/Feature/Buyers/PermissionEnforcementTest.php` asserts that
every identifier in the catalogue is enforced somewhere in `app/`, `routes/` or
`resources/views/` — directly, through a `PermissionCatalog` constant, or through the
channel-family suffix that `BuyerPermissions` composes at runtime.

The exceptions are listed in that test, each with the phase that will enforce it:

| Permissions | Waiting for |
| ----------- | ----------- |
| `animal.*` | Phase 6 |
| `employee.*` | Phase 7 |
| `report.*` | Phase 9 |
| `finance.payment.view`, `finance.payment.create` | the phases that create outgoing payments — salaries and suppliers. Receipts from buyers are governed by the channel families, so these are not a second way to take money in |
| `permission.manage` | a permission editor, if one is ever wanted. Roles carry permissions today, and `role.manage` guards that |
| `milk.customer_delivery.override_rate` | a grid re-pricing workflow (D45) |
| `milk.production.delete` | nothing: there is no destructive production route, by decision (D33) |

The test fails in **both** directions. An identifier that starts being checked must be
removed from the waiting list, and an identifier that stops being checked fails the
first test rather than quietly granting nothing.

The buyer list shows only the channels the user may view, so it never lists rows
the server would then refuse to open — and the refusal is the protection, not the
filtering.

### Animals

`animal.view`, `animal.create`, `animal.update`, `animal.event.create`,
`animal.purchase.create`

### Finance

`expense.view`, `expense.create`, `expense.update`, `expense.cancel`

`finance.view`, `finance.account.manage`, `finance.payment.view`,
`finance.payment.create`, `finance.cashbook.view`

`partner.view`, `partner.create`, `partner.update`, `partner.finance.view`,
`partner.contribution.create`

### Employees

`employee.view`, `employee.create`, `employee.update`,
`employee.document.view`, `employee.document.manage`

`employee.salary.view`, `employee.salary.manage`, `employee.loan.view`,
`employee.loan.manage`

`employee.document.view` is checked by the download controller on every request,
not only when rendering the document list.

### Reports

`report.operational.view`, `report.financial.view`, `report.export`

Exports re-check the same permission as the report they render.

### Administration

`user.manage`, `role.manage`, `permission.manage`, `settings.manage`,
`audit.view`

---

## Seeded role matrix

The starting allocation, seeded on first run. **These are editable defaults, not
business rules.** Nothing in the codebase decides access from a role name, and an
administrator can change any of it at runtime.

Counts as of Phase 5, with 73 permissions in the catalogue.

| Role | Permissions | Deliberately excluded |
| ---- | ----------: | --------------------- |
| Super Admin | **73** (all) | nothing — re-synced to the whole catalogue on every seed |
| Owner | **69** | `user.manage`, `role.manage`, `permission.manage`, `milk.production.delete` |
| Accountant | **32** | `expense.cancel`, `finance.account.manage`, employee documents, audit, settings, all milk writes including usage |
| Manager | **30** | all money, rate override, milk adjustment, sale cancellation, usage cancellation, `customer.archive`, `animal.purchase.create` |
| Partner | **19** | every create/update/manage — visibility only |
| Data Operator | **15** | all money and masters; entry screens only |
| Viewer | **14** | every non-`.view` permission, plus financial, salary, document and audit views |

Owner is defined as a diff against the catalogue — everything except the four
listed — so it picks up permissions added by later phases automatically. Every other
role is an explicit list, and re-seeding does not alter one after first creation.

The Phase 3 additions landed as:

| Permission | Roles holding it by default |
| ---------- | --------------------------- |
| `milk.usage.view` | Super Admin, Owner, Partner, Manager, Accountant, Data Operator, Viewer |
| `milk.usage.create` | Super Admin, Owner, Manager, Data Operator |
| `milk.usage.cancel` | Super Admin, Owner |
| `milk.adjustment.cancel` | Super Admin, Owner |

Both cancellations stay with the Owner, which is consistent with the existing
exclusions: Manager already holds none of `milk.sale.cancel`,
`milk.adjustment.create` or the rate override, because those are the ways to depart
from recorded truth.

The allocation follows the job, not the seniority of the name:

- **Owner** has full business authority but cannot hand out authority. Separating
  "runs the business" from "decides who can do what" means an account compromise
  does not escalate into permanent access.
- **Accountant** may record and correct expenses but not cancel them, and may not
  create or edit the financial accounts themselves.
- **Manager** touches no money at all, and holds none of the permissions
  that allow departing from recorded truth — `milk.customer_delivery.override_rate`,
  `milk.adjustment.create`, `milk.adjustment.cancel`, `milk.sale.cancel` and
  `milk.usage.cancel`.
- **Data Operator** has only the daily entry screens and the buyer lists those
  screens need.
- **Viewer** holds only permissions ending in `.view`, and not the sensitive ones:
  read-only is not the same as may-see-everything.

Asserted by `tests/Feature/Admin/RolePermissionTest.php`, including that no role
but Super Admin receives account administration, that Viewer holds nothing but
view permissions, and that operational roles receive no money permissions.

## Protection of seeded identifiers

| Action | Allowed? |
| ------ | -------- |
| Change which permissions a role holds | yes, for every role except Super Admin |
| Create a custom role | yes |
| Delete a custom role | yes, once no user holds it |
| Rename or delete a seeded role | **no** |
| Edit Super Admin's permissions | **no** — always the whole catalogue |
| Create a custom permission | yes; it appears under "Other permissions" |
| Rename or delete a seeded permission | **no** — see [DECISIONS.md](DECISIONS.md) D15 |

**A custom permission is not granted to Super Admin automatically.** The seeder
syncs Super Admin to `PermissionCatalog::all()` — the seeded catalogue — and
nothing else. An administrator who creates a permission decides which roles hold
it, including whether Super Admin does.

That has a consequence worth knowing before it surprises someone: because the
sync is a `syncPermissions`, a custom permission granted to **Super Admin
specifically** is removed the next time the seeder runs. Grant custom permissions
through a custom role, or re-grant after a deploy. The alternative — having the
seeder adopt every permission it finds in the database — would mean any
permission anyone creates silently becomes a Super Admin capability, which is the
worse failure.

## Rules that must hold

1. A role change takes effect on the user's next request. Permissions are never
   cached into the session in a way that outlives a role edit.
2. Removing a permission from a role immediately removes access for every user
   holding that role.
3. Financial visibility (`finance.*`, `partner.finance.view`,
   `report.financial.view`) is separable from operational access, so a data
   operator can enter milk without seeing money.
4. Every permission is covered by a test asserting that a user **without** it is
   refused server-side.
