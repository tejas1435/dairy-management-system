<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Farm;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    $this->admin = superAdmin();
});

/** The entries written for one model, newest first. */
function auditFor(string $alias, int $id)
{
    return AuditLog::query()
        ->where('auditable_type', $alias)
        ->where('auditable_id', $id)
        ->orderByDesc('id')
        ->get();
}

/*
|--------------------------------------------------------------------------
| H. Viewer authorisation
|--------------------------------------------------------------------------
*/

test('a user with audit.view can list and open entries', function () {
    $viewer = userWithPermissions([PermissionCatalog::AUDIT_VIEW]);
    $log = AuditLog::factory()->create();

    $this->actingAs($viewer)->get(route('admin.audit.index'))->assertOk();
    $this->actingAs($viewer)->get(route('admin.audit.show', $log))->assertOk();
});

test('a user without audit.view is refused by the server, not just by a hidden menu', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);
    $log = AuditLog::factory()->create();

    $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.audit.show', $log))->assertForbidden();
});

test('the audit link is hidden from a user who cannot view it', function () {
    // Presentation only; the assertion above is what actually protects it.
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('admin.audit.index'));
});

/*
|--------------------------------------------------------------------------
| I. Phase 1 retrofit coverage
|--------------------------------------------------------------------------
*/

test('creating a user is audited, including the roles granted', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), [
        'name' => 'Audited User',
        'email' => 'audited@example.test',
        'password' => 'strong-pass-99',
        'password_confirmation' => 'strong-pass-99',
        'locale' => 'en',
        'is_active' => '1',
        'roles' => [RoleCatalog::ACCOUNTANT],
    ])->assertRedirect();

    $user = User::query()->where('email', 'audited@example.test')->firstOrFail();
    $logs = auditFor('user', $user->id);

    expect($logs->pluck('action')->all())
        ->toContain(AuditAction::Created)
        ->toContain(AuditAction::RolesChanged);

    $roles = $logs->firstWhere('action', AuditAction::RolesChanged);
    expect($roles->new_values['roles'])->toBe([RoleCatalog::ACCOUNTANT]);
});

test('deactivating and reactivating a user is audited', function () {
    $target = User::factory()->create(['is_active' => true]);

    $this->actingAs($this->admin)
        ->put(route('admin.users.status.update', $target), ['is_active' => 0])
        ->assertRedirect();

    $this->actingAs($this->admin)
        ->put(route('admin.users.status.update', $target), ['is_active' => 1])
        ->assertRedirect();

    expect(auditFor('user', $target->id)->pluck('action')->all())
        ->toContain(AuditAction::Deactivated)
        ->toContain(AuditAction::Activated);
});

test('changing a users roles is audited with the before and after lists', function () {
    $target = User::factory()->create();
    $target->assignRole(RoleCatalog::VIEWER);

    $this->actingAs($this->admin)->put(route('admin.users.update', $target), [
        'name' => $target->name,
        'email' => $target->email,
        'locale' => 'en',
        'is_active' => '1',
        'roles' => [RoleCatalog::MANAGER],
    ])->assertRedirect();

    $log = auditFor('user', $target->id)->firstWhere('action', AuditAction::RolesChanged);

    expect($log)->not->toBeNull()
        ->and($log->old_values['roles'])->toBe([RoleCatalog::VIEWER])
        ->and($log->new_values['roles'])->toBe([RoleCatalog::MANAGER]);
});

test('a password change is recorded as a fact without the value', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin)->put(route('admin.users.update', $target), [
        'name' => $target->name,
        'email' => $target->email,
        'locale' => 'en',
        'is_active' => '1',
        'password' => 'replacement-pass-7',
        'password_confirmation' => 'replacement-pass-7',
    ])->assertRedirect();

    $logs = auditFor('user', $target->id);
    $payload = json_encode($logs->map(fn ($l) => [$l->old_values, $l->new_values])->all());

    // The fact survives the redactor; the value never reaches it.
    expect($payload)->toContain('password_changed')
        ->and($payload)->not->toContain('replacement-pass-7')
        ->and($payload)->not->toContain($target->refresh()->password);
});

