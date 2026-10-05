<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilkType;
use App\Enums\Shift;
use App\Support\Quantity;
use Database\Factories\MilkProductionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shift's recorded production for a farm, holding both milk types.
 *
 * There is no `milk_type` attribute. Cow and buffalo are columns, and
 * `quantityFor()` is how the reconciliation engine asks for one of them without
 * spreading `$production->cow_milk_quantity` through the codebase.
 */
class MilkProduction extends Model
{
    /** @use HasFactory<MilkProductionFactory> */
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'production_date',
        'shift',
        'cow_milk_quantity',
        'buffalo_milk_quantity',
        'notes',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'shift' => Shift::class,
            // decimal: keeps the value a string at 3 places. Casting to float
            // here would undo the point of the DECIMAL column.
            'cow_milk_quantity' => 'decimal:3',
            'buffalo_milk_quantity' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Farm, $this> */
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The column that holds a given milk type's quantity. */
    public static function columnFor(MilkType $milkType): string
    {
        return match ($milkType) {
            MilkType::Cow => 'cow_milk_quantity',
            MilkType::Buffalo => 'buffalo_milk_quantity',
        };
    }

    /** This shift's production of one milk type, as a decimal string. */
    public function quantityFor(MilkType $milkType): string
    {
        return Quantity::of($this->getAttribute(self::columnFor($milkType)));
    }

    /** Both types together. */
    public function totalQuantity(): string
    {
        return Quantity::add($this->cow_milk_quantity, $this->buffalo_milk_quantity);
    }

    /**
     * Narrows to one farm, date and shift -- the identity this table is unique
     * on, so the result is always zero or one row.
     *
     * @param  Builder<MilkProduction>  $query
     * @return Builder<MilkProduction>
     */
    public function scopeForShift(Builder $query, int $farmId, string $date, Shift $shift): Builder
    {
        return $query->where('farm_id', $farmId)
            ->whereDate('production_date', $date)
            ->where('shift', $shift->value);
    }
}
