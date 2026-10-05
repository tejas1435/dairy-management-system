# MASTER DEVELOPMENT PROMPT — DAIRY MANAGEMENT SYSTEM

You are the Principal Software Architect, Senior Laravel Developer, Database Architect, Security Engineer, QA Engineer, and UI/UX Engineer responsible for building this application end-to-end.

Read this entire specification before writing code.

Your responsibility is to build a complete, production-quality Dairy Management System, not merely create scaffolding, mock screens, placeholder controllers, or incomplete CRUD pages.

The system must be functional end-to-end with real database-backed calculations, validations, authorization, responsive UI, reports, exports, tests, demo data, documentation, PWA support, and deployment instructions.

---

# 1. EXECUTION MODE

Build the application step-by-step in phases.

At the end of every phase:

1. Run migrations where applicable.
2. Run automated tests.
3. Run Laravel Pint/code quality checks.
4. Fix failures before continuing.
5. Update `docs/PROGRESS.md`.
6. Record important architecture decisions in `docs/DECISIONS.md`.
7. Give a concise checkpoint summary.
8. Continue automatically to the next phase.

Do NOT stop after every file or ask for confirmation after routine implementation decisions.

Only stop and ask the user when there is a genuine blocker such as:

- conflicting requirements that materially change business behavior;
- destructive action involving existing user data;
- required dependency incompatibility with no safe alternative;
- environment problem that cannot be solved from the repository.

If the context window is becoming insufficient, update `docs/PROGRESS.md` with:

- completed work;
- database state;
- tests passing/failing;
- pending work;
- exact next task;
- important architecture decisions.

Then a future Claude Code session must be able to continue by reading that file.

Never claim the project is complete unless the final Definition of Done in this specification passes.

---

# 2. PRODUCT OVERVIEW

Build a professional browser-based dairy/cattle business management application for a local dairy business.

This is NOT currently a SaaS/multi-tenant product.

There is one business.

However, the architecture must support:

- unlimited users;
- unlimited partners;
- unlimited employees;
- unlimited cows/buffaloes;
- unlimited customers;
- unlimited vendors;
- multiple Mandalis;
- multiple sales channels;
- multiple farms/locations in the future.

V1 exposes one primary farm/location in normal workflows.

Do not implement SaaS registration, tenant switching, subscription billing, tenant middleware, or organization isolation.

Create a `businesses` entity and `farms` entity so future multi-location expansion does not require redesign.

---

# 3. TECHNOLOGY STACK

Use:

- Laravel 13.x
- PHP 8.3 minimum
- Prefer PHP 8.4 for Docker/local container setup
- MySQL 8.4 LTS
- Blade templates
- Vanilla modern JavaScript / ES modules
- Bootstrap 5.3.x
- Bootstrap Icons
- Vite
- Chart.js for dashboard charts
- Laravel session authentication
- Spatie Laravel Permission using the latest Laravel-13-compatible version
- Laravel database notifications
- Laravel filesystem abstraction
- Laravel queues where beneficial
- PHPUnit or Pest using the testing stack already preferred by the Laravel installation
- Laravel Pint

DO NOT introduce:

- React
- Vue
- Inertia
- Livewire
- Tailwind CSS
- jQuery unless there is an unavoidable, documented reason
- unnecessary frontend frameworks
- unnecessary microservices

Prefer native Laravel functionality and simple maintainable architecture.

Bootstrap should be installed through NPM/Vite rather than depending on CDN assets.

Use current stable compatible package versions.

Before installing third-party packages, verify they are compatible with the installed Laravel/PHP version.

Do not downgrade Laravel silently because a package is outdated.

If an export package is incompatible, choose another maintained library.

---

# 4. APPLICATION STYLE

This is a professional business application.

Do NOT design it like an application specifically for elderly users.

Do NOT use unnecessarily oversized buttons, huge typography, cartoon-style interfaces, or overly simplified kiosk screens.

The application should resemble a clean modern business/SaaS dashboard.

Design goals:

- clean;
- professional;
- efficient;
- compact but readable;
- responsive;
- keyboard-friendly;
- fast repetitive data entry;
- consistent;
- accessible;
- minimal visual clutter.

Primary environment:

Desktop/laptop browser.

Also fully support:

- tablet;
- mobile browser;
- installable PWA.

---

# 5. UI LAYOUT

Create a reusable application shell.

Desktop:

Left sidebar containing:

Dashboard

Milk
- Production
- Customer Daily Entry
- Mandali
- Vendors
- Other Sales
- Milk Usage / Reconciliation

Animals

Finance
- Income / Payments Received
- Expenses
- Cashbook
- Financial Accounts
- Partners

Employees
- Employees
- Salary
- Loans / Advances

Reports

Administration
- Users
- Roles & Permissions
- Notifications
- Audit Log

Settings
- Business
- Farm
- Sales Channels
- Milk Prices
- Expense Categories
- Payment Methods
- Language/Profile

Top navigation:

- page/context information;
- notifications;
- language switcher;
- user profile menu.

On mobile:

Use Bootstrap offcanvas sidebar.

Use consistent:

- page headers;
- breadcrumbs where helpful;
- action buttons;
- filters;
- pagination;
- forms;
- tables;
- status badges;
- empty states;
- confirmation dialogs;
- toasts/alerts;
- validation messages.

---

# 6. RESPONSIVE REQUIREMENTS

Every major feature must work on:

- desktop;
- laptop;
- tablet;
- mobile.

Desktop should remain the most efficient interface.

For large tables:

- use responsive horizontal scrolling where appropriate;
- support sticky headers;
- use sensible sticky first columns when useful;
- do not transform every professional table into giant mobile cards unnecessarily.

Optimize the Customer Daily Entry grid especially carefully.

---

# 7. LOCALIZATION

Support from the beginning:

- English
- Gujarati
- Hindi

English is the default language.

Every user has an individual preferred language.

Store locale on the user.

Provide a language switcher.

After switching, persist the preference.

Do not hard-code application-facing UI text directly throughout Blade templates.

Use Laravel translation files.

Create translation files for:

`en`
`gu`
`hi`

Translate:

- menus;
- buttons;
- headings;
- statuses;
- validation-facing labels;
- common messages;
- dashboard labels;
- module labels.

User-generated data such as customer names, notes, animal names, etc. must remain as entered.

---

# 8. REGIONAL SETTINGS

Defaults:

Currency:
INR / ₹

Milk measurement:
Litres

Milk precision:
Up to 3 decimal places.

Money precision:
2 decimal places.

Date display:
DD-MM-YYYY

Timezone:
Asia/Kolkata

Use business dates as proper DATE values instead of encoding them in strings.

Use consistent timezone handling.

---

# 9. AUTHENTICATION

Authentication method:

Email + password only.

No public registration.

Users are created by authorized administrators.

Implement:

- login;
- logout;
- session regeneration after login;
- forgot password;
- password reset;
- profile;
- change password;
- preferred language;
- active/inactive user status.

Rate-limit login attempts.

Inactive users cannot authenticate.

Use Laravel hashing.

Never store plaintext passwords.

---

# 10. ROLES AND PERMISSIONS

Use granular permissions rather than hard-coded role checks.

Use Spatie Laravel Permission.

Default roles:

- Super Admin
- Owner
- Partner
- Manager
- Accountant
- Data Operator
- Viewer

Roles must be editable.

Permissions must be editable.

Users may be assigned roles.