test('creating a role is audited with its permissions', function () {
    $this->actingAs($this->admin)->post(route('admin.roles.store'), [
        'name' => 'Audited Role',
        'permissions' => ['dashboard.view', 'expense.view'],
    ])->assertRedirect();

    $role = Role::query()->where('name', 'Audited Role')->firstOrFail();
    $log = auditFor('role', $role->id)->firstWhere('action', AuditAction::Created);

    expect($log)->not->toBeNull()
        ->and($log->new_values['permissions'])->toBe(['dashboard.view', 'expense.view']);
});

test('changing a roles permissions is audited with the full before and after sets', function () {
    $role = Role::findOrCreate('Audited Role', 'web');
    $role->syncPermissions(['dashboard.view']);

    $this->actingAs($this->admin)->put(route('admin.roles.update', $role), [
        'name' => 'Audited Role',
        'permissions' => ['dashboard.view', 'partner.view'],
    ])->assertRedirect();

    $log = auditFor('role', $role->id)->firstWhere('action', AuditAction::PermissionsChanged);

    expect($log)->not->toBeNull()
        ->and($log->old_values['permissions'])->toBe(['dashboard.view'])
        ->and($log->new_values['permissions'])->toBe(['dashboard.view', 'partner.view']);
});

test('deleting a custom role is audited before the row disappears', function () {
    $role = Role::findOrCreate('Temporary Role', 'web');
    $role->syncPermissions(['dashboard.view']);
    $id = $role->id;

    $this->actingAs($this->admin)->delete(route('admin.roles.destroy', $role))->assertRedirect();

    $log = auditFor('role', $id)->firstWhere('action', AuditAction::Deleted);

    expect($log)->not->toBeNull()
        ->and($log->old_values['name'])->toBe('Temporary Role')
        ->and(Role::query()->whereKey($id)->exists())->toBeFalse();
});

test('business settings changes are audited', function () {
    $this->actingAs($this->admin)->put(route('settings.business.update'), [
        'name' => 'Renamed Dairy',
        'currency' => 'INR',
        'timezone' => 'Asia/Kolkata',
        'date_format' => 'd/m/Y',
        'default_locale' => 'gu',
        'is_active' => '1',
    ])->assertRedirect();

    $log = auditFor('business', $this->business->id)->firstWhere('action', AuditAction::Updated);

    expect($log)->not->toBeNull()
        ->and($log->new_values)->toHaveKey('name')
        ->and($log->new_values['name'])->toBe('Renamed Dairy')
        ->and($log->new_values['date_format'])->toBe('d/m/Y')
        ->and($log->new_values['default_locale'])->toBe('gu');
});

test('changing the primary farm is audited with both farms named', function () {
    $original = $this->business->farms()->first();
    $replacement = Farm::factory()->for($this->business)->create(['code' => 'NEW', 'name' => 'North Farm']);

    $this->actingAs($this->admin)
        ->put(route('settings.farms.primary', $replacement))
        ->assertRedirect();

    $log = auditFor('farm', $replacement->id)->firstWhere('action', AuditAction::PrimaryChanged);

    expect($log)->not->toBeNull()
        ->and($log->old_values['primary_farm'])->toContain($original->name)
        ->and($log->new_values['primary_farm'])->toContain('North Farm');
});

test('farm creation and deactivation are audited', function () {
    $this->actingAs($this->admin)->post(route('settings.farms.store'), [
        'name' => 'South Farm',
        'code' => 'SOUTH',
        'is_active' => '1',
    ])->assertRedirect();

    $farm = Farm::query()->where('code', 'SOUTH')->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('settings.farms.status.update', $farm), ['is_active' => 0])
        ->assertRedirect();

    expect(auditFor('farm', $farm->id)->pluck('action')->all())
        ->toContain(AuditAction::Created)
        ->toContain(AuditAction::Deactivated);
});

test('no audit records are invented for changes that predate the audit service', function () {
    // seedBusiness() creates the business and farm directly through factories,
    // exactly as history created before Phase 2 would have been. Nothing should
    // have manufactured entries for them.
    expect(auditFor('business', $this->business->id))->toBeEmpty()
        ->and(auditFor('farm', $this->business->farms()->first()->id))->toBeEmpty();
});
