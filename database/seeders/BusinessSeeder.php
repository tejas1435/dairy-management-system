<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DateFormat;
use App\Enums\Locale;
use App\Models\Business;
use App\Models\Farm;
use App\Services\BusinessContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the one business and its primary farm.
 *
 * Idempotent: if a business already exists it is left untouched, because its
 * settings are edited through Settings and must not be reset by a re-seed.
 */
class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $business = Business::query()->oldest('id')->first();

            $business ??= Business::create([
                'name' => env('BUSINESS_NAME', 'Dairy Farm'),
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
                'date_format' => DateFormat::default()->value,
                'default_locale' => Locale::default()->value,
                'is_active' => true,
            ]);

            if ($business->farms()->doesntExist()) {
                Farm::create([
                    'business_id' => $business->id,
                    'name' => env('FARM_NAME', 'Main Farm'),
                    'code' => env('FARM_CODE', 'MAIN'),
                    'is_primary' => true,
                    'is_active' => true,
                ]);
            }
        });

        app(BusinessContext::class)->forget();

        $context = app(BusinessContext::class);

        $this->command?->info(sprintf(
            'Business: %s. Primary farm: %s.',
            $context->business()->name,
            $context->primaryFarm()->label(),
        ));
    }
}
