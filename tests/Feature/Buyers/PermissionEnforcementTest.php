<?php

use App\Support\BuyerPermissions;
use App\Support\PermissionCatalog;

/*
 * Every permission the catalogue grants is enforced somewhere.
 *
 * A permission nobody checks is worse than a missing one: it appears on the role
 * screen, an administrator grants or withholds it believing that decision has an
 * effect, and nothing changes. Phase 5 added six of them, which is the point at which
 * this is worth asserting rather than reviewing by eye.
 *
 * Permissions for phases not yet built are listed explicitly below, so a forgotten
 * check in the phase that arrives next fails this test instead of passing unnoticed.
 */

/**
 * Permissions that are deliberately seeded ahead of the feature that uses them.
 *
 * Seeding them early keeps the role matrix in `docs/PERMISSIONS.md` stable across
 * phases: an administrator configures a role once rather than revisiting it whenever
 * a phase lands. Each entry is removed from this list by the phase that enforces it.
 *
 * @return array<int, string>
 */
function permissionsAwaitingTheirPhase(): array
{
    return [
        // Phase 6: animals.
        'animal.view',
        'animal.create',
        'animal.update',
        'animal.event.create',
        'animal.purchase.create',

        // Phase 7: employees and payroll.
        'employee.view',
        'employee.create',
        'employee.update',
        'employee.document.view',
        'employee.document.manage',
        'employee.salary.view',
        'employee.salary.manage',
        'employee.loan.view',
        'employee.loan.manage',

        // Phase 9: reporting and export.
        'report.operational.view',
        'report.financial.view',
        'report.export',

        /*
         * Outgoing payments — salaries, suppliers — which arrive with the phases that
         * create something to pay. Receipts from buyers are governed by the channel
         * families instead, so these two are not a second way to take money in.
         */
        'finance.payment.view',
        'finance.payment.create',

        /*
         * There is no screen for editing permissions themselves: roles carry them,
         * and `role.manage` is what guards that. Kept in the catalogue because the
         * role matrix in docs/PERMISSIONS.md is stable across phases, and a
         * permission editor is a plausible later addition.
         */
        'permission.manage',

        /*
         * Phase 4 reserved this for the Customer Daily Entry grid, where a rate may
         * not yet be departed from at all (D45). It is deliberately *not* the one
         * that authorises a vendor override — that is `milk.sale.override_rate`, and
         * the two are kept apart so neither grants the other.
         */
        'milk.customer_delivery.override_rate',
    ];
}

/**
 * Where a permission may be enforced: application code, routes and views.
 *
 * The catalogue and the seeders are excluded on purpose. A permission that appears
 * only in the list of permissions is exactly what this test is looking for.
 */
function enforcementHaystack(): string
{
    static $haystack = null;

    if ($haystack !== null) {
        return $haystack;
    }

    $haystack = '';

    foreach ([app_path(), base_path('routes'), resource_path('views')] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                continue;
            }

            if (str_contains($file->getPathname(), 'PermissionCatalog.php')
                || str_contains($file->getPathname(), 'RoleCatalog.php')) {
                continue;
            }

            $haystack .= file_get_contents($file->getPathname());
        }
    }

    return $haystack;
}

/**
 * The catalogue constant names that hold each permission string.
 *
 * Most checks name the permission directly; the administration and dashboard ones go
 * through `PermissionCatalog::ROLE_MANAGE` and friends, so the constant reference is
 * the enforcement and the literal never appears outside the catalogue.
 *
 * @return array<string, string>
 */
function permissionConstantNames(): array
{
    $names = [];

    foreach ((new ReflectionClass(PermissionCatalog::class))->getConstants() as $name => $value) {
        if (is_string($value)) {
            $names[$value] = $name;
        }
    }

    return $names;
}

test('every permission in the catalogue is enforced somewhere, or is waiting for its phase', function () {
    $haystack = enforcementHaystack();
    $constants = permissionConstantNames();
    $families = array_values(BuyerPermissions::families());
    $families[] = BuyerPermissions::CUSTOM_FAMILY;

    $unenforced = [];

    foreach (PermissionCatalog::all() as $permission) {
        if (in_array($permission, permissionsAwaitingTheirPhase(), true)) {
            continue;
        }

        if (str_contains($haystack, $permission)) {
            continue;
        }

        if (isset($constants[$permission])
            && str_contains($haystack, 'PermissionCatalog::'.$constants[$permission])) {
            continue;
        }

        /*
         * A buyer permission is composed at runtime — `$family.'.payment.create'` —
         * because one `buyers` table serves every channel (D26). The literal never
         * appears, so the suffix standing alone counts as the check.
         */
        [$family, $suffix] = array_pad(explode('.', $permission, 2), 2, '');

        if (in_array($family, $families, true) && str_contains($haystack, "'.".$suffix."'")) {
            continue;
        }

        $unenforced[] = $permission;
    }

    expect($unenforced)->toBe([], 'These permissions are granted but never checked: '.implode(', ', $unenforced));
});

test('the permissions waiting for a phase really are absent from the application', function () {
    $haystack = enforcementHaystack();
    $constants = permissionConstantNames();

    foreach (permissionsAwaitingTheirPhase() as $permission) {
        if (isset($constants[$permission])) {
            expect(str_contains($haystack, 'PermissionCatalog::'.$constants[$permission]))->toBeFalse(
                $permission.' is now enforced through its constant, so remove it from the waiting list.'
            );
        }

        // If one of these starts being enforced, it belongs in the catalogue test
        // above rather than on the waiting list.
        expect(str_contains($haystack, $permission))->toBeFalse(
            $permission.' is now enforced, so remove it from permissionsAwaitingTheirPhase().'
        );
    }
});

test('the six Phase 5 permissions are each enforced by name', function (string $permission) {
    expect(PermissionCatalog::all())->toContain($permission);

    expect(str_contains(enforcementHaystack(), $permission))->toBeTrue(
        $permission.' is in the catalogue but nothing checks it.'
    );
})->with([
    'mandali.settlement.manage',
    'milk.sale.override_rate',
    'milk.sale.cancel',
    'mandali.payment.create',
    'mandali.payment.cancel',
    'vendor.payment.create',
    'vendor.payment.cancel',
]);