Prefer permissions through roles instead of giving direct permissions to users.

Create permissions similar to:

dashboard.view

milk.production.view
milk.production.create
milk.production.update
milk.production.delete

milk.customer_delivery.view
milk.customer_delivery.create
milk.customer_delivery.update
milk.customer_delivery.override_rate

milk.sale.view
milk.sale.create
milk.sale.update
milk.sale.cancel

milk.reconciliation.view
milk.adjustment.create

customer.view
customer.create
customer.update
customer.archive
customer.payment.create

mandali.view
mandali.create
mandali.update
mandali.payment.create
mandali.settlement.manage

vendor.view
vendor.create
vendor.update
vendor.payment.create

animal.view
animal.create
animal.update
animal.event.create
animal.purchase.create

expense.view
expense.create
expense.update
expense.cancel

finance.view
finance.account.manage
finance.payment.view
finance.payment.create
finance.cashbook.view

partner.view
partner.create
partner.update
partner.finance.view
partner.contribution.create

employee.view
employee.create
employee.update
employee.document.view
employee.document.manage

employee.salary.view
employee.salary.manage
employee.loan.view
employee.loan.manage

report.operational.view
report.financial.view
report.export

user.manage
role.manage
permission.manage

settings.manage

audit.view

Do not rely only on hiding menu items.

Enforce authorization server-side using policies, middleware, Gates and/or controller authorization.

---

# 11. BUSINESS/FARM MODEL

Create a business record.

Suggested fields:

business name
legal/display name if useful
mobile
email
address
currency
timezone
date format
default locale
active status

Create farms/locations.

Suggested fields:

business_id
name
code
address
is_primary
active

V1:

Use the primary farm automatically in operational forms.

Do not clutter normal users with a farm selector yet.

Design database foreign keys so future multi-farm support is possible.

Operational records should include `farm_id` where appropriate.

---

# 12. SALES CHANNEL ARCHITECTURE

Do NOT hard-code the system so only three sales destinations can ever exist.

Create configurable sales channels.

Seed system channels:

1. Mandali
2. Vendor
3. Direct Customer

Allow future custom channels such as:

- hotel;
- restaurant;
- tea shop;
- sweet shop;
- other dairy;
- bulk buyer;
- other.

System channels can contain special UI/business behavior.

Custom channels can use a generic sale entry interface.

Create a generic Buyer entity associated with a sales channel.

A buyer can therefore represent:

- Mandali;
- vendor;
- direct customer;
- future buyer.

Suggested buyer fields:

business_id
sales_channel_id
name
mobile
email optional
address
area
payment_cycle optional
active
notes
created_by

---

# 13. MILK TYPES

Initially support:

- Cow
- Buffalo

Do not scatter literal values across the codebase.

Use PHP backed enums/value objects/constants appropriately.

Prefer database string columns over MySQL ENUM when future extensibility matters.

---

# 14. MILK PRODUCTION

Milk production is entered date-wise and shift-wise.

Shifts:

- Morning
- Evening

For every date + farm + shift record:

- cow milk quantity;
- buffalo milk quantity;
- notes;
- created by;
- updated by.

The database must prevent duplicate production records for the same:

farm + date + shift.

UI should present a professional matrix such as:

| Milk Type | Morning | Evening | Total |
| Cow | ... | ... | ... |
| Buffalo | ... | ... | ... |
| Total | ... | ... | ... |

Allow entering morning and evening efficiently.

Do not require animal-wise production in V1.

Design so animal-wise milk production can be added later without rewriting this module.

---

# 15. MILK RECONCILIATION

For each:

date + shift + milk type

calculate:

Available Milk
=
Production
+
Authorized Adjustments

Allocated Milk
=
Sales
+
Internal Usage

Remaining Milk
=
Available - Allocated

Internal usage types:

- Calf Feeding
- Home Use
- Sample
- Wastage
- Other

Provide a Milk Reconciliation screen.

Show:

Production
Mandali
Vendors
Direct Customers
Other Sales
Calf
Home
Wastage
Other
Adjustment
Remaining

Never silently allow invalid milk allocation.

If production has not yet been entered, clearly display:

"Production not entered"

rather than pretending production is zero.

If distribution exceeds recorded production:

- block normal users;
- authorized users may override through a specific adjustment flow;
- require a reason;
- audit it.

Do not silently create balancing records.

---

# 16. DIRECT CUSTOMER MASTER

Direct customers are registered once.

They should NOT be selected manually every day.

Customer profile should include:

- name;
- mobile;
- address;
- area;
- active/inactive;
- delivery note/caption;
- start date;
- payment cycle;
- milk preferences;
- default quantity reminders;
- customer-specific rate if applicable.

A customer may potentially receive Cow Milk, Buffalo Milk, or both.

Model preferences in a way that supports more than one milk type per customer.

For each customer + milk type preference store:

- morning quantity reminder;
- evening quantity reminder;
- active status.

Example:

Rajesh Patel
Cow Milk

Morning reminder: 1 L
Evening reminder: 2 L

These quantities are REMINDERS.

They are not contractual limits.

---

# 17. DIRECT CUSTOMER PRICE SYSTEM

Implement:

Business default milk price
+
optional buyer/customer-specific price override.

Prices must support:

Cow Milk
Buffalo Milk

Price changes must have effective dates.

Do not overwrite historical prices in a way that changes old sales.

Create price history.

A sale must store the applied unit rate as a snapshot.

Price resolution:

1. Find active customer-specific rate for sale date/milk type.
2. Otherwise use business default rate effective on that date.
3. If neither exists, block save and clearly tell the user to configure the price.

Provide Milk Price settings.

Do NOT implement Mandali fat formulas in V1.

---

# 18. CUSTOMER PAUSE PERIODS

Create customer pause functionality.

Customer can be paused between:

start date
and
end date.

Include optional reason.

Examples:

- vacation;
- temporary stop;
- no milk required.

On the Daily Customer Entry screen:

- paused customers should be visually marked;
- quantity fields should be disabled by default;
- no sales should be generated automatically for them.

An authorized override can be allowed with a reason and audit entry if necessary.

---

# 19. CUSTOMER DAILY MILK ENTRY — CRITICAL FEATURE

This is one of the most important screens in the project.

Do not build it as a normal "Add Sale" form.

User workflow:

Milk
→ Customer Daily Entry
→ Select date
→ all active direct customers automatically appear vertically as rows.

Example structure:

| Customer | Milk | Reminder | Morning | Evening | Rate | Total |
| Rajesh Patel | Cow | M 1L / E 2L | input | input | ₹70 | auto |
| Amit Patel | Cow | M 2L / E 2L | input | input | ₹70 | auto |
| Mahesh | Buffalo | M 1L / E 1L | input | input | ₹85 | auto |

Important behavior:

The customer name is never repeatedly selected.

Rows come from registered customers.

Morning and Evening are editable quantity fields.

Default reminder quantity must be shown as subtle helper/reminder text.

IMPORTANT:

Do NOT automatically prefill morning/evening fields using reminder values.

The user explicitly wants reminder values visible while actual daily fields remain manually entered.

Provide an optional:

"Copy Previous Day"

action.

It must happen only when the user explicitly clicks it.

Never auto-copy previous quantities.

Provide:

- date selector;
- customer search;
- area filter if useful;
- milk-type filter;
- sticky table header;
- keyboard-friendly numeric fields;
- Tab navigation;
- clear row status;
- paused customer indicator;
- totals at bottom/top;
- unsaved changes warning where practical;
- Save Day action.

