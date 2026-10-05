<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The seeded roles and their starting permissions.
 *
 * These are **editable defaults**, not business rules. An administrator can
 * change any role's permissions at runtime and the application will honour it
 * immediately; nothing in the codebase checks a role name to make a decision.
 *
 * The matrix is deliberately conservative. A role is not given override, cancel,
 * delete or manage permissions because its name sounds senior — each grant has
 * to be justified by the job the role actually does. Widening a role is a
 * one-click change in the UI; discovering that Data Operator could cancel sales
 * is not.
 *
 * Seeded role names are protected from rename and deletion so the seeder stays
 * idempotent and so the Super Admin role cannot be removed, locking everyone
 * out. See docs/DECISIONS.md D15.
 */
final class RoleCatalog
{
    public const SUPER_ADMIN = 'Super Admin';

    public const OWNER = 'Owner';

    public const PARTNER = 'Partner';

    public const MANAGER = 'Manager';

    public const ACCOUNTANT = 'Accountant';

    public const DATA_OPERATOR = 'Data Operator';

    public const VIEWER = 'Viewer';

    /**
     * Roles that may not be renamed or deleted.
     *
     * @return array<int, string>
     */
    public static function systemRoles(): array
    {
        return [
            self::SUPER_ADMIN,
            self::OWNER,
            self::PARTNER,
            self::MANAGER,
            self::ACCOUNTANT,
            self::DATA_OPERATOR,
            self::VIEWER,
        ];
    }

    public static function isSystem(string $role): bool
    {
        return in_array($role, self::systemRoles(), true);
    }

    /**
     * Super Admin always holds every permission in the catalogue, including
     * ones added by later phases. Its permission set is re-synced on every seed
     * rather than stored as a fixed list.
     */
    public static function isAllPermissions(string $role): bool
    {
        return $role === self::SUPER_ADMIN;
    }

    /**
     * The starting permission matrix.
     *
     * Super Admin is absent because it receives PermissionCatalog::all().
     *
     * @return array<string, array<int, string>>
     */
    public static function matrix(): array
    {
        return [
            /*
             * Owner: full operational and financial authority over the business,
             * but not account administration. Excludes user, role and permission
             * management so that granting business authority does not also grant
             * the ability to hand out authority. Excludes milk.production.delete
             * because production is corrected by updating it, not erasing it --
             * and nothing in the application checks that permission at all
             * (docs/DECISIONS.md D33).
             *
             * Being a diff against the catalogue, this role picks up permissions
             * added by later phases automatically, which is intended: the Owner is
             * defined as "everything except the four listed".
             */
            self::OWNER => array_values(array_diff(PermissionCatalog::all(), [
                'user.manage',
                'role.manage',
                'permission.manage',
                'milk.production.delete',
            ])),

            /*
             * Partner: sees the business, including the financial picture and
             * their own ledger, and changes nothing. Partners fund the business;
             * recording those funds is the Accountant's job.
             */
            self::PARTNER => [
                'dashboard.view',
                'milk.production.view',
                'milk.customer_delivery.view',
                'milk.sale.view',
                'milk.reconciliation.view',
                'milk.usage.view',
                'customer.view',
                'mandali.view',
                'vendor.view',
                'animal.view',
                'expense.view',
                'finance.view',
                'finance.payment.view',
                'finance.cashbook.view',
                'partner.view',
                'partner.finance.view',
                'employee.view',
                'report.operational.view',
                'report.financial.view',
            ],

            /*
             * Manager: runs the farm day to day. Records milk, customers,
             * animals and staff. Touches no money: no payments, settlements,
             * expenses, purchases or ledgers. Also no rate override, no milk
             * adjustment and no cancellation — those are the three ways to
             * depart from recorded truth and they stay with the Owner.
             */
            self::MANAGER => [
                'dashboard.view',
                'milk.production.view',
                'milk.production.create',
                'milk.production.update',
                'milk.customer_delivery.view',
                'milk.customer_delivery.create',
                'milk.customer_delivery.update',
                'milk.sale.view',
                'milk.sale.create',
                'milk.sale.update',
                'milk.reconciliation.view',
                'milk.usage.view',
                // Recording calf feeding, home use and wastage is part of running
                // the farm. Cancelling one is not: that is a correction, and
                // corrections stay with the Owner, like the rest of this role's
                // exclusions.
                'milk.usage.create',
                'customer.view',
                'customer.create',
                'customer.update',
                'mandali.view',
                'mandali.create',
                'mandali.update',
                'vendor.view',
                'vendor.create',
                'vendor.update',
                'animal.view',
                'animal.create',
                'animal.update',
                'animal.event.create',
                'employee.view',
                'employee.create',
                'employee.update',
                'report.operational.view',
            ],

            /*
             * Accountant: the money. Payments in, expenses out, settlements,
             * payroll and loans, plus financial reporting. Excludes
             * expense.cancel and finance.account.manage: reversing a recorded
             * expense and creating or editing the accounts themselves are
             * owner-level acts. Excludes employee documents, which are personal
             * records rather than financial ones.
             */
            self::ACCOUNTANT => [
                'dashboard.view',
                'milk.production.view',
                'milk.customer_delivery.view',
                'milk.sale.view',
                'milk.reconciliation.view',
                'milk.usage.view',
                'customer.view',
                'customer.payment.create',
                'mandali.view',
                'mandali.payment.create',
                'mandali.settlement.manage',
                'vendor.view',
                'vendor.payment.create',
                'animal.view',
                'expense.view',
                'expense.create',
                'expense.update',
                'finance.view',
                'finance.payment.view',
                'finance.payment.create',
                'finance.cashbook.view',
                'partner.view',
                'partner.finance.view',
                'partner.contribution.create',
                'employee.view',
                'employee.salary.view',
                'employee.salary.manage',
                'employee.loan.view',
                'employee.loan.manage',
                'report.operational.view',
                'report.financial.view',
                'report.export',
            ],

            /*
             * Data Operator: the daily entry screens and nothing else. Can
             * record production and deliveries and read the buyer lists those
             * screens need. No money, no masters, no corrections.
             */
            self::DATA_OPERATOR => [
                'dashboard.view',
                'milk.production.view',
                'milk.production.create',
                'milk.production.update',
                'milk.customer_delivery.view',
                'milk.customer_delivery.create',
                'milk.customer_delivery.update',
                'milk.sale.view',
                'milk.sale.create',
                'milk.reconciliation.view',
                'milk.usage.view',
                // Internal usage is a daily entry screen, which is this role's
                // whole job. No cancellation, no adjustment.
                'milk.usage.create',
                'customer.view',
                'mandali.view',
                'vendor.view',
            ],

            /*
             * Viewer: read-only. Operational visibility only — no financial
             * figures, no employee documents, salaries or loans, and no audit
             * log, because "read-only" is not the same as "may see everything".
             */
            self::VIEWER => [
                'dashboard.view',
                'milk.production.view',
                'milk.customer_delivery.view',
                'milk.sale.view',
                'milk.reconciliation.view',
                'milk.usage.view',
                'customer.view',
                'mandali.view',
                'vendor.view',
                'animal.view',
                'expense.view',
                'partner.view',
                'employee.view',
                'report.operational.view',
            ],
        ];
    }

    /**
     * Permissions for a role, resolving Super Admin to the whole catalogue.
     *
     * @return array<int, string>
     */
    public static function permissionsFor(string $role): array
    {
        if (self::isAllPermissions($role)) {
            return PermissionCatalog::all();
        }

        return self::matrix()[$role] ?? [];
    }
}
