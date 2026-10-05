<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buyers;

use App\Models\SalesChannel;

/**
 * Mandali management (MASTER_SPEC section 22).
 *
 * A Mandali is a buyer in the Mandali channel, so everything here comes from
 * {@see ChannelBuyerController}. The three declarations below are the whole
 * difference between this screen and the vendor one.
 */
class MandaliController extends ChannelBuyerController
{
    protected function channelSlug(): string
    {
        return SalesChannel::MANDALI;
    }

    protected function viewPrefix(): string
    {
        return 'buyers.channel';
    }

    protected function saleRouteName(): string
    {
        return 'milk.mandali-deliveries.create';
    }

    protected function routePrefix(): string
    {
        return 'mandalis';
    }

    protected function translationPrefix(): string
    {
        return 'buyers.mandali';
    }
}
