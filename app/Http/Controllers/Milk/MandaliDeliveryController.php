<?php

declare(strict_types=1);

namespace App\Http\Controllers\Milk;

use App\Actions\Milk\RecordChannelSale;
use App\Enums\SaleSource;
use App\Models\Buyer;

/**
 * Mandali collections (MASTER_SPEC section 22).
 *
 * The rate is typed in, fat and SNF are recorded beside it and change nothing, and an
 * optional collection slip can be attached. All of that is in
 * {@see ChannelSaleController} and {@see RecordChannelSale}; this
 * declares which workflow it is.
 */
class MandaliDeliveryController extends ChannelSaleController
{
    protected function source(): SaleSource
    {
        return SaleSource::MandaliDelivery;
    }

    protected function viewPrefix(): string
    {
        return 'channel-sales';
    }

    protected function routePrefix(): string
    {
        return 'milk.mandali-deliveries';
    }

    protected function buyerQuery()
    {
        return Buyer::query()
            ->where('business_id', $this->context->business()->id)
            ->mandalis();
    }
}
