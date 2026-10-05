<?php

declare(strict_types=1);

namespace App\Http\Controllers\Milk;

use App\Enums\SaleSource;
use App\Models\Buyer;

/**
 * The generic sale form for administrator-created channels (MASTER_SPEC section 25).
 *
 * A hotel, a sweet shop, a bulk buyer. The rate is typed in because a custom channel
 * has no price rules of its own, and the buyer query admits **only** custom channels:
 * Mandalis, vendors and direct customers each have a workflow with rules this form
 * does not apply, so letting one through here would be a way round them.
 */
class OtherSaleController extends ChannelSaleController
{
    protected function source(): SaleSource
    {
        return SaleSource::GenericSale;
    }

    protected function viewPrefix(): string
    {
        return 'channel-sales';
    }

    protected function routePrefix(): string
    {
        return 'milk.other-sales';
    }

    protected function buyerQuery()
    {
        return Buyer::query()
            ->where('business_id', $this->context->business()->id)
            ->customChannel();
    }
}
