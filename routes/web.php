<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserStatusController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\Buyers\BuyerPaymentController;
use App\Http\Controllers\Buyers\BuyerSettlementController;
use App\Http\Controllers\Buyers\MandaliController;
use App\Http\Controllers\Buyers\OtherBuyerController;
use App\Http\Controllers\Buyers\VendorController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerPauseController;
use App\Http\Controllers\Customers\CustomerPaymentController;
use App\Http\Controllers\Customers\CustomerPriceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Finance\CashbookController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\FinancialAccountController;
use App\Http\Controllers\Finance\PartnerContributionController;
use App\Http\Controllers\Finance\PartnerController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Milk\CustomerDailyEntryController;
use App\Http\Controllers\Milk\MandaliDeliveryController;
use App\Http\Controllers\Milk\MilkAdjustmentController;
use App\Http\Controllers\Milk\MilkUsageController;
use App\Http\Controllers\Milk\OtherSaleController;
use App\Http\Controllers\Milk\ProductionController;
use App\Http\Controllers\Milk\ReconciliationController;
use App\Http\Controllers\Milk\VendorSaleController;
use App\Http\Controllers\Profile\PasswordController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\PwaManifestController;
use App\Http\Controllers\Settings\BusinessController;
use App\Http\Controllers\Settings\ExpenseCategoryController;
use App\Http\Controllers\Settings\FarmController;
use App\Http\Controllers\Settings\MilkPriceController;
use App\Http\Controllers\Settings\PaymentMethodController;
use App\Http\Controllers\Settings\SalesChannelController;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Route;

/*
 * There is deliberately no registration route. Accounts are created by an
 * administrator through Administration -> Users.
 *
 * Every authenticated route carries both 'auth' and 'active': 'auth'
 * establishes who is asking, 'active' ends the session of anyone since
 * deactivated. Permission checks use the 'can' middleware, so a route is
 * protected by the server even when the sidebar has already hidden its link.
 * Buyer routes are the one exception: their permission depends on the record's
 * sales channel, so BuyerPolicy decides and the controller calls authorize().
 */

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))
    ->name('home');

Route::get('manifest.webmanifest', PwaManifestController::class)->name('pwa.manifest');

