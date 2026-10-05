<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    /**
     * Phase 6 creates the animal purchase expense automatically and needs to
     * find this category without matching a translatable label.
     */
    public const ANIMAL_PURCHASE = 'animal_purchase';

    protected $fillable = ['name', 'code', 'is_system', 'is_active', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @param  Builder<ExpenseCategory>  $query
     * @return Builder<ExpenseCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ExpenseCategory>  $query
     * @return Builder<ExpenseCategory>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function isInUse(): bool
    {
        return $this->expenses()->exists();
    }
}
