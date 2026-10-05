<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Locale;
use App\Models\User;
use App\Services\BusinessContext;
use App\Support\RoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates the development administrator from environment values.
 *
 * Deliberately does nothing unless both ADMIN_EMAIL and ADMIN_PASSWORD are
 * present. A seeder that invents a default password would put a known
 * credential on every installation, including ones that later become
 * production. Skipping is the safe outcome, and the console explains how to
 * create the account properly.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            $this->command?->warn(
                'Admin user skipped: ADMIN_EMAIL and ADMIN_PASSWORD are not both set in .env.'
            );
            $this->command?->line(
                '  Create one interactively instead: php artisan dairy:create-admin'
            );

            return;
        }

        $user = DB::transaction(function () use ($email, $password): User {
            $user = User::query()->where('email', $email)->first();

            $attributes = [
                'business_id' => app(BusinessContext::class)->business()->id,
                'name' => env('ADMIN_NAME', 'Administrator'),
                'email' => $email,
                'password' => $password,
                'locale' => Locale::default()->value,
                'is_active' => true,
            ];

            if ($user) {
                $user->fill($attributes)->save();
            } else {
                $user = User::create($attributes);
            }

            $user->syncRoles([RoleCatalog::SUPER_ADMIN]);

            return $user;
        });

        $this->command?->info("Admin user ready: {$user->email} (Super Admin).");
    }
}
