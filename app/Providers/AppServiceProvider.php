<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\MilkSalesAllocator;
use App\Services\BusinessContext;
use App\Services\Milk\RecordedMilkSales;
use App\Services\PriceResolver;
use App\Support\MorphMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One resolution of the business and primary farm per request.
        $this->app->singleton(BusinessContext::class);

        /*
         * One price resolver per request, so its memo is actually shared.
         *
         * `PriceResolver` memoises by buyer, milk type and date and documents that
         * memo as per-request — but it was not bound, so every injection built its
         * own. The Customer Daily Entry grid made the cost of that visible: the grid
         * primes every rate in two queries, then each cell's save resolves again
         * through a *different* instance, which is two more queries per cell on a
         * screen that saves a whole round at once.
         *
         * Binding it here makes the documented behaviour true, and is what the
         * `forget()` method was always written for.
         */
        $this->app->singleton(PriceResolver::class);

        /*
         * The sales half of milk reconciliation.
         *
         * Reconciliation is production + adjustments - (sales + internal usage).
         * The engine is written against the MilkSalesAllocator contract and this
         * binding decides what answers it (docs/DECISIONS.md D32).
         *
         * Phase 3 bound NoMilkSalesRecorded, which reported a true zero and the
         * fact that no sales subsystem existed. Phase 4 created `milk_sales`, so
         * the real allocator now aggregates it by channel.
         *
         * **This one line is the whole of the swap.** CalculateMilkReconciliation,
         * MilkReconciliation and the reconciliation view are unchanged, which is
         * what the contract was for: the engine is also where the "remaining must
         * not go negative" rule lives, and that rule should not be edited by the
         * phase that starts feeding it sales.
         */
        $this->app->singleton(MilkSalesAllocator::class, RecordedMilkSales::class);
    }

    public function boot(): void
    {
        $this->registerMorphMap();

        /*
         * Laravel's paginator renders Tailwind markup by default, which this
         * project forbids. Without this the pagination links on every list page
         * would silently emit Tailwind classes that no stylesheet resolves.
         */
        Paginator::useBootstrapFive();

        /*
         * preventSilentlyDiscardingAttributes turns a typo in a fill() array
         * into an exception instead of a quietly ignored field.
         *
         * preventLazyLoading is deliberately NOT enabled. Spatie's permission
         * package resolves roles and permissions through lazy-loaded relations
         * on every authorisation check, so enabling it would throw on ordinary
         * `can()` calls rather than surfacing our own N+1 problems. Query
         * efficiency is handled by explicit eager loading in the queries that
         * feed list pages, and reviewed per phase.
         */
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        /*
         * There is deliberately no Gate::before here, and no role-name check
         * anywhere in the authorisation path.
         *
         * Every decision resolves through a permission held via a role. Super
         * Admin reaches everything because RoleSeeder synchronises that role
         * with the whole permission catalogue, not because its name is special.
         * A role is a bundle of permissions, never an authorisation shortcut.
         *
         * The practical consequence: a permission introduced by a later phase
         * grants nothing to anyone, including Super Admin, until the seeder runs
         * and adds it to the role. That is the intended behaviour. A new
         * capability should become reachable when it is deliberately granted,
         * not the instant its string appears in the catalogue.
         */
    }

    /**
     * Stable aliases for polymorphic relations, so class names are never
     * persisted as business identifiers (docs/DECISIONS.md D10).
     *
     * Enforced, which means a polymorphic relation on an unmapped model raises
     * an error instead of quietly writing a fully-qualified class name. The map
     * lives in App\Support\MorphMap and grows as each phase introduces its
     * models; only models that exist today are listed.
     */
    private function registerMorphMap(): void
    {
        Relation::enforceMorphMap(MorphMap::map());
    }
}
