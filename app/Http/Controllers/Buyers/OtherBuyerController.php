<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buyers;

/**
 * Buyers in administrator-created channels: a hotel, a sweet shop, a bulk buyer.
 *
 * One screen for all of them rather than one per channel, because a custom channel is
 * created by the user — there is no slug to name in a controller and no fixed number
 * of them. {@see ChannelBuyerController} therefore reads a null channel slug as "every
 * channel that is not one of the three the specification names".
 *
 * It exists because a receivable with no screen is worse than no receivable. The
 * generic sale form has been able to sell milk to a sweet shop since Phase 5 Pass 1,
 * and every such sale raises a balance — but a custom-channel buyer had no trade
 * profile, so that balance could be created and never seen or settled. A payment
 * route with nothing linking to it is not a feature.
 *
 * Settlements are absent here, not hidden: only a Mandali settles a period, and the
 * shared profile draws that section from the buyer itself.
 *
 * Authorisation is the `customer.*` family, per D26 — commercially these are direct
 * buyers, and the alternative was either a permission per administrator-created
 * channel or borrowing Mandali rights, which would hand settlement authority to
 * whoever can see a sweet shop's account.
 */
class OtherBuyerController extends ChannelBuyerController
{
    protected function channelSlug(): ?string
    {
        return null;
    }

    protected function viewPrefix(): string
    {
        return 'buyers.channel';
    }

    protected function saleRouteName(): string
    {
        return 'milk.other-sales.create';
    }

    protected function routePrefix(): string
    {
        return 'other-buyers';
    }

    protected function translationPrefix(): string
    {
        return 'buyers.other_buyer';
    }
}
