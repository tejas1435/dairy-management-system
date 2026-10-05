<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Foundation seed.
 *
 * Order matters: permissions before roles, the business before anything that
 * belongs to it, the business before the admin user. Every seeder here is
 * idempotent, so `db:seed` can be run repeatedly without duplicating data or
 * resetting settings an administrator has edited.
 *
 * Phase 2 adds finance and master data. There is deliberately no demo financial
 * transaction data: invented expenses and contributions would make a fresh
 * installation look like it had a financial history it does not have.
 *
 * Phase 3 does seed a few days of milk, which is a different case. Production,
 * usage and adjustments carry no money and no counterparty, so a sample week
 * makes the reconciliation screen explorable without asserting anything about the
 * books. It is internally consistent and leaves one shift unentered so the
 * "Production not entered" state is visible on a fresh installation.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Phase 1 — foundation
            PermissionSeeder::class,
            RoleSeeder::class,
            BusinessSeeder::class,
            AdminUserSeeder::class,

            // Phase 2 — masters and financial foundation
            PaymentMethodSeeder::class,
            ExpenseCategorySeeder::class,
            SalesChannelSeeder::class,
            FinanceSeeder::class,
            MilkPriceSeeder::class,

            // Phase 3 — milk production and reconciliation
            MilkProductionSeeder::class,

            // Phase 4 — direct customers, then their deliveries and receipts. The
            // sales seeder runs last because it writes through the real domain
            // action and needs the customers, prices, accounts and production that
            // everything above it puts in place.
            DirectCustomerSeeder::class,
            CustomerSalesSeeder::class,

            /*
             * Phase 5 — Mandali, vendors and other channels. Runs after the customer
             * sales because its quantities are chosen to fit inside whatever those
             * leave of the seeded production, and because both write through the real
             * domain actions, which check availability as they go.
             */
            ChannelSaleSeeder::class,
        ]);
    }
}