// Language switching is available to guests so the sign-in screens can be read.
Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('dashboard', DashboardController::class)
        ->middleware('can:'.PermissionCatalog::DASHBOARD_VIEW)
        ->name('dashboard');

    // Self-service: available to every signed-in user, no permission required.
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [PasswordController::class, 'update'])->name('profile.password.update');

    /*
     * Direct customers.
     *
     * Authorisation is per record through BuyerPolicy, which maps the row's sales
     * channel to its permission family, so these carry no 'can' middleware. Each
     * action also resolves the record as a direct customer first: `buyers` holds
     * Mandalis and vendors too, and an id in a URL proves only that a row exists.
     *
     * There is deliberately no Customer Daily Entry route yet. The grid is Pass 2;
     * Pass 1 built the domain it will sit on.
     */
    Route::prefix('customers')->name('customers.')->group(function (): void {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('create', [CustomerController::class, 'create'])->name('create');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::get('{customer}', [CustomerController::class, 'show'])->name('show');
        Route::get('{customer}/edit', [CustomerController::class, 'edit'])->name('edit');
        Route::put('{customer}', [CustomerController::class, 'update'])->name('update');
        Route::put('{customer}/status', [CustomerController::class, 'updateStatus'])->name('status.update');

        // Pauses, payments and price overrides live inside the profile rather than
        // as their own menu entries.
        Route::post('{customer}/pauses', [CustomerPauseController::class, 'store'])->name('pauses.store');
        Route::put('pauses/{pause}/cancel', [CustomerPauseController::class, 'cancel'])->name('pauses.cancel');

        Route::post('{customer}/payments', [CustomerPaymentController::class, 'store'])->name('payments.store');
        Route::put('payments/{payment}/cancel', [CustomerPaymentController::class, 'cancel'])
            ->name('payments.cancel');

        Route::post('{customer}/prices', [CustomerPriceController::class, 'store'])->name('prices.store');
        Route::delete('{customer}/prices/{priceRule}', [CustomerPriceController::class, 'destroy'])
            ->name('prices.destroy');
    });

    /*
     * Milk
     *
     * Production is entered and corrected, never deleted: there is deliberately
     * no destructive route here, and nothing checks milk.production.delete
     * (docs/DECISIONS.md D33). Usage and adjustments are cancelled with a reason.
     */
    Route::prefix('milk')->name('milk.')->group(function (): void {
        Route::get('production', [ProductionController::class, 'index'])
            ->middleware('can:milk.production.view')->name('production.index');
        Route::post('production', [ProductionController::class, 'store'])
            ->middleware('can:milk.production.create')->name('production.store');

        /*
         * Customer Daily Entry. The route permission is only `view`: a single save
         * can mix new deliveries with corrections to existing ones, and those need
         * different permissions, so the real check is made per operation inside
         * SaveCustomerDailyDeliveries. Gating the route on `create` would lock out
         * someone allowed to correct the day but not to add to it.
         *
         * `store` takes a JSON body rather than form fields (D5), and
         * `copy-previous` is a GET because it reads yesterday and writes nothing.
         */
        Route::get('customer-entry', [CustomerDailyEntryController::class, 'index'])
            ->middleware('can:milk.customer_delivery.view')->name('customer-entry.index');
        Route::post('customer-entry', [CustomerDailyEntryController::class, 'store'])
            ->middleware('can:milk.customer_delivery.view')->name('customer-entry.store');
        Route::get('customer-entry/copy-previous', [CustomerDailyEntryController::class, 'copyPrevious'])
            ->middleware('can:milk.customer_delivery.view')->name('customer-entry.copy-previous');

        Route::get('usage', [MilkUsageController::class, 'index'])
            ->middleware('can:milk.usage.view')->name('usage.index');
        Route::post('usage', [MilkUsageController::class, 'store'])
            ->middleware('can:milk.usage.create')->name('usage.store');
        Route::put('usage/{usage}/cancel', [MilkUsageController::class, 'cancel'])
            ->middleware('can:milk.usage.cancel')->name('usage.cancel');

        /*
         * Adjustments: the authorised exception to recorded production. Reading
         * the list needs the same permission as creating one, because the list is
         * the record of exceptions somebody has claimed.
         */
        Route::get('adjustments', [MilkAdjustmentController::class, 'index'])
            ->middleware('can:milk.adjustment.create')->name('adjustments.index');
        Route::post('adjustments', [MilkAdjustmentController::class, 'store'])
            ->middleware('can:milk.adjustment.create')->name('adjustments.store');
        Route::put('adjustments/{adjustment}/cancel', [MilkAdjustmentController::class, 'cancel'])
            ->middleware('can:milk.adjustment.cancel')->name('adjustments.cancel');

        Route::get('reconciliation', ReconciliationController::class)
            ->middleware('can:milk.reconciliation.view')->name('reconciliation');

        /*
         * Phase 5 sale entry: Mandali collections, vendor sales and the generic form
         * for administrator-created channels.
         *
         * Three routes groups over one controller hierarchy, because the three differ
         * only in how the rate is decided. `milk.sale.*` gates them rather than the
         * buyer families: these are milk-distribution screens, and the buyer's own
         * channel permission is checked per record by BuyerPolicy. The rate-override
         * permission is checked inside the action, not here, because only some saves
         * are overrides.
         */
        Route::prefix('mandali-deliveries')->name('mandali-deliveries.')->group(function (): void {
            Route::get('/', [MandaliDeliveryController::class, 'index'])
                ->middleware('can:milk.sale.view')->name('index');
            Route::get('create', [MandaliDeliveryController::class, 'create'])
                ->middleware('can:milk.sale.create')->name('create');
            Route::post('/', [MandaliDeliveryController::class, 'store'])
                ->middleware('can:milk.sale.create')->name('store');
            Route::get('{sale}/edit', [MandaliDeliveryController::class, 'edit'])
                ->middleware('can:milk.sale.update')->name('edit');
            Route::put('{sale}', [MandaliDeliveryController::class, 'update'])
                ->middleware('can:milk.sale.update')->name('update');
            Route::put('{sale}/cancel', [MandaliDeliveryController::class, 'cancel'])
                ->middleware('can:milk.sale.cancel')->name('cancel');
            // The only way to a collection slip: the file is on a private disk with a
            // randomised name and no URL of its own.
            Route::get('{sale}/slip', [MandaliDeliveryController::class, 'slip'])
                ->middleware('can:milk.sale.view')->name('slip');
        });

        Route::prefix('vendor-sales')->name('vendor-sales.')->group(function (): void {
            Route::get('/', [VendorSaleController::class, 'index'])
                ->middleware('can:milk.sale.view')->name('index');
            Route::get('create', [VendorSaleController::class, 'create'])
                ->middleware('can:milk.sale.create')->name('create');
            Route::post('/', [VendorSaleController::class, 'store'])
                ->middleware('can:milk.sale.create')->name('store');
            Route::get('{sale}/edit', [VendorSaleController::class, 'edit'])
                ->middleware('can:milk.sale.update')->name('edit');
            Route::put('{sale}', [VendorSaleController::class, 'update'])
                ->middleware('can:milk.sale.update')->name('update');
            Route::put('{sale}/cancel', [VendorSaleController::class, 'cancel'])
                ->middleware('can:milk.sale.cancel')->name('cancel');
        });

        Route::prefix('other-sales')->name('other-sales.')->group(function (): void {
            Route::get('/', [OtherSaleController::class, 'index'])
                ->middleware('can:milk.sale.view')->name('index');
            Route::get('create', [OtherSaleController::class, 'create'])
                ->middleware('can:milk.sale.create')->name('create');
            Route::post('/', [OtherSaleController::class, 'store'])
                ->middleware('can:milk.sale.create')->name('store');
            Route::get('{sale}/edit', [OtherSaleController::class, 'edit'])
                ->middleware('can:milk.sale.update')->name('edit');
            Route::put('{sale}', [OtherSaleController::class, 'update'])
                ->middleware('can:milk.sale.update')->name('update');
            Route::put('{sale}/cancel', [OtherSaleController::class, 'cancel'])
                ->middleware('can:milk.sale.cancel')->name('cancel');
        });
    });

    /*
     * Mandali and vendor masters.
     *
     * Channel-scoped lists and a trade profile carrying the ledger, the receipts and
     * (for a Mandali) the settlement history. Create and edit deliberately redirect
     * to the Phase 2 buyer form with the channel pre-selected rather than duplicating
     * it — see ChannelBuyerController.
     *
     * No `can` middleware: BuyerPolicy resolves the permission from the buyer's own
     * channel, because `buyers` holds every channel in one table.
     */
    Route::prefix('mandalis')->name('mandalis.')->group(function (): void {
        Route::get('/', [MandaliController::class, 'index'])->name('index');
        Route::get('{buyer}', [MandaliController::class, 'show'])->name('show');
        Route::get('{buyer}/statement', [MandaliController::class, 'statement'])->name('statement');

        Route::prefix('{mandali}/settlements')->name('settlements.')->group(function (): void {
            Route::get('/', [BuyerSettlementController::class, 'index'])->name('index');
            Route::get('create', [BuyerSettlementController::class, 'create'])->name('create');
            Route::post('/', [BuyerSettlementController::class, 'store'])->name('store');
            Route::get('{settlement}', [BuyerSettlementController::class, 'show'])->name('show');
            Route::put('{settlement}', [BuyerSettlementController::class, 'update'])->name('update');
            Route::put('{settlement}/finalize', [BuyerSettlementController::class, 'finalize'])->name('finalize');
            Route::put('{settlement}/cancel', [BuyerSettlementController::class, 'cancel'])->name('cancel');
        });
    });

    Route::prefix('vendors')->name('vendors.')->group(function (): void {
        Route::get('/', [VendorController::class, 'index'])->name('index');
        Route::get('{buyer}', [VendorController::class, 'show'])->name('show');
        Route::get('{buyer}/statement', [VendorController::class, 'statement'])->name('statement');
    });

    /*
     * Buyers in administrator-created channels, which share one list because there is
     * no fixed set of them. The same three screens, so a receivable raised by the
     * generic sale form can be seen and settled.
     */
    Route::prefix('other-buyers')->name('other-buyers.')->group(function (): void {
        Route::get('/', [OtherBuyerController::class, 'index'])->name('index');
        Route::get('{buyer}', [OtherBuyerController::class, 'show'])->name('show');
        Route::get('{buyer}/statement', [OtherBuyerController::class, 'statement'])->name('statement');
    });

    /*
     * Receipts from any non-customer buyer, through the same action and the same
     * table as a customer receipt. The permission comes from the buyer's channel
     * family, resolved by BuyerPolicy.
     */
    Route::post('buyers/{buyer}/payments', [BuyerPaymentController::class, 'store'])
        ->name('buyers.payments.store');
    Route::put('buyer-payments/{payment}/cancel', [BuyerPaymentController::class, 'cancel'])
        ->name('buyers.payments.cancel');

    /*
     * Finance
     */
    Route::prefix('finance')->name('finance.')->group(function (): void {
        Route::middleware('can:finance.account.manage')->group(function (): void {
            Route::get('accounts', [FinancialAccountController::class, 'index'])->name('accounts.index');
            Route::get('accounts/create', [FinancialAccountController::class, 'create'])->name('accounts.create');
            Route::post('accounts', [FinancialAccountController::class, 'store'])->name('accounts.store');
            Route::get('accounts/{account}', [FinancialAccountController::class, 'show'])->name('accounts.show');
            Route::get('accounts/{account}/edit', [FinancialAccountController::class, 'edit'])->name('accounts.edit');
            Route::put('accounts/{account}', [FinancialAccountController::class, 'update'])->name('accounts.update');
            Route::put('accounts/{account}/status', [FinancialAccountController::class, 'updateStatus'])
                ->name('accounts.status.update');
        });

        Route::get('cashbook', CashbookController::class)
            ->middleware('can:finance.cashbook.view')
            ->name('cashbook');

        // Partners: read behind partner.view, writes behind their own permissions.
        //
        // The literal `partners/create` is declared before `partners/{partner}`,
        // because Laravel matches in declaration order: the other way round,
        // /partners/create resolves as a partner whose id is "create" and is
        // gated by the wrong permission.
        Route::get('partners/create', [PartnerController::class, 'create'])
            ->middleware('can:partner.create')->name('partners.create');
        Route::post('partners', [PartnerController::class, 'store'])
            ->middleware('can:partner.create')->name('partners.store');

        Route::middleware('can:partner.view')->group(function (): void {
            Route::get('partners', [PartnerController::class, 'index'])->name('partners.index');
            Route::get('partners/{partner}', [PartnerController::class, 'show'])->name('partners.show');
        });

        Route::middleware('can:partner.update')->group(function (): void {
            Route::get('partners/{partner}/edit', [PartnerController::class, 'edit'])->name('partners.edit');
            Route::put('partners/{partner}', [PartnerController::class, 'update'])->name('partners.update');
            Route::put('partners/{partner}/status', [PartnerController::class, 'updateStatus'])
                ->name('partners.status.update');
        });

        Route::middleware('can:partner.contribution.create')->group(function (): void {
            Route::post('partners/{partner}/contributions', [PartnerContributionController::class, 'store'])
                ->name('partners.contributions.store');
            Route::put('contributions/{contribution}/cancel', [PartnerContributionController::class, 'cancel'])
                ->name('contributions.cancel');
        });

        // Expenses
        Route::middleware('can:expense.view')->group(function (): void {
            Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        });

        Route::middleware('can:expense.create')->group(function (): void {
            Route::get('expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
            Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        });

        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])
            ->middleware('can:expense.view')->name('expenses.show');

        Route::middleware('can:expense.update')->group(function (): void {
            Route::get('expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
            Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        });

        Route::put('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])
            ->middleware('can:expense.cancel')->name('expenses.cancel');
    });

    /*
     * Buyers. Authorisation is per record, decided by BuyerPolicy from the
     * sales channel, so these carry no 'can' middleware.
     */
    Route::get('buyers', [BuyerController::class, 'index'])->name('buyers.index');
    Route::get('buyers/create', [BuyerController::class, 'create'])->name('buyers.create');
    Route::post('buyers', [BuyerController::class, 'store'])->name('buyers.store');
    Route::get('buyers/{buyer}', [BuyerController::class, 'show'])->name('buyers.show');
    Route::get('buyers/{buyer}/edit', [BuyerController::class, 'edit'])->name('buyers.edit');
    Route::put('buyers/{buyer}', [BuyerController::class, 'update'])->name('buyers.update');
    Route::put('buyers/{buyer}/status', [BuyerController::class, 'updateStatus'])->name('buyers.status.update');
    Route::post('buyers/{buyer}/prices', [BuyerController::class, 'storePrice'])->name('buyers.prices.store');
    Route::delete('buyers/{buyer}/prices/{priceRule}', [BuyerController::class, 'destroyPrice'])
        ->name('buyers.prices.destroy');

    /*
     * Administration
     */
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::middleware('can:'.PermissionCatalog::USER_MANAGE)->group(function (): void {
            Route::resource('users', UserController::class)->except(['show', 'destroy']);
            Route::put('users/{user}/status', [UserStatusController::class, 'update'])
                ->name('users.status.update');
        });

        Route::middleware('can:'.PermissionCatalog::ROLE_MANAGE)->group(function (): void {
            Route::resource('roles', RoleController::class)->except(['show']);
        });

        // Read-only. There is no create, update or delete route for audit records.
        Route::middleware('can:'.PermissionCatalog::AUDIT_VIEW)->group(function (): void {
            Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
            Route::get('audit/{auditLog}', [AuditLogController::class, 'show'])->name('audit.show');
        });
    });

    /*
     * Settings
     */
    Route::prefix('settings')->name('settings.')
        ->middleware('can:'.PermissionCatalog::SETTINGS_MANAGE)
        ->group(function (): void {
            Route::get('business', [BusinessController::class, 'edit'])->name('business.edit');
            Route::put('business', [BusinessController::class, 'update'])->name('business.update');

            Route::get('farms', [FarmController::class, 'index'])->name('farms.index');
            Route::get('farms/create', [FarmController::class, 'create'])->name('farms.create');
            Route::post('farms', [FarmController::class, 'store'])->name('farms.store');
            Route::get('farms/{farm}/edit', [FarmController::class, 'edit'])->name('farms.edit');
            Route::put('farms/{farm}', [FarmController::class, 'update'])->name('farms.update');
            Route::put('farms/{farm}/primary', [FarmController::class, 'makePrimary'])->name('farms.primary');
            Route::put('farms/{farm}/status', [FarmController::class, 'updateStatus'])->name('farms.status.update');

            Route::get('payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
            Route::post('payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
            Route::put('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])
                ->name('payment-methods.update');
            Route::put('payment-methods/{paymentMethod}/status', [PaymentMethodController::class, 'updateStatus'])
                ->name('payment-methods.status.update');
            Route::delete('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])
                ->name('payment-methods.destroy');

            Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])
                ->name('expense-categories.index');
            Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])
                ->name('expense-categories.store');
            Route::put('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])
                ->name('expense-categories.update');
            Route::put('expense-categories/{expenseCategory}/status', [ExpenseCategoryController::class, 'updateStatus'])
                ->name('expense-categories.status.update');
            Route::delete('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])
                ->name('expense-categories.destroy');

            Route::get('sales-channels', [SalesChannelController::class, 'index'])->name('sales-channels.index');
            Route::post('sales-channels', [SalesChannelController::class, 'store'])->name('sales-channels.store');
            Route::put('sales-channels/{salesChannel}', [SalesChannelController::class, 'update'])
                ->name('sales-channels.update');
            Route::put('sales-channels/{salesChannel}/status', [SalesChannelController::class, 'updateStatus'])
                ->name('sales-channels.status.update');
            Route::delete('sales-channels/{salesChannel}', [SalesChannelController::class, 'destroy'])
                ->name('sales-channels.destroy');

            Route::get('milk-prices', [MilkPriceController::class, 'index'])->name('milk-prices.index');
            Route::post('milk-prices', [MilkPriceController::class, 'store'])->name('milk-prices.store');
            Route::delete('milk-prices/{milkPrice}', [MilkPriceController::class, 'destroy'])
                ->name('milk-prices.destroy');
        });
});
