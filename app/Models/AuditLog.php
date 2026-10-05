<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * An audit record.
 *
 * Append-only by construction: `updated_at` does not exist, and both updating
 * and deleting throw. Records are written through App\Services\AuditLogger, not
 * by calling create() from a controller.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'subject',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * An audit trail that can be rewritten is not an audit trail. These
         * guards make tampering a crash rather than a silent success, including
         * from tinker or a future controller that forgets.
         */
        static::updating(function (): never {
            throw new RuntimeException('Audit records are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Audit records are append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The fields that changed, as old/new pairs, for the detail view.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function changes(): array
    {
        $keys = array_unique(array_merge(
            array_keys($this->old_values ?? []),
            array_keys($this->new_values ?? []),
        ));

        sort($keys);

        $changes = [];

        foreach ($keys as $key) {
            $changes[$key] = [
                'old' => $this->old_values[$key] ?? null,
                'new' => $this->new_values[$key] ?? null,
            ];
        }

        return $changes;
    }

    /** A one-line description of what changed, for the list view. */
    public function summary(): string
    {
        $fields = array_keys($this->changes());

        if ($fields === []) {
            return $this->subject ?? '';
        }

        $shown = array_slice($fields, 0, 3);
        $summary = implode(', ', $shown);

        if (count($fields) > count($shown)) {
            $summary .= ' +'.(count($fields) - count($shown));
        }

        return $summary;
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForAuditable(Builder $query, string $type, int $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }
}
