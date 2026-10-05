<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SalesChannel;
use App\Services\BusinessContext;
use Illuminate\Database\Seeder;

/**
 * Seeds the three system sales channels (MASTER_SPEC section 12).
 *
 * These are "system" because later phases branch on their slugs. Custom
 * channels are created by administrators and are not touched here.
 */
class SalesChannelSeeder extends Seeder
{
    public function run(): void
    {
        $business = app(BusinessContext::class)->business();

        $channels = [
            [SalesChannel::MANDALI, 'Mandali', 10],
            [SalesChannel::VENDOR, 'Vendor', 20],
            [SalesChannel::DIRECT_CUSTOMER, 'Direct Customer', 30],
        ];

        foreach ($channels as [$slug, $name, $order]) {
            SalesChannel::query()->firstOrCreate(
                ['business_id' => $business->getKey(), 'slug' => $slug],
                ['name' => $name, 'is_system' => true, 'is_active' => true, 'sort_order' => $order],
            );
        }

        $this->command?->info('Sales channels: '.SalesChannel::query()->count().'.');
    }
}
