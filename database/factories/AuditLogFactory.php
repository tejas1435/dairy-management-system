<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => AuditAction::Created->value,
            'auditable_type' => 'partner',
            'auditable_id' => 1,
            'subject' => $this->faker->name(),
            'old_values' => null,
            'new_values' => ['name' => $this->faker->name()],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
        ];
    }
}
