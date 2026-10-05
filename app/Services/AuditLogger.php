<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * The single place audit records are written.
 *
 * Domain actions call the intention-revealing methods below rather than
 * constructing AuditLog rows, so redaction, actor resolution and request
 * metadata are applied uniformly instead of being remembered at each call site.
 *
 * Two rules the whole design rests on:
 *
 *  1. **Never persist a whole request payload.** Only the fields that actually
 *     changed are recorded, so the log stays readable and cannot accumulate
 *     secrets that happened to be posted alongside.
 *  2. **Redact centrally.** A new sensitive field is protected everywhere by
 *     adding one entry to SENSITIVE_KEYS, not by auditing every call site.
 */
class AuditLogger
{
    /**
     * Field names whose values are never stored.
     *
     * Matched case-insensitively against whole keys; SENSITIVE_FRAGMENTS below
     * additionally catches anything containing these substrings, so a field
     * named `smtp_password` or `webhook_secret` is caught without being listed.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'api_token',
        'access_token',
        'refresh_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @var array<int, string> */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'credential',
    ];

    /**
     * Attributes that change on nearly every save and say nothing about intent.
     *
     * `slip_path` is here for a different reason: it is a path on the private disk,
     * and an audit record is read by more people than the file is. The callers that
     * audit a Mandali delivery already pass explicit payloads that omit it; this is
     * the backstop for a future caller that audits a whole model and would otherwise
     * copy the path in. What a reader needs is *whether* a slip is attached and what
     * it is called, and those are recorded by name.
     *
     * @var array<int, string>
     */
    private const NOISE_KEYS = [
        'created_at',
        'updated_at',
        'email_verified_at',
        'last_login_at',
        'slip_path',
    ];

    private const REDACTED = '[redacted]';

    /** Records the creation of a record, capturing its meaningful attributes. */
    public function created(Model $model, ?array $values = null, ?string $subject = null): AuditLog
    {
        return $this->record(
            AuditAction::Created,
            $model,
            [],
            $this->clean($values ?? $model->getAttributes()),
            $subject,
        );
    }

    /**
     * Records an update, storing only the attributes that actually differ.
     *
     * Returns null when nothing meaningful changed, so a form resubmitted
     * without edits does not add a row that says nothing.
     */
    public function updated(Model $model, array $before, ?array $after = null, ?string $subject = null): ?AuditLog
    {
        $before = $this->clean($before);
        $after = $this->clean($after ?? $model->getAttributes());

        [$old, $new] = $this->diff($before, $after);

        if ($new === []) {
            return null;
        }

        return $this->record(AuditAction::Updated, $model, $old, $new, $subject);
    }

    /** Records an activation or deactivation. */
    public function statusChanged(Model $model, bool $active, ?string $subject = null): AuditLog
    {
        return $this->record(
            $active ? AuditAction::Activated : AuditAction::Deactivated,
            $model,
            ['is_active' => ! $active],
            ['is_active' => $active],
            $subject,
        );
    }

    /** Records a cancellation together with the reason given for it. */
    public function cancelled(Model $model, string $reason, ?string $subject = null): AuditLog
    {
        return $this->record(
            AuditAction::Cancelled,
            $model,
            [],
            ['cancellation_reason' => $reason],
            $subject,
        );
    }

    /**
     * Records anything else worth knowing about, such as a funding split or a
     * role permission change.
     */
    public function custom(
        AuditAction $action,
        Model $model,
        array $old = [],
        array $new = [],
        ?string $subject = null,
    ): AuditLog {
        return $this->record($action, $model, $this->clean($old), $this->clean($new), $subject);
    }

    private function record(
        AuditAction $action,
        Model $model,
        array $old,
        array $new,
        ?string $subject,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            // getMorphClass() returns the alias from the enforced morph map.
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'subject' => Str::limit($subject ?? $this->describe($model), 250, ''),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'ip_address' => $this->ipAddress(),
            'user_agent' => $this->userAgent(),
        ]);
    }

    /**
     * Removes noise and redacts sensitive values.
     *
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        $cleaned = [];

        foreach ($values as $key => $value) {
            if (in_array($key, self::NOISE_KEYS, true)) {
                continue;
            }

            $cleaned[$key] = $this->isSensitive($key) ? self::REDACTED : $this->normalise($value);
        }

        return $cleaned;
    }

    private function isSensitive(string $key): bool
    {
        $lower = Str::lower($key);

        if (in_array($lower, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** Casts values to something that survives a JSON round trip legibly. */
    private function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_object($value) => (string) $value,
            default => $value,
        };
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            // Loose comparison on scalars only, so "1" and 1 are not a change
            // while null and 0 still are.
            if ($this->same($previous, $value)) {
                continue;
            }

            $old[$key] = $previous;
            $new[$key] = $value;
        }

        return [$old, $new];
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }

        return $a === $b;
    }

    private function describe(Model $model): string
    {
        foreach (['name', 'title', 'label', 'code', 'email'] as $attribute) {
            if (filled($model->getAttribute($attribute))) {
                return (string) $model->getAttribute($attribute);
            }
        }

        return $model->getMorphClass().' #'.$model->getKey();
    }

    /**
     * Request metadata, when there is a request.
     *
     * Presence of REMOTE_ADDR is the test, not `runningInConsole()`. A console
     * process and an HTTP request are not the same question: a queued job runs
     * in console and legitimately has no client address, while an HTTP request
     * under test also reports as console and would wrongly lose its address.
     * Asking what the request actually carries answers both correctly and makes
     * the behaviour verifiable.
     */
    private function ipAddress(): ?string
    {
        return $this->hasHttpContext() ? Request::ip() : null;
    }

    private function userAgent(): ?string
    {
        if (! $this->hasHttpContext()) {
            return null;
        }

        return Str::limit((string) Request::userAgent(), 500, '') ?: null;
    }

    private function hasHttpContext(): bool
    {
        return filled(Request::server('REMOTE_ADDR'));
    }
}