Display totals for:

Morning Cow
Morning Buffalo
Evening Cow
Evening Buffalo
Overall Quantity
Overall Amount

Use one bulk-save request/transaction.

---

# 20. INTERNAL DATA MODEL FOR CUSTOMER DAILY ENTRY

The UI is one daily grid.

The database may correctly store Morning and Evening as separate sale records.

Do not confuse UI convenience with database normalization.

For every entered quantity create/update the appropriate milk sale record.

Store:

farm
sale date
shift
milk type
sales channel
buyer
quantity
unit rate snapshot
amount
source
status
creator/updater

Use source similar to:

`customer_daily_grid`

Ensure repeated saving is idempotent.

Saving the same day twice must update existing customer daily sale records rather than creating duplicates.

Use a deterministic unique key or equivalent safe database/application strategy for:

customer + date + shift + milk type + daily-grid source.

If a previously entered quantity is intentionally removed, safely cancel/remove the corresponding grid-generated entry according to financial/audit rules instead of leaving stale sales.

Use database transactions.

---

# 21. DIRECT CUSTOMER LEDGER

Customer profile must include a ledger.

Show:

date
morning quantity
evening quantity
total milk
rate
sale amount
payments
balance

Provide date filters.

Provide monthly statement view.

Example totals:

September Milk
89 L

Sales
₹5,785

Payments Received
₹4,000

Outstanding
₹1,785

The user should never need to manually duplicate sales into finance.

Customer Daily Entry automatically generates sales/receivables.

---

# 22. MANDALI MANAGEMENT

Support one or more Mandalis.

Mandali is represented as a Buyer under the Mandali sales channel.

Mandali delivery fields:

- date;
- shift;
- milk type;
- quantity;
- fat percentage;
- SNF percentage optional;
- unit rate;
- automatically calculated amount;
- notes;
- slip/document attachment optional.

MANDALI RATE RULE:

The user selected manual rate entry.

Do NOT implement automatic fat/SNF pricing formulas in V1.

Fat and SNF are recorded for reference/reporting.

Rate is manually entered.

Amount:

quantity × unit rate.

Store rate snapshot.

---

# 23. MANDALI SETTLEMENT

Mandali normally settles after a period/month.

Provide monthly/period settlement functionality.

Settlement should include:

buyer/Mandali
period start
period end
milk quantity
system-calculated expected sales amount
Mandali statement amount if entered
difference/adjustment
status
payment information
notes

Statuses can include:

Draft
Finalized
Partially Paid
Paid
Cancelled

If a finalized Mandali statement amount differs from the system expected amount, do not silently modify historical milk rates.

Record an explicit buyer balance/settlement adjustment with:

amount
reason
settlement reference
creator
timestamp.

Provide Mandali monthly statement/report.

---

# 24. VENDOR MANAGEMENT

Support multiple local dairies/vendors.

Vendor delivery entry:

- date;
- shift;
- milk type;
- quantity;
- buyer;
- rate;
- calculated amount;
- notes.

Allow a buyer-specific default rate if configured.

Allow authorized manual rate override.

Store applied rate snapshot.

Provide vendor ledger:

Sales
Payments Received
Outstanding

Provide period filters.

---

# 25. OTHER SALES CHANNELS

Allow administrators to create future sales channels.

For non-system/custom channels provide a generic sale entry form:

buyer
date
shift
milk type
quantity
rate
amount
notes

All sales must feed the same reporting and milk-reconciliation engine.

---

# 26. PAYMENTS RECEIVED

Payments in this system are ACCOUNTING/DATA ENTRY ONLY.

DO NOT implement:

- payment gateway;
- UPI API;
- Stripe;
- Razorpay;
- bank API;
- actual fund transfer.

Seed payment methods:

- Cash
- UPI
- Bank Transfer
- Cheque
- Other

When recording a payment, the user simply selects how payment was received.

Buyer payment fields:

buyer
date
amount
payment method
business financial account receiving money
reference number optional
settlement optional
notes

Payments reduce buyer outstanding.

Payments received into a business Cash/Bank account should generate financial ledger entries automatically.

---

# 27. BUYER OUTSTANDING

Buyer outstanding should come from real transactions.

Do not maintain an arbitrary manually editable "current outstanding" number.

Conceptually calculate:

Sales
+
Receivable Adjustments
-
Payments Received
=
Buyer Balance

Support credit/negative balance only through an explicit authorized workflow if necessary.

Normal payment entry should warn/block accidental overpayment.

---

# 28. FINANCIAL ACCOUNTS

Create business financial accounts.

Types:

- Cash
- Bank
- Other

Examples:

Cash
Main Bank Account

Fields:

name
type
opening balance
active
notes

Do NOT integrate with banks.

These are bookkeeping accounts inside this application.

---

# 29. FINANCIAL LEDGER

Create an internal business account ledger.

Recommended table:

`financial_ledger_entries`

Each record should include:

financial_account_id
date
direction: credit/debit
amount
reference_type
reference_id
description
created_by
timestamps

Ledger entries should normally be created by domain services, not manually edited.

Examples:

Customer payment received
→ Cash/Bank Credit

Mandali payment
→ Cash/Bank Credit

Partner contributes ₹50,000 to business cash
→ Cash Credit

Feed expense paid from Cash
→ Cash Debit

Salary paid from Bank
→ Bank Debit

Employee loan paid from Cash
→ Cash Debit

Account balance:

Opening Balance
+ Credits
- Debits

Do not store an untraceable manually editable current balance.

---

# 30. PARTNER MANAGEMENT

Partner count must be unlimited.

Never build:

partner_1
partner_2

columns.

Partners are dynamic records.

Partner fields:

- name;
- mobile;
- email optional;
- joining date;
- active/inactive;
- notes.

Do NOT implement in V1:

- ownership percentage;
- profit-sharing percentage;
- profit distribution;
- partner loan-to-business contracts.

Current requirement:

Partners contribute money or directly pay business expenses.

---

# 31. PARTNER CONTRIBUTIONS

Partner can contribute money into a business Cash/Bank account.

Record:

partner
date
amount
destination financial account
payment method
reference
notes

This should:

1. create Partner Contribution;
2. credit the selected business account;
3. appear in Partner Ledger;
4. appear in Cashbook;
5. be auditable.

---

# 32. MULTI-SOURCE PAYMENT SPLIT

A single expense can be paid by any number of sources.

Example:

Cow Purchase
₹90,000

Partner A
₹40,000

Partner B
₹30,000

Business Bank
₹20,000

Total allocated:
₹90,000

Remaining:
₹0

Sources may be:

- Partner
- Business Financial Account

Do NOT design fixed partner columns.

Create reusable funding allocation architecture.

Recommended concept:

`funding_allocations`

Fields such as:

payable_type
payable_id
source_type
source_id
amount
payment_method_id
reference
created_by

Use a controlled Laravel morph map if using polymorphic relations.

Never store PHP class names directly as long-term business identifiers.

Source type aliases could be:

`partner`
`financial_account`

Payable aliases could include:

`expense`
`payroll_payment`
`employee_loan_disbursement`

All allocation sums must be validated transactionally.

---

# 33. PARTNER LEDGER

Partner ledger should derive from actual records.

Show:

Date
Type
Reference
Description
Amount

Entries include:

- cash contribution into business;
- expense directly paid by partner;
- animal purchase paid by partner;
- salary/loan directly paid by partner if applicable.

