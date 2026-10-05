<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Models\User;
use App\Services\BusinessContext;
use App\Support\RoleCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Creates or resets the administrator account.
 *
 * The password is prompted without echoing and is never accepted as a
 * command-line argument, so it does not end up in shell history or in a process
 * listing.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'dairy:create-admin
        {--name= : Display name}
        {--email= : Email address}
        {--reset : Reset the password of an existing account instead of failing}';

    protected $description = 'Create or reset an administrator account (Super Admin)';

    public function handle(): int
    {
        $business = app(BusinessContext::class)->businessOrNull();

        if (! $business) {
            $this->components->error('No business exists yet. Run "php artisan db:seed" first.');

            return self::FAILURE;
        }

        if (! Role::query()->where('name', RoleCatalog::SUPER_ADMIN)->exists()) {
            $this->components->error('The Super Admin role does not exist. Run "php artisan db:seed" first.');

            return self::FAILURE;
        }

        $email = $this->option('email') ?: text(
            label: 'Email address',
            required: true,
        );

        $existing = User::query()->where('email', $email)->first();

        if ($existing && ! $this->option('reset')) {
            $this->components->error(
                "A user with {$email} already exists. Re-run with --reset to set a new password."
            );

            return self::FAILURE;
        }

        $name = $this->option('name') ?: ($existing->name ?? text(
            label: 'Display name',
            required: true,
        ));

        $password = promptPassword(label: 'Password', required: true);
        $confirmation = promptPassword(label: 'Confirm password', required: true);

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($existing?->id),
            ],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($existing, $business, $name, $email, $password): User {
            $attributes = [
                'business_id' => $business->id,
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_active' => true,
            ];

            if ($existing) {
                $existing->fill($attributes)->save();
                $user = $existing;
            } else {
                $user = User::create($attributes + ['locale' => Locale::default()->value]);
            }

            $user->syncRoles([RoleCatalog::SUPER_ADMIN]);

            return $user;
        });

        $this->components->info(
            ($existing ? 'Password reset for ' : 'Administrator created: ')."{$user->email} (Super Admin)."
        );

        return self::SUCCESS;
    }
}
