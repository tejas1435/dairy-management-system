<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Seeds the payment methods (MASTER_SPEC section 46).
 *
 * Matched on `code`, so re-running never duplicates a method and an
 * administrator who renamed "UPI" keeps their label.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [PaymentMethod::CASH, 'Cash', 10],
            [PaymentMethod::UPI, 'UPI', 20],
            [PaymentMethod::BANK_TRANSFER, 'Bank Transfer', 30],
            [PaymentMethod::CHEQUE, 'Cheque', 40],
            [PaymentMethod::OTHER, 'Other', 50],
        ];

        foreach ($methods as [$code, $name, $order]) {
            PaymentMethod::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_system' => true, 'is_active' => true, 'sort_order' => $order],
            );
        }

        $this->command?->info('Payment methods: '.PaymentMethod::query()->count().'.');
    }
}