Do not duplicate records manually.

Show total contribution/payment by partner over selected period.

---

# 34. EXPENSE MANAGEMENT

Expense categories should be configurable.

Seed:

- Animal Food / Feed
- Medicine
- Animal Purchase
- Accessories / Equipment
- Vehicle
- Employee
- Electricity
- Farm
- Repair / Maintenance
- Other

Expense fields:

date
category
amount
description
vendor/payee text optional
related animal optional
related employee optional
attachment optional
notes
status
created_by

Allow split funding.

Example:

Feed Expense ₹10,000

Partner A ₹7,000
Business Cash ₹3,000

When saved:

- one Expense exists;
- two Funding Allocations exist;
- Cash ledger is debited by ₹3,000;
- Partner A ledger contains ₹7,000;
- dashboards/reports see only ONE ₹10,000 expense.

Never double-count funding allocations as separate expenses.

---

# 35. BUSINESS CASH VS MILK EARNING MONEY

When the owner says an expense is paid using money earned from milk, represent that as payment from:

Business Cash
or
Business Bank.

Do not create a special fake "milk earning wallet" unless explicitly required later.

Milk payment received creates business cash/bank balance.

Expense consumes business cash/bank balance.

---

# 36. ANIMAL MANAGEMENT

Support:

- Cow
- Buffalo

Animal profile fields should include:

farm
unique tag/animal number
name optional
photo optional
species
breed
birth date optional
approximate age/note optional
purchase date optional
purchase price optional
seller optional
notes
life status
lactation status
reproductive status

Do not use one combined animal status enum that creates combinations such as:

Pregnant + Lactating
Pregnant + Dry
etc.

Use separate state dimensions.

Recommended:

Life Status:
- Active
- Sold
- Dead

Lactation Status:
- Lactating
- Dry
- Not Started / Not Applicable

Reproductive Status:
- Unknown
- Open
- Pregnant

This correctly supports a pregnant cow that is still lactating.

---

# 37. ANIMAL EVENTS

Create event-based animal history.

V1 event types:

- Purchased
- Lactation Started
- Lactation Stopped / Dry
- Pregnancy Confirmed
- Calving
- Sold
- Died

Each event stores:

animal
event type
event date
notes
metadata if appropriate
created_by

Pregnancy event may store:

expected calving date.

Animal timeline should display events chronologically.

Domain actions should update current animal state from events.

Examples:

Lactation Started
→ lactation_status = Lactating

Lactation Stopped
→ lactation_status = Dry

Pregnancy Confirmed
→ reproductive_status = Pregnant

Calving
→ reproductive_status = Open
→ optionally allow user to mark Lactation Started in same guided workflow

Sold
→ life_status = Sold

Died
→ life_status = Dead

Sold/dead animals must not appear in Current Milking count.

Do not delete history.

---

# 38. CURRENT MILKING COUNT

Dashboard current milking animals:

life_status = Active
AND
lactation_status = Lactating

Show:

Total Animals
Cows
Buffaloes
Currently Lactating
Dry
Pregnant
Sold/Dead where appropriate

Pregnant and Lactating numbers may overlap.

That is expected.

Do not force mutually exclusive business states that are not mutually exclusive in reality.

---

# 39. ANIMAL PURCHASE — ENTER ONCE

When creating a newly purchased animal:

Animal Details
+
Purchase Details
+
Payment Split

Example:

Purchase:
₹90,000

Partner A:
₹40,000

Partner B:
₹30,000

Business Bank:
₹20,000

Saving must happen in one database transaction.

Automatically:

1. create animal;
2. create Purchased animal event;
3. create Animal Purchase expense;
4. create funding allocations;
5. debit business financial account portions;
6. update partner ledgers through source records;
7. create audit records.

The user must NOT enter the same purchase again in Expenses.

Also allow adding an existing/opening animal without generating a purchase expense when explicitly selected as an existing animal record.

---

# 40. EMPLOYEE MANAGEMENT

Employee profile:

- employee code;
- photo;
- name;
- mobile;
- email optional;
- address;
- emergency contact optional;
- designation/job;
- joining date;
- current monthly salary;
- active/inactive;
- leaving date optional;
- notes.

---

# 41. EMPLOYEE DOCUMENTS

Support secure uploads such as:

- identification document;
- photo;
- bank document;
- other employee document.

Store:

employee
document type
display name
file path
MIME type
size
uploaded_by
timestamps

Use private storage.

Never expose private files directly through predictable public URLs.

Provide authorized download/view controllers.

Validate file MIME and size server-side.

Default accepted:

PDF
JPEG
PNG

Make maximum upload size configurable.

---

# 42. EMPLOYEE SALARY

Maintain monthly employee payroll.

Unique payroll per:

employee + year + month.

Payroll fields:

base salary snapshot
bonus
other additions
loan deduction
recoverable employee-charge deduction
other deductions
net payable
paid amount
status
notes

Statuses:

Draft
Finalized
Partially Paid
Paid
Cancelled

Net calculation must be server-side.

Never trust JavaScript-only calculations.

Salary changes later must not change old payroll calculations.

Store salary snapshot on payroll.

---

# 43. SALARY PAYMENT

A finalized payroll may have one or multiple payments.

Payment can be funded by:

- Business Cash
- Business Bank
- Partner

Use the reusable Funding Allocation mechanism.

If a ₹12,000 salary payment is split:

Cash ₹5,000
Partner A ₹7,000

record it correctly.

Business Cash ledger decreases only ₹5,000.

Partner ledger records ₹7,000.

Payroll total paid increases ₹12,000.

Prevent paid amount from exceeding payroll net payable without an authorized correction workflow.

---

# 44. EMPLOYEE LOAN / ADVANCE

Support employee loans/advances.

Employee loan fields:

employee
date
original amount
description
status
notes

Support transactions such as:

- Disbursement
- Manual Repayment
- Payroll Deduction
- Adjustment with authorization

Loan balance should come from transactions, not an arbitrary editable field.

A loan disbursement can be funded through:

Business Account
and/or
Partner

using Funding Allocations.

Example:

Loan ₹20,000
Repaid ₹5,000
Outstanding ₹15,000

---

# 45. EMPLOYEE FOOD/GROCERY/ACCESSORIES

When recording an employee-related expense allow treatment:

1. Company Expense
2. Employee Benefit
3. Recoverable From Employee

If Recoverable From Employee:

- create expense normally;
- create employee recoverable charge;
- show employee outstanding charge;
- allow deduction through payroll;
- avoid entering the same item twice.

Example:

Groceries:
₹1,500

Treatment:
Recoverable From Employee

Later Payroll:
₹1,500 deduction

Employee recoverable balance decreases accordingly.

---

# 46. PAYMENT METHODS

Seed:

Cash
UPI
Bank Transfer
Cheque
Other

Payment method represents how an external/internal payment happened.

It does not process payment.

Allow administrator to activate/deactivate payment methods.

Do not remove historical method records.

---

# 47. CASHBOOK

Create a Cashbook/Accounts Ledger interface.

Filter by:

- account;
- date range;
- transaction type;
- reference.

Show:

date
description
reference
payment method if applicable
debit
credit
running balance

Running balance must be based on ledger entries.

Provide Excel/PDF/Print export.

---

# 48. DASHBOARD

Create a professional responsive dashboard.

Do not fill it with meaningless charts.

Every widget must answer a business question.

Top summary metrics should include selected useful information such as:

