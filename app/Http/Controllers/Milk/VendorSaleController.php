<?php

declare(strict_types=1);

namespace App\Http\Controllers\Milk;

use App\Enums\SaleSource;
use App\Models\Buyer;

/**
 * Vendor sales (MASTER_SPEC section 24).
 *
 * Priced from the configured rate for the vendor, milk type and sale date. A
 * different figure is an authorised override needing `milk.sale.override_rate` and a
 * reason — the one real difference from the other two workflows.
 */
class VendorSaleController extends ChannelSaleController
{
    protected function source(): SaleSource
    {
        return SaleSource::VendorSale;
    }

    protected function viewPrefix(): string
    {
        return 'channel-sales';
    }

    protected function routePrefix(): string
    {
        return 'milk.vendor-sales';
    }

    protected function buyerQuery()
    {
        return Buyer::query()
            ->where('business_id', $this->context->business()->id)
            ->vendors();
    }
}
