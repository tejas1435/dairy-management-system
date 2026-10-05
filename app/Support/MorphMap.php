<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Business;
use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use App\Models\BuyerPayment;
use App\Models\BuyerPriceRule;
use App\Models\BuyerSettlement;
use App\Models\CustomerPause;
use App\Models\CustomerPreference;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Farm;
use App\Models\FinancialAccount;
use App\Models\MilkAdjustment;
use App\Models\MilkPriceRule;
use App\Models\MilkProduction;
use App\Models\MilkSale;
use App\Models\MilkUsage;
use App\Models\Partner;
use App\Models\PartnerContribution;
use App\Models\PaymentMethod;
use App\Models\SalesChannel;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Stable aliases for polymorphic relations (docs/DECISIONS.md D10).
 *
 * Polymorphic columns store these short strings, never a fully-qualified PHP
 * class name, so classes can be renamed or moved without a data migration and
 * an `auditable_type` of "expense" stays readable years later.
 *
 * The map is *enforced*, so a polymorphic relation on an unmapped model raises
 * an error rather than quietly writing a class name. It grows one phase at a
 * time: an alias is added only when its model exists.
 */
final class MorphMap
{
    /**
     * @return array<string, class-string>
     */
    public static function map(): array
    {
        return [
            // Phase 1
            'user' => User::class,
            'business' => Business::class,
            'farm' => Farm::class,

            /*
             * Spatie's models are mapped too. The audit log records role and
             * permission changes, and the map is enforced, so an unmapped class
             * would throw the moment somebody edits a role.
             */
            'role' => Role::class,
            'permission' => Permission::class,

            // Phase 2 — finance
            'financial_account' => FinancialAccount::class,
            'payment_method' => PaymentMethod::class,
            'partner' => Partner::class,
            'partner_contribution' => PartnerContribution::class,
            'expense_category' => ExpenseCategory::class,
            'expense' => Expense::class,

            // Phase 2 — masters and pricing
            'sales_channel' => SalesChannel::class,
            'buyer' => Buyer::class,
            'milk_price_rule' => MilkPriceRule::class,
            'buyer_price_rule' => BuyerPriceRule::class,

            // Phase 3 — milk production and reconciliation
            'milk_production' => MilkProduction::class,
            'milk_usage' => MilkUsage::class,
            'milk_adjustment' => MilkAdjustment::class,

            // Phase 4 — direct customers
            'customer_preference' => CustomerPreference::class,
            'customer_pause' => CustomerPause::class,
            'milk_sale' => MilkSale::class,
            'buyer_payment' => BuyerPayment::class,

            // Phase 5 — Mandali, vendors and buyer settlement
            'buyer_settlement' => BuyerSettlement::class,
            'buyer_balance_adjustment' => BuyerBalanceAdjustment::class,
        ];
    }

    /**
     * Aliases valid as a funding source. A source either holds business money
     * or is a partner spending their own.
     *
     * @return array<int, string>
     */
    public static function fundingSources(): array
    {
        return ['partner', 'financial_account'];
    }

    /**
     * Aliases valid as a funding payable.
     *
     * Phase 2 funds expenses only. Phase 6 adds animal purchases and Phase 7
     * adds payroll payments and loan disbursements, through this same table.
     *
     * @return array<int, string>
     */
    public static function fundingPayables(): array
    {
        return ['expense'];
    }

    public static function aliasFor(object|string $model): ?string
    {
        $class = is_object($model) ? $model::class : $model;

        return array_search($class, self::map(), true) ?: null;
    }
}
