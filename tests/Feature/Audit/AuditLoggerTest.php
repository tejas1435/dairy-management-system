<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\User;
use App\Services\AuditLogger;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    $this->actor = superAdmin();
    $this->logger = app(AuditLogger::class);
});

/*
|--------------------------------------------------------------------------
| A. Basic record
|--------------------------------------------------------------------------
*/

test('a record captures the actor, action, stable alias and id', function () {
    $partner = Partner::factory()->for($this->business)->create(['name' => 'Rekha Shah']);

    $this->actingAs($this->actor);
    $log = $this->logger->created($partner);

    expect($log->user_id)->toBe($this->actor->id)
        ->and($log->action)->toBe(AuditAction::Created)
        // The stable alias, never the class name.
        ->and($log->auditable_type)->toBe('partner')
        ->and($log->auditable_type)->not->toContain('App\\')
        ->and($log->auditable_id)->toBe($partner->id)
        ->and($log->subject)->toBe('Rekha Shah')
        ->and($log->created_at)->not->toBeNull();
});

test('a write with no http context records no actor, ip or user agent rather than inventing them', function () {
    /*
     * Seeders and console commands have no client address. The test harness
     * supplies a default REMOTE_ADDR to every request it builds, so that
     * default is removed here to reproduce the console case honestly rather
     * than asserting something the harness would satisfy anyway.
     */
    request()->server->remove('REMOTE_ADDR');
    request()->headers->remove('User-Agent');

    $partner = Partner::factory()->for($this->business)->create();

    $log = $this->logger->created($partner);

    expect($log->user_id)->toBeNull()
        ->and($log->ip_address)->toBeNull()
        ->and($log->user_agent)->toBeNull();
});

test('a web write records the request ip and user agent', function () {
    $partner = Partner::factory()->for($this->business)->create();

    // Exercised through a real request so the request context exists.
    $this->actingAs($this->actor)
        ->withServerVariables(['REMOTE_ADDR' => '10.11.12.13', 'HTTP_USER_AGENT' => 'PestBrowser/1.0'])
        ->post(route('finance.partners.store'), [
            'name' => 'Audited Partner',
            'is_active' => '1',
        ])->assertRedirect();

    $log = AuditLog::query()->where('auditable_type', 'partner')->latest('id')->firstOrFail();

    expect($log->ip_address)->toBe('10.11.12.13')
        ->and($log->user_agent)->toContain('PestBrowser');
});

/*
|--------------------------------------------------------------------------
| B. Create event
|--------------------------------------------------------------------------
*/

test('a create event records the new state and no old state', function () {
    $partner = Partner::factory()->for($this->business)->create(['name' => 'Nita']);

    $log = $this->logger->created($partner, ['name' => 'Nita', 'is_active' => true]);

    expect($log->old_values)->toBeNull()
        ->and($log->new_values)->toMatchArray(['name' => 'Nita', 'is_active' => true]);
});

/*
|--------------------------------------------------------------------------
| C. Update event
|--------------------------------------------------------------------------
*/

test('an update records only the fields that actually changed', function () {
    $partner = Partner::factory()->for($this->business)->create([
        'name' => 'Before', 'mobile' => '9000000000',
    ]);

    $before = $partner->only(['name', 'mobile']);
    $partner->update(['name' => 'After']);

    $log = $this->logger->updated($partner, $before, $partner->only(['name', 'mobile']));

    expect($log->old_values)->toBe(['name' => 'Before'])
        ->and($log->new_values)->toBe(['name' => 'After'])
        // The unchanged field is absent, not recorded as unchanged.
        ->and($log->new_values)->not->toHaveKey('mobile');
});

