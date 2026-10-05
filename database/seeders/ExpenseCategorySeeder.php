<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['animal_feed', 'Animal Food / Feed', 10],
            ['medicine', 'Medicine', 20],
            [ExpenseCategory::ANIMAL_PURCHASE, 'Animal Purchase', 30],
            ['accessories', 'Accessories / Equipment', 40],
            ['vehicle', 'Vehicle', 50],
            ['employee', 'Employee', 60],
            ['electricity', 'Electricity', 70],
            ['farm', 'Farm', 80],
            ['repair_maintenance', 'Repair / Maintenance', 90],
            ['other', 'Other', 100],
        ];

        foreach ($categories as [$code, $name, $order]) {
            ExpenseCategory::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_system' => true, 'is_active' => true, 'sort_order' => $order],
            );
        }

        $this->command?->info('Expense categories: '.ExpenseCategory::query()->count().'.');
    }
}
