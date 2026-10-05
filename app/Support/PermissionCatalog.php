<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The application's permission identifiers.
 *
 * These strings are contracts: routes, policies, middleware and Blade all
 * reference them by name. They live in code rather than only in the database so
 * that renaming one is a deliberate code change reviewed alongside the checks
 * that depend on it, instead of an edit in an admin screen that silently breaks
 * authorisation.
 *
 * Role-to-permission assignments remain fully editable at runtime, and
 * administrators may add their own permissions. What is protected is the
 * identity of a seeded permission. See docs/DECISIONS.md D15.
 */
final class PermissionCatalog
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const USER_MANAGE = 'user.manage';

    public const ROLE_MANAGE = 'role.manage';

    public const PERMISSION_MANAGE = 'permission.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const AUDIT_VIEW = 'audit.view';

    /**
     * Every seeded permission, grouped for display in the role editor.
     *
     * @return array<string, array<int, string>>
     */
    public static function groups(): array
    {
        return [
            'dashboard' => [
                'dashboard.view',
            ],
            'milk' => [
                'milk.production.view',
                'milk.production.create',
                'milk.production.update',
                'milk.production.delete',
                'milk.customer_delivery.view',
                'milk.customer_delivery.create',
                'milk.customer_delivery.update',
                'milk.customer_delivery.override_rate',
                'milk.sale.view',
                'milk.sale.create',
                'milk.sale.update',
                'milk.sale.cancel',

                /*
                 * Departing from the rate the resolver returned on a vendor or
                 * generic sale. Added in Phase 5.
                 *
                 * Deliberately NOT `milk.customer_delivery.override_rate`, which
                 * belongs to the daily entry grid and stays unused there until
                 * somebody designs an override workflow for it. Borrowing it would
                 * have made one permission mean two different capabilities in two
                 * different screens, and granting it for vendor work would have
                 * quietly unlocked customer re-pricing the day that screen appears.
                 *
                 * A Mandali rate is not an override: it is typed by hand every time,
                 * because that is the workflow the specification describes.
                 */
                'milk.sale.override_rate',
                'milk.reconciliation.view',

                /*
                 * Internal usage. Added in Phase 3: the specification requires
                 * the workflow (section 15) but its original catalogue had no
                 * permission for it, and borrowing an unrelated one would have
                 * made "record production" quietly mean "record wastage too".
                 * There is no milk.usage.update, because a recorded usage is
                 * corrected by cancelling it and entering the right figure.
                 * See docs/DECISIONS.md D34.
                 */
                'milk.usage.view',
                'milk.usage.create',
                'milk.usage.cancel',

                /*
                 * Adjustments are the authorised exception to recorded
                 * production. Cancelling one is separate from creating one
                 * because withdrawing milk that has already been distributed is
                 * a different decision from granting it.
                 */
                'milk.adjustment.create',
                'milk.adjustment.cancel',
            ],
            'customers' => [
                'customer.view',
                'customer.create',
                'customer.update',
                'customer.archive',
                'customer.payment.create',

                /*
                 * Added in Phase 4. Cancelling a recorded payment reverses money in
                 * the ledger and raises the customer's outstanding again, which is a
                 * different decision from recording the receipt — so recording one
                 * must not imply withdrawing one. The same split the milk module
                 * already makes between usage.create and usage.cancel.
                 * See docs/DECISIONS.md D40.
                 */
                'customer.payment.cancel',
            ],
            'mandali' => [
                'mandali.view',
                'mandali.create',
                'mandali.update',
                'mandali.payment.create',

                /*
                 * Added in Phase 5, for the same reason as
                 * `customer.payment.cancel` in D40: withdrawing a recorded receipt
                 * reverses money in the ledger and raises a balance again, which is
                 * not what "may record a receipt" should imply. Without it
                 * `BuyerPolicy::cancelPayment()` would have had to pass for a
                 * Mandali on no permission at all.
                 */
                'mandali.payment.cancel',

                'mandali.settlement.manage',
            ],
            'vendors' => [
                'vendor.view',
                'vendor.create',
                'vendor.update',
                'vendor.payment.create',

                // Same reasoning as the Mandali cancel permission above.
                'vendor.payment.cancel',
            ],
            'animals' => [
                'animal.view',
                'animal.create',
                'animal.update',
                'animal.event.create',
                'animal.purchase.create',
            ],
            'expenses' => [
                'expense.view',
                'expense.create',
                'expense.update',
                'expense.cancel',
            ],
            'finance' => [
                'finance.view',
                'finance.account.manage',
                'finance.payment.view',
                'finance.payment.create',
                'finance.cashbook.view',
            ],
            'partners' => [
                'partner.view',
                'partner.create',
                'partner.update',
                'partner.finance.view',
                'partner.contribution.create',
            ],
            'employees' => [
                'employee.view',
                'employee.create',
                'employee.update',
                'employee.document.view',
                'employee.document.manage',
                'employee.salary.view',
                'employee.salary.manage',
                'employee.loan.view',
                'employee.loan.manage',
            ],
            'reports' => [
                'report.operational.view',
                'report.financial.view',
                'report.export',
            ],
            'administration' => [
                'user.manage',
                'role.manage',
                'permission.manage',
            ],
            'settings' => [
                'settings.manage',
            ],
            'audit' => [
                'audit.view',
            ],
        ];
    }

    /**
     * Flat list of every seeded permission.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /** Whether the given name is a seeded system permission. */
    public static function isSystem(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /** The group a permission belongs to, or null for an administrator-added one. */
    public static function groupOf(string $permission): ?string
    {
        foreach (self::groups() as $group => $permissions) {
            if (in_array($permission, $permissions, true)) {
                return $group;
            }
        }

        return null;
    }
}