test('an update that changes nothing writes no record at all', function () {
    // A form resubmitted without edits should not add a row that says nothing.
    $partner = Partner::factory()->for($this->business)->create(['name' => 'Same']);

    $log = $this->logger->updated($partner, ['name' => 'Same'], ['name' => 'Same']);

    expect($log)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

test('timestamps and other save noise are never recorded as changes', function () {
    $partner = Partner::factory()->for($this->business)->create(['name' => 'Noisy']);

    $log = $this->logger->updated(
        $partner,
        ['name' => 'Noisy', 'updated_at' => '2020-01-01 00:00:00'],
        ['name' => 'Renamed', 'updated_at' => '2026-01-01 00:00:00'],
    );

    expect(array_keys($log->new_values))->toBe(['name']);
});

/*
|--------------------------------------------------------------------------
| D. Status change
|--------------------------------------------------------------------------
*/

test('activation and deactivation each produce their own action', function () {
    $partner = Partner::factory()->for($this->business)->create();

    $off = $this->logger->statusChanged($partner, false);
    $on = $this->logger->statusChanged($partner, true);

    expect($off->action)->toBe(AuditAction::Deactivated)
        ->and($off->new_values)->toBe(['is_active' => false])
        ->and($on->action)->toBe(AuditAction::Activated)
        ->and($on->new_values)->toBe(['is_active' => true]);
});

/*
|--------------------------------------------------------------------------
| E. Redaction
|--------------------------------------------------------------------------
*/

test('sensitive field names are redacted, never stored', function (string $field) {
    $partner = Partner::factory()->for($this->business)->create();
    $secret = 'super-secret-value-9999';

    $log = $this->logger->custom(AuditAction::Updated, $partner, [], [$field => $secret]);

    expect($log->new_values[$field])->toBe('[redacted]')
        ->and(json_encode($log->new_values))->not->toContain($secret);
})->with([
    'password',
    'password_confirmation',
    'current_password',
    'new_password',
    'password_hash',
    'remember_token',
    'token',
    'reset_token',
    'api_token',
    'secret',
    'api_key',
    'apikey',
    'private_key',
    'db_password',
    'database_credential',
    'two_factor_secret',
    'smtp_password',
    'webhook_secret',
]);

test('redaction is case insensitive', function (string $field) {
    $partner = Partner::factory()->for($this->business)->create();

    $log = $this->logger->custom(AuditAction::Updated, $partner, [], [$field => 'leaked']);

    expect($log->new_values[$field])->toBe('[redacted]');
})->with(['Password', 'PASSWORD', 'Remember_Token', 'API_KEY', 'Secret']);

test('redaction applies to old values as well as new', function () {
    $partner = Partner::factory()->for($this->business)->create();

    $log = $this->logger->custom(
        AuditAction::Updated,
        $partner,
        ['password' => 'old-secret'],
        ['password' => 'new-secret'],
    );

    expect($log->old_values['password'])->toBe('[redacted]')
        ->and($log->new_values['password'])->toBe('[redacted]')
        ->and(json_encode($log->getAttributes()))->not->toContain('old-secret')
        ->and(json_encode($log->getAttributes()))->not->toContain('new-secret');
});

test('ordinary fields are not redacted by accident', function () {
    $partner = Partner::factory()->for($this->business)->create();

    $log = $this->logger->custom(AuditAction::Updated, $partner, [], [
        'name' => 'Kiran',
        'mobile' => '9876543210',
        'amount' => '1000.00',
    ]);

    expect($log->new_values)->toBe([
        'name' => 'Kiran',
        'mobile' => '9876543210',
        'amount' => '1000.00',
    ]);
});

test('a real user password never reaches the audit log', function () {
    // The end-to-end path: an administrator setting a password through the UI.
    $this->actingAs($this->actor)->post(route('admin.users.store'), [
        'name' => 'New Person',
        'email' => 'new.person@example.test',
        'password' => 'plaintext-secret-42',
        'password_confirmation' => 'plaintext-secret-42',
        'locale' => 'en',
        'is_active' => '1',
    ])->assertRedirect();

    $created = User::query()->where('email', 'new.person@example.test')->firstOrFail();
    $logs = AuditLog::query()->where('auditable_type', 'user')
        ->where('auditable_id', $created->id)->get();

    expect($logs)->not->toBeEmpty();

    foreach ($logs as $log) {
        $payload = json_encode([$log->old_values, $log->new_values]);

        expect($payload)->not->toContain('plaintext-secret-42')
            // Nor the hash: it is still credential material.
            ->and($payload)->not->toContain($created->password);
    }
});

/*
|--------------------------------------------------------------------------
| F. Request payload safety
|--------------------------------------------------------------------------
*/

test('the logger records only the fields it is given, never the whole request', function () {
    $partner = Partner::factory()->for($this->business)->create();

    // A request carrying extra junk alongside the real fields.
    $this->actingAs($this->actor)->put(route('finance.partners.update', $partner), [
        'name' => 'Renamed Partner',
        'mobile' => $partner->mobile,
        'email' => $partner->email,
        'joining_date' => $partner->joining_date?->toDateString(),
        '_token_leak' => 'should-never-be-stored',
        'csrf_junk' => 'nor-this',
        'password' => 'definitely-not-this',
    ])->assertRedirect();

    $log = AuditLog::query()->where('auditable_type', 'partner')->latest('id')->firstOrFail();
    $payload = json_encode([$log->old_values, $log->new_values]);

    expect(array_keys($log->new_values))->toBe(['name'])
        ->and($payload)->not->toContain('should-never-be-stored')
        ->and($payload)->not->toContain('nor-this')
        ->and($payload)->not->toContain('definitely-not-this');
});

/*
|--------------------------------------------------------------------------
| G. Immutability
|--------------------------------------------------------------------------
*/

test('an audit record cannot be updated through the model', function () {
    $partner = Partner::factory()->for($this->business)->create();
    $log = $this->logger->created($partner);

    expect(fn () => $log->update(['action' => AuditAction::Deleted->value]))
        ->toThrow(RuntimeException::class);

    expect($log->fresh()->action)->toBe(AuditAction::Created);
});

test('an audit record cannot be deleted through the model', function () {
    $partner = Partner::factory()->for($this->business)->create();
    $log = $this->logger->created($partner);

    expect(fn () => $log->delete())->toThrow(RuntimeException::class);

    expect(AuditLog::query()->whereKey($log->getKey())->exists())->toBeTrue();
});

test('there are no routes for writing or removing audit records', function (string $name) {
    expect(app('router')->getRoutes()->getByName($name))->toBeNull();
})->with([
    'admin.audit.store',
    'admin.audit.update',
    'admin.audit.destroy',
    'admin.audit.edit',
    'admin.audit.create',
]);