Today Milk Production
This Month Milk Sales
This Month Payments Received
This Month Expenses
Total Outstanding Receivable
Current Lactating Animals

IMPORTANT:

Do not confuse sales/revenue with cash received.

Display them separately.

Example:

Milk Sales This Month
₹1,72,000

Payments Received
₹1,30,000

Outstanding
₹42,000

Expenses
₹94,000

---

# 49. TODAY MILK DASHBOARD

Show:

Morning Cow
Morning Buffalo
Evening Cow
Evening Buffalo
Total Production

Total Distributed
Remaining

Distribution by:

Mandali
Vendor
Direct Customer
Other
Internal Use

If production is missing, show an informative state.

---

# 50. DASHBOARD CHARTS

Useful charts:

Milk Production Trend
- daily period
- Cow vs Buffalo

Sales vs Expenses
- monthly trend

Milk Distribution by Channel

Expense by Category

Receivables by Buyer / Channel

Optional:

Payments Received Trend

Use Chart.js.

Charts must use real DB data.

No hard-coded demo chart arrays outside seed/demo mode.

---

# 51. DASHBOARD ANIMAL SECTION

Show:

Total Active Animals
Cow
Buffalo
Lactating
Dry
Pregnant

Provide links to filtered animal lists.

---

# 52. DASHBOARD EMPLOYEE SECTION

Show useful information such as:

Active Employees
Current Month Salary Payable
Salary Paid
Salary Pending
Outstanding Employee Loans

Keep dashboard concise.

---

# 53. IN-APP NOTIFICATIONS

Implement Laravel database notifications.

Create notification bell with unread count.

Allow:

mark one read
mark all read
view notifications

Initial useful notification architecture may include:

- expected calving approaching;
- finalized salary still pending;
- Mandali settlement/payment due;
- customer pause starting/ending;
- important system/business alerts.

Avoid noisy notification spam.

Build notification services/contracts so future channels can be added.

Create architecture/interfaces for future:

WhatsApp
SMS

DO NOT implement an actual WhatsApp/SMS provider in V1.

---

# 54. REPORTS

Create a dedicated Reports module.

Core reports:

## Milk

Daily Milk Production

Date Range Production

Morning vs Evening

Cow vs Buffalo

Milk Distribution

Channel-wise Sales

Milk Reconciliation

## Customers/Buyers

Customer Monthly Statement

Customer Outstanding

Buyer Sales

Vendor Ledger

Mandali Delivery

Mandali Settlement

Buyer Payments

## Finance

Income/Payments Received

Expense Report

Expense by Category

Expense by Funding Source

Cashbook

Financial Account Statement

Partner Ledger

Partner Contributions

## Animals

Animal Register

Animal Purchase Report

Current Lactating Animals

Dry Animals

Pregnant Animals

Animal Event History

## Employees

Employee Register

Salary Report

Salary Payment Report

Loan/Advance Report

Recoverable Charges

Provide filters appropriate to each report.

---

# 55. REPORT EXPORTS

V1 requires:

- Excel
- PDF
- Print-friendly output

Use maintained Laravel-13-compatible packages/libraries.

For Excel:

Prefer a maintained compatible Excel library.

If a Laravel wrapper is incompatible, use PhpSpreadsheet directly.

For PDF:

Use a maintained compatible DOMPDF or equivalent server-side library.

Do not force-install stale packages.

Exports must honor:

- permissions;
- selected filters;
- locale where practical;
- date ranges.

---

# 56. SEARCH, FILTERS AND TABLE BEHAVIOR

List pages should support relevant:

- search;
- filters;
- date ranges;
- status;
- pagination;
- sorting where useful.

Avoid loading thousands of records into the DOM.

Use server-side pagination for normal listings.

Customer Daily Entry is an exception because it needs all relevant active customers for a selected day; still optimize the query and avoid N+1 queries.

---

# 57. DATABASE DESIGN PRINCIPLES

Use:

- proper foreign keys;
- indexes;
- unique constraints;
- transactions;
- normalized data;
- explicit history where business data changes over time.

Primary keys can be standard unsigned big integers unless there is a concrete reason otherwise.

Recommended data types:

Milk Quantity:
DECIMAL(10,3)

Money:
DECIMAL(14,2)

Rates:
DECIMAL(10,2)

Fat/SNF:
DECIMAL(5,2)

Do not use floating-point persistence for financial values.

Centralize financial/milk calculations so rounding rules are consistent.

Avoid using JSON for core searchable/relational business data.

JSON metadata is acceptable for infrequent event-specific extra data.

---

# 58. BASELINE DATABASE ENTITIES

Use the following as the baseline architecture.

You may improve naming/normalization if necessary, but document meaningful changes in `docs/DECISIONS.md`.

Core:

businesses
farms
users

Spatie roles/permissions tables

Master:

sales_channels
buyers
customer_preferences
customer_pauses
milk_price_rules
buyer_price_rules
payment_methods
financial_accounts
expense_categories
partners
employees

Milk:

milk_productions
milk_sales
milk_usages
milk_adjustments

Buyer Finance:

buyer_payments
buyer_settlements
buyer_balance_adjustments

Business Finance:

expenses
funding_allocations
financial_ledger_entries
partner_contributions

Animals:

animals
animal_events

Employees:

employee_documents
employee_payrolls
payroll_payments
employee_loans
employee_loan_transactions
employee_loan_disbursements if needed
employee_charges

System:

notifications
audit_logs

Do not create unnecessary duplicate tables if a cleaner domain model provides the same behavior.

---

# 59. AUDIT LOGGING

Build an application audit log.

Do not depend on a package that forces an unnecessarily higher PHP requirement.

Audit important create/update/cancel/status changes.

Audit record should include:

user
action
auditable type
auditable ID
old values
new values
IP
user agent
timestamp

Do not record:

passwords
password reset tokens
entire sensitive uploaded documents
secret configuration values.

Audit especially:

- milk adjustments;
- customer delivery corrections;
- rate overrides;
- expenses;
- funding splits;
- partner contributions;
- payments received;
- Mandali settlements;
- animal purchase;
- animal lifecycle changes;
- salary;
- loans;
- role/permission changes.

Create an Audit Log viewer restricted by permission.

---

# 60. DELETE/CANCELLATION RULES

Do not casually hard-delete financial history.

For important financial/operational transaction records use statuses such as:

Active
Cancelled

Store:

cancelled_at
cancelled_by
cancellation_reason

Cancelled records must no longer affect:

balances
reports
milk allocation
dashboard totals

but must remain auditable.

Master data such as buyers/partners/employees should normally be deactivated instead of deleted if history exists.

---

# 61. SECURITY

Treat security as part of implementation, not a final patch.

Implement:

- CSRF protection;
- session security;
- login throttling;
- server-side validation;
- server-side authorization;
- escaped Blade output;
- safe file uploads;
- route authorization;
- password hashing;
- session regeneration;
- secure logout;
- protection against mass-assignment mistakes;
- database transactions;
- secure private file access;
- appropriate security headers;
- production cookie configuration documentation;
- no secrets committed to repository.

Avoid raw SQL unless genuinely needed.

When raw SQL is used, use bindings.

Do not trust IDs coming from forms without authorization.

Avoid exposing sequential IDs as an authorization mechanism.

Possessing an ID does not grant access.

---

# 62. FILE SECURITY

Use Laravel filesystem abstraction.

Development storage:

Local/private storage.

