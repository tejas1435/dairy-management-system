<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buyers;

use App\Models\SalesChannel;

/**
 * Vendor management (MASTER_SPEC section 24).
 *
 * The same two pages as the Mandali screens over the same table, differing only in
 * channel, permission family and wording — see {@see ChannelBuyerController}.
 */
class VendorController extends ChannelBuyerController
{
    protected function channelSlug(): string
    {
        return SalesChannel::VENDOR;
    }

    protected function viewPrefix(): string
    {
        return 'buyers.channel';
    }

    protected function saleRouteName(): string
    {
        return 'milk.vendor-sales.create';
    }

    protected function routePrefix(): string
    {
        return 'vendors';
    }

    protected function translationPrefix(): string
    {
        return 'buyers.vendor';
    }
}
