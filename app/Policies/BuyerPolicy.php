<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Buyer;
use App\Models\SalesChannel;
use App\Models\User;
use App\Support\BuyerPermissions;

/**
 * Authorises buyer records through the permission family of their channel.
 *
 * Every decision still resolves to a permission; the policy only works out
 * *which* permission applies to this row. Nothing here checks a role name.
 */
class BuyerPolicy
{
    /** The list is reachable if the user can view buyers in any channel. */
    public function viewAny(User $user): bool
    {
        foreach (BuyerPermissions::allViewPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, Buyer $buyer): bool
    {
        return $user->can(BuyerPermissions::view($buyer));
    }

    /**
     * Creation is checked against the channel the buyer is being created in,
     * which the request supplies, so the channel must be resolved before the
     * check rather than after.
     */
    public function create(User $user, ?SalesChannel $channel = null): bool
    {
        if ($channel === null) {
            foreach (BuyerPermissions::families() as $family) {
                if ($user->can($family.'.create')) {
                    return true;
                }
            }

            return $user->can(BuyerPermissions::CUSTOM_FAMILY.'.create');
        }

        return $user->can(BuyerPermissions::create($channel));
    }

    public function update(User $user, Buyer $buyer): bool
    {
        return $user->can(BuyerPermissions::update($buyer));
    }

    /**
     * Archiving a buyer — removing them from the active round without touching
     * their history.
     *
     * The specification gives only the customer family its own archive permission
     * (`customer.archive`), because a direct customer leaving the delivery round is a
     * distinct operational decision. Other channels have no archive permission, so
     * deactivating them falls back to the update right their channel already grants.
     */
    public function archive(User $user, Buyer $buyer): bool
    {
        return $buyer->isDirectCustomer()
            ? $user->can('customer.archive')
            : $user->can(BuyerPermissions::update($buyer));
    }

    /**
     * Buyers are deactivated, never deleted: they are referenced by sales,
     * payments and settlements in later phases.
     */
    public function delete(User $user, Buyer $buyer): bool
    {
        return false;
    }

    /** Recording money received from this buyer. */
    public function recordPayment(User $user, Buyer $buyer): bool
    {
        return $user->can(BuyerPermissions::familyFor($buyer).'.payment.create');
    }

    /**
     * Withdrawing a recorded payment.
     *
     * Phase 4 could only answer this for a direct customer, because `customer.*` was
     * the only family with a cancel permission — so the check was written to refuse
     * every other channel rather than pass on a permission that did not exist.
     *
     * Phase 5 added `mandali.payment.cancel` and `vendor.payment.cancel`, so the
     * question is now asked the same way as every other buyer permission: through the
     * channel's family. A custom channel maps to `customer.*` (D26), which is the
     * family its payments were recorded under in the first place.
     */
    public function cancelPayment(User $user, Buyer $buyer): bool
    {
        return $user->can(BuyerPermissions::familyFor($buyer).'.payment.cancel');
    }

    /**
     * Managing a Mandali's period settlements.
     *
     * Only a Mandali has settlements in V1. The channel check is not redundant with
     * the permission: `mandali.settlement.manage` says the user may run settlements,
     * and this says the subject is a buyer that has them — a settlement against a
     * direct customer would be a routing bug, and refusing it here is cheaper than
     * discovering it in the data.
     */
    public function manageSettlement(User $user, Buyer $buyer): bool
    {
        return $buyer->isMandali() && $user->can('mandali.settlement.manage');
    }
}
