<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'business_id',
        'name',
        'email',
        'password',
        'locale',
        'is_active',
    ];

    /**
     * last_login_at is written by the login flow, never by mass assignment.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'locale' => Locale::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Deactivated accounts must not regain access through the password reset
     * flow. The token row is still created so the caller receives the same
     * generic response either way and cannot use the reset form to discover
     * whether an address belongs to a disabled account.
     */
    public function sendPasswordResetNotification($token): void
    {
        if (! $this->is_active) {
            return;
        }

        parent::sendPasswordResetNotification($token);
    }

    public function preferredLocale(): Locale
    {
        return $this->locale ?? Locale::default();
    }

    /** Initials for the avatar placeholder in the top navigation. */
    public function initials(): string
    {
        preg_match_all('/\b\p{L}/u', $this->name, $matches);

        return mb_strtoupper(implode('', array_slice($matches[0] ?? [], 0, 2))) ?: '?';
    }
}