Architecture must allow later switch to:

S3-compatible storage

without redesign.

Employee documents, receipts, bills and other sensitive attachments must not be directly public.

Authorized controller:

checks user
checks permission
locates file
streams/downloads securely.

Randomize stored filenames.

Preserve original filename only as metadata.

Validate:

extension
MIME
size

Do not execute uploaded files.

---

# 63. VALIDATION RULES

Implement validation through Form Requests or equivalent domain validation.

Examples:

No negative milk quantities.

No negative expense amount.

No zero-value financial records unless explicitly valid.

Fat/SNF values within sensible configured limits.

Rate must be positive.

Customer daily quantity cannot be negative.

Paused customer cannot receive delivery through normal workflow.

Duplicate customer/date/shift/milk-type daily-grid records must be prevented.

Distribution exceeding available recorded milk must be caught.

Funding allocation total must equal required payment amount.

Animal purchase payment split must equal purchase amount when marked fully paid.

Sold/dead animal cannot be marked lactating without explicit valid reactivation workflow.

Payment received must not silently exceed outstanding.

Payroll cannot be paid above payable amount.

Employee loan deduction cannot exceed applicable outstanding balance.

Uploaded documents must match permitted MIME/size.

All important validation must exist server-side even if JavaScript also validates it.

---

# 64. TRANSACTION SAFETY

Use `DB::transaction()` for multi-record business operations.

Required examples:

Customer daily grid bulk save.

Animal purchase.

Expense with funding allocations.

Partner contribution + cash ledger entry.

Buyer payment + cash ledger entry.

Payroll payment + funding allocations.

Employee loan disbursement.

Mandali settlement finalization.

If one part fails, the entire business operation must roll back.

---

# 65. DOMAIN SERVICE / ACTION ARCHITECTURE

Do not put complex financial logic into Blade templates or giant controllers.

Controllers should be reasonably thin.

Use:

Form Requests
Policies / permissions
Service classes
Action classes where helpful
PHP enums
DTOs/value objects when they add clarity

Examples of useful domain services/actions:

SaveCustomerDailyDeliveries

CreateMilkSale

CalculateMilkReconciliation

RecordBuyerPayment

CreateExpense

AllocateFundingSources

CreatePartnerContribution

PurchaseAnimal

RecordAnimalEvent

GeneratePayroll

RecordPayrollPayment

DisburseEmployeeLoan

FinalizeMandaliSettlement

FinancialLedgerService

PriceResolver

Do not introduce a repository layer merely for ceremony.

Use Eloquent cleanly.

---

# 66. ENTER ONCE, UPDATE EVERYWHERE

This is a fundamental product rule.

Customer Daily Milk:
→ Milk Sale
→ Milk Distribution
→ Customer Ledger
→ Receivable
→ Dashboard
→ Reports

Customer Payment:
→ Buyer Payment
→ Receivable Balance
→ Financial Account Credit
→ Cashbook
→ Dashboard

Animal Purchase:
→ Animal
→ Animal Event
→ Expense
→ Funding Allocation
→ Partner Ledger
→ Business Account Ledger where applicable
→ Reports

Expense:
→ Expense
→ Funding Sources
→ Account Ledger
→ Partner Ledger
→ Dashboard

Salary Payment:
→ Payroll
→ Payment
→ Funding Sources
→ Financial Ledger
→ Partner Ledger if applicable

Never require duplicate manual entry for information the system already possesses.

---

# 67. PWA

The application must be installable as a Progressive Web Application.

Implement:

- web app manifest;
- app name;
- short name;
- icons;
- theme/background configuration;
- service worker;
- installability requirements;
- appropriate meta tags.

IMPORTANT:

This application does NOT need offline-first business data behavior.

Internet availability is reliable.

Do not build complex offline database synchronization.

Do not queue financial/milk writes locally while offline.

Service worker should focus primarily on:

- static assets;
- application shell assets;
- safe caching.

Dynamic authenticated business pages/data should prefer network-first/no-cache behavior as appropriate.

If offline during a write operation:

show a clear connection failure.

Do NOT falsely display successful save.

---

# 68. UI JAVASCRIPT

Use vanilla JavaScript modules.

Use Fetch API for AJAX functionality.

All requests must include CSRF handling where needed.

JavaScript is for:

- customer daily grid;
- calculations/previews;
- dependent fields;
- modals;
- confirmation;
- charts;
- responsive interactions;
- filters;
- async saves where they improve UX.

Server remains authoritative.

Do not move critical business logic exclusively to JS.

---

# 69. CUSTOMER GRID UX DETAILS

Spend extra development effort on Customer Daily Entry.

Desktop behavior:

- customer names in first column;
- milk type;
- reminder;
- morning;
- evening;
- rate;
- total;
- status.

Use compact normal-sized Bootstrap controls.

Keep row height reasonable.

Support keyboard Tab navigation through quantity inputs.

Use numeric input.

Accept decimal quantities.

Provide visual modified/unsaved state if practical.

Provide Save Day.

Provide Copy Previous Day.

Show daily totals live in UI, but recompute server-side after save.

If customer has a delivery note/caption, show it unobtrusively below/beside customer name.

Example:

Rajesh Patel
"Evening after 6 PM"

Reminder:
M 1L / E 2L

Fields:
Morning [    ]
Evening [    ]

Do not prefill quantities from reminder.

---

# 70. DEFAULT PRICE UX

Settings → Milk Prices

Show price history.

Example:

Cow Milk:
₹70/L effective 01-09-2026

Buffalo:
₹85/L effective 01-09-2026

When rate changes:

close/end previous effective record
create new effective record.

Customer profile:

Default Cow Price:
₹70

Customer Override:
₹72

Effective:
01-10-2026

Past sales remain unchanged because sale stores unit rate snapshot.

---

# 71. PERFORMANCE

Prevent N+1 queries.

Use eager loading.

Use SQL/database aggregation instead of looping through entire tables for dashboards.

Add indexes for common filters such as:

farm_id
sale_date
shift
milk_type
buyer_id
sales_channel_id
expense date/category
animal status
employee status
partner
payment date
settlement period

Paginate large pages.

Do not prematurely add distributed caching architecture.

Redis can remain optional.

The app should work with Laravel's normal cache/session/queue drivers.

---

# 72. DATABASE SEEDING

Create realistic development/demo seed data.

Include:

1 business
1 primary farm

Default roles/permissions

Admin user driven by environment values if possible

Several partners

Financial accounts:
Cash
Bank

Payment methods

Expense categories

Milk price rules

Example Mandali

Several vendors

Approximately 15-25 direct customers

Customer preferences with different reminder quantities

Some customer-specific price overrides

Some customer pauses

Approximately:
10+ cows
4-5 buffaloes

Various animal statuses

Several employees

Milk production records

Customer daily delivery/sales records

Vendor/Mandali sales

Payments

Expenses

Partner-funded expenses

Payroll/loan examples

Seed data must generate internally consistent records.

Do not expose insecure production credentials.

For development, document how to create/reset an admin password.

---

# 73. UI DEMO DATA VS REAL DATA

All dashboard figures must be generated from database queries.

Seeded demo data may populate those tables.

Never hard-code:

₹1,72,000
125 L
17 animals

into production Blade templates merely to make the UI appear complete.

---

# 74. TESTING

Create meaningful automated tests.

At minimum test:

Authentication

Inactive user cannot login

Authorization and permissions

Unauthorized route access

Language preference

Milk production create/update

Duplicate production prevention

Milk reconciliation

Over-allocation validation

Milk adjustments authorization

Customer registration

Customer pause behavior

Customer daily grid bulk save

Customer grid idempotency

Morning/evening sale creation

Removing/changing existing daily quantities

Customer-specific price resolution

Default price fallback

Historical price snapshot behavior

Missing price validation

Mandali manual rate

Mandali settlement

Vendor sales

Buyer payment

Buyer outstanding

Payment method record behavior

Expense creation

Multi-partner/multi-source expense funding

Funding allocation total validation

Financial ledger entries

Partner contributions

Partner ledger calculation

Animal purchase atomic flow

Animal purchase split funding

Animal lifecycle

Current lactating count

Sold/dead animal restrictions

Employee creation

Private document authorization

Payroll calculation

Partial/full salary payment

Employee loan balance

Payroll loan deduction

Recoverable employee charge

Audit logging

Export authorization

Report filter smoke tests

PWA manifest/service worker presence

Do not write superficial tests whose assertions do not validate business behavior.

---

# 75. DATABASE TESTING

Use proper test database handling.

Factories should create valid relationships.

Avoid depending on manually pre-existing local data.

Tests should be repeatable.

---

# 76. CODE QUALITY

Use:

Laravel conventions
PSR standards
Laravel Pint

Use meaningful class/method names.

Avoid giant controllers.

Avoid giant service classes when logical separation helps.

Avoid duplicated financial calculations.

Avoid magic strings where enums/constants are appropriate.

Use typed method signatures.

Use PHPDoc only where useful.

Do not fill code with unnecessary comments that repeat what the code says.

Document non-obvious business rules.

---

# 77. ACCESSIBILITY

Use:

proper form labels
keyboard navigation
adequate contrast
semantic tables
visible focus states
ARIA labels where necessary
clear validation feedback

Do not sacrifice usability for decorative design.

---

# 78. NOT REQUIRED IN V1

Do NOT build unless needed internally for architecture:

Animal-wise daily milk production

Automatic Mandali fat/SNF price formula

Vaccination management

Veterinary treatment module

Insemination/breeding management beyond pregnancy status

Feed inventory

Feed stock management

Customer delivery route optimization

Customer subscription automation

WhatsApp integration

SMS integration

Payment gateway

Actual UPI integration

Actual bank integration

Full double-entry accounting

GST accounting

Profit distribution between partners

Partner ownership percentages

SaaS/multi-tenancy

Complex offline sync

Native Android/iOS applications

Keep extension points where sensible, but do not inflate V1.

---

# 79. LOCAL DEVELOPMENT

The user will first run the application locally.

Support a straightforward native environment:

PHP
Composer
Node/NPM
MySQL

Document commands clearly.

Typical developer workflow should be similar to:

composer install

copy `.env.example` to `.env`

configure MySQL

php artisan key:generate

php artisan migrate --seed

npm install

npm run dev

php artisan serve

Adjust commands to the actual final project.

---

# 80. DOCKER DEVELOPMENT

Also provide an optional Docker setup.

Use practical services such as:

PHP-FPM
Nginx
MySQL 8.4
optional Mailpit

Do not make Redis mandatory unless actually needed.

Use PHP 8.4 for the Docker app image unless a better supported version is selected.

Provide:

docker compose configuration
Nginx development configuration if needed
documented commands

The application must not require Docker to work.

---

# 81. VPS DEPLOYMENT

The final application will be hosted on a VPS.

Create:

`docs/DEPLOYMENT.md`

Explain production setup for:

Nginx
PHP-FPM
MySQL 8.4
Composer
Node production build
storage permissions
environment variables
HTTPS
scheduler
queue worker if required
log permissions
Laravel optimization/cache commands
backups
deployment migration procedure

Do not embed provider-specific secrets.

---

# 82. BACKUPS

Document a basic production backup strategy covering:

MySQL database

Private uploaded documents

Environment/configuration responsibility

Do not implement an unnecessarily complex cloud backup product.

---

# 83. DOCUMENTATION TO CREATE

Create:

`README.md`

`docs/ARCHITECTURE.md`

`docs/DATABASE.md`

`docs/BUSINESS_RULES.md`

`docs/PERMISSIONS.md`

`docs/TESTING.md`

`docs/DEPLOYMENT.md`

`docs/PWA.md`

`docs/DECISIONS.md`

`docs/PROGRESS.md`

README should explain:

What product does

Requirements

Installation

Local setup

Docker setup

Initial admin creation

Build commands

Tests

Main modules

Deployment documentation location

---

# 84. ARCHITECTURE DOCUMENT

`docs/ARCHITECTURE.md` should document:

application structure
major modules
domain service approach
authentication
authorization
financial flow
milk flow
file storage
notifications
PWA
localization
report/export architecture

---

# 85. DATABASE DOCUMENT

`docs/DATABASE.md` should contain:

main tables
purpose
important columns
relationships
unique constraints
indexes
financial relationships

Include Mermaid ER diagrams if practical.

---

# 86. BUSINESS RULES DOCUMENT

Document important rules including:

Milk reconciliation

Customer Daily Entry behavior

Customer reminders are not defaults

Price resolution

Customer pauses

Sales vs payments

Outstanding calculation

Expense funding

Partner funding

Animal lifecycle

Current milking count

Employee salary

Employee loan

Recoverable employee expenses

Cancellation behavior

---

# 87. CHECKPOINT PHASES

Implement in the following order.

## PHASE 0 — Repository and Architecture

Inspect repository.

If empty:

create Laravel 13 application.

Verify:

PHP
Composer
Node
MySQL compatibility.

Set up:

Bootstrap
Bootstrap Icons
Vite
Chart.js
permissions package

Create documentation skeleton.

Create architecture/database plan.

Create `.env.example`.

Checkpoint.

---

## PHASE 1 — Foundation

Implement:

business
primary farm
authentication
password reset
users
roles
permissions
profile
language preference
English/Gujarati/Hindi localization
application shell
sidebar
top navigation
responsive layout
base settings
PWA manifest skeleton

Seed roles/permissions.

Write tests.

Checkpoint.

---

## PHASE 2 — Masters and Financial Foundation

Implement:

financial accounts
payment methods
partners
partner contributions
financial ledger
expense categories
expenses
funding allocations
split payment UI
sales channels
buyers
default milk prices
buyer-specific prices

Implement audit foundation.

Write tests.

Checkpoint.

---

## PHASE 3 — Milk Production and Reconciliation

Implement:

daily production
morning/evening
cow/buffalo
internal milk usage
milk adjustments
reconciliation engine
reconciliation UI

Implement permissions and tests.

Checkpoint.

---

## PHASE 4 — Direct Customers

Implement:

customer registration
milk preferences
quantity reminders
delivery note/caption
customer-specific pricing
customer pauses
Customer Daily Entry grid
Copy Previous Day
bulk transaction save
daily totals
customer ledger
customer payment recording
outstanding

This phase is high priority.

Test thoroughly.

Checkpoint.

---

## PHASE 5 — Mandali, Vendors and Other Buyers

Implement:

Mandali delivery
manual fat/SNF/rate
Mandali ledger
Mandali settlement
Vendor sales
Vendor ledger
Vendor payment
Generic future channel sale entry
buyer outstanding
payment receipts
financial account credits

Write tests.

Checkpoint.

---

## PHASE 6 — Animals

Implement:

animal profile
animal photo
animal lifecycle states
animal events
animal timeline
animal purchase workflow
multi-partner/payment-source split
automatic Animal Purchase expense
lactating/dry functionality
pregnancy
calving
sold/dead
dashboard animal queries

Write tests.

Checkpoint.

---

## PHASE 7 — Employees

Implement:

employee registration
private documents
salary/payroll
salary payments
split funding
employee loans/advances
loan transactions
employee grocery/accessory/benefit/recoverable expense handling
employee ledgers/views

Write tests.

Checkpoint.

---

## PHASE 8 — Dashboard and Notifications

Implement:

dashboard KPIs
milk overview
financial overview
animal overview
employee overview
Chart.js charts
in-app notifications
notification center
future SMS/WhatsApp abstractions

Optimize queries.

Write tests.

Checkpoint.

---

## PHASE 9 — Reports and Exports

Implement all required reports.

Add:

Excel
PDF
Print

Ensure:

permissions
filters
date ranges
correct totals

Test.

Checkpoint.

---

## PHASE 10 — Security, PWA and Production Hardening

Finish:

PWA service worker
installability
static caching
security headers
private file audit
validation review
authorization review
N+1 review
database indexing
error states
responsive QA
localization QA
production config documentation

Run full tests.

Checkpoint.

---

## PHASE 11 — FINAL QA

Run:

all automated tests
Pint
frontend production build
migration from empty database
fresh seed
critical workflow smoke tests

Manually verify these workflows:

### Workflow A

Enter today's morning/evening milk.

Confirm dashboard/reconciliation.

### Workflow B

Open Customer Daily Entry.

Customers automatically appear.

Reminder quantities appear as information.

Actual inputs are blank unless saved data exists.

Enter M/E quantities.

Save.

Confirm sales and customer ledger.

### Workflow C

Enter Mandali milk.

Enter fat and manual rate.

Record monthly settlement/payment.

Confirm outstanding and cash ledger.

### Workflow D

Buy cow.

₹90,000.

Partner A:
₹40,000.

Partner B:
₹30,000.

Bank:
₹20,000.

Confirm:

animal
purchase event
expense
funding split
partner ledgers
bank debit

without duplicate data.

### Workflow E

Mark animal Dry.

Confirm Current Lactating decreases.

Mark Lactation Started later.

Confirm count increases and timeline contains both events.

### Workflow F

Create feed expense funded by Partner + Cash.

Confirm one expense and correct ledgers.

### Workflow G

Generate payroll.

Give employee loan.

Deduct part through payroll.

Confirm loan balance and payroll/payment totals.

### Workflow H

Change user role.

Confirm server-side access immediately changes.

### Workflow I

Switch language.

Confirm UI persists selected language.

### Workflow J

Install as PWA.

Confirm manifest/service worker works and authenticated dynamic data is not dangerously cached for offline writes.

---

# 88. DEFINITION OF DONE

The application is not complete until:

- fresh install succeeds;
- migrations succeed;
- seed succeeds;
- authentication works;
- roles/permissions work;
- localization works;
- responsive UI works;
- production entry works;
- direct customer daily grid works;
- customer pauses work;
- customer pricing works;
- Mandali works;
- vendors work;
- buyer payments/outstanding work;
- expenses work;
- unlimited partner split funding works;
- account ledger works;
- partner ledger works;
- animal purchase works;
- animal lifecycle works;
- lactating count works;
- employees work;
- documents are private;
- payroll works;
- loans work;
- reports work;
- Excel/PDF/print work;
- dashboard uses real data;
- audit log works;
- PWA is installable;
- test suite passes;
- production build passes;
- Pint passes;
- documentation is complete.

No major module should contain fake placeholder UI.

No TODO should remain for a V1 requirement.

---

# 89. IMPORTANT PRODUCT PRINCIPLES

Remember these throughout development:

## Principle 1 — Enter Once, Update Everywhere

Never ask users to manually duplicate related information.

## Principle 2 — Historical Accuracy

Changing today's rate/status must not rewrite historical transactions.

## Principle 3 — Financial Traceability

Every rupee affecting business accounts or partner-funded spending should have a traceable source/reference.

## Principle 4 — Milk Traceability

Every litre should be understandable through production, sales, internal use or authorized adjustment.

## Principle 5 — Flexible Partners

Never assume exactly two partners.

## Principle 6 — Flexible Sales Channels

Never assume only three destinations forever.

## Principle 7 — Efficient Daily Customer Entry

Never force customer selection for each day's customer milk delivery.

## Principle 8 — Professional UI

This is a modern browser-based business dashboard, not an elderly-user kiosk interface.

## Principle 9 — Server Authority

JavaScript improves UX; Laravel validates and owns business truth.

## Principle 10 — Auditability

Important financial and operational corrections must be traceable.

---

# 90. IMPORTANT NON-NEGOTIABLE USER REQUIREMENTS

These requirements came directly from the product owner and must not be changed:

1. Laravel backend/application.
2. MySQL database.
3. Blade + JavaScript frontend.
4. Bootstrap UI.
5. Email/password login.
6. Single business currently.
7. Future multiple farm/location architecture.
8. Only one primary farm exposed currently.
9. English default.
10. Gujarati and Hindi supported.
11. Every user can select their own language.
12. INR currency.
13. Litres.
14. DD-MM-YYYY.
15. Asia/Kolkata.
16. Mandali rate is manually entered in V1.
17. Direct customers can have customer-specific rates.
18. Also maintain default milk prices.
19. Payment methods are recorded only.
20. No payment is processed through this platform.
21. Partners only contribute/pay business money in V1.
22. No partner profit-share/ownership system in V1.
23. Unlimited number of partners.
24. Animal V1 scope is core purchase/lactation-dry/pregnancy/calving/sold/dead.
25. Direct customer default quantities are reminders only.
26. Customer daily actual fields must NOT automatically prefill reminder quantities.
27. Customer pause periods are required.
28. Employee/doc uploads use private local storage initially.
29. Architecture must allow S3 later.
30. Reports require Excel, PDF and Print.
31. In-app notifications required.
32. WhatsApp/SMS only future extensibility.
33. Primary deployment later is VPS.
34. Development first happens locally.
35. Responsive web application required.
36. PWA required.
37. No offline-first complexity required.
38. Professional modern UI required.
39. Customer Daily Entry must show customers vertically with Morning and Evening fields and one day-level save workflow.
40. When animal purchase is paid by multiple partners/accounts, all contributions must be recorded in a single purchase workflow.

---

# 91. FIRST ACTIONS

Start now.

Do not immediately generate dozens of random CRUD files.

First:

1. inspect current repository/environment;
2. verify Laravel/PHP/Composer/Node situation;
3. create or initialize Laravel 13;
4. create `docs/ARCHITECTURE.md`;
5. create `docs/DATABASE.md`;
6. create `docs/DECISIONS.md`;
7. create `docs/PROGRESS.md`;
8. design migrations in dependency-safe order;
9. then begin Phase 1 implementation.

While designing the database, explicitly check that the model can correctly support:

- unlimited partners;
- multi-source expense payment;
- customer M/E daily grid;
- customer price history;
- milk reconciliation;
- animal state history;
- financial account cashbook;
- partner ledger;
- buyer outstanding;
- employee loans;
- future additional farms;
- future sales channels.

Do not optimize for speed of code generation at the expense of correct architecture.

Build a system that another professional Laravel team can maintain after you.