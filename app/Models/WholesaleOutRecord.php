<?php

namespace App\Models;

use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOutRecord extends Model
{
    use HasFactory;

    protected $casts = [
        'unit_price' => MoneyIntegerCast::class,
        'total_price' => MoneyIntegerCast::class,
    ];

    protected $fillable = [
        'wholesale_out_id',
        'position',
        'record_id',
        'stock_id',
        'quantity',
        'shipped_quantity',
        'unit_price',
        'discount',
        'total_price',
        'vat',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = [
        'shipped_quantity',
        'backordered_quantity',
    ];

    public function wholesaleOut(): BelongsTo
    {
        return $this->belongsTo(WholesaleOut::class);
    }

    public function parentRecord(): BelongsTo
    {
        return $this->belongsTo(Record::class, 'record_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function wholesaleOutRecordsArea(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WholesaleOutRecordsArea::class, 'wholesale_out_records_id');
    }

    public function backorderRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BackorderRecord::class);
    }

    /**
     * Calculate the shipped quantity for this wholesale out record
     * Shipped = Requested - Backordered (only pending backorders, not activated ones)
     *
     * IMPORTANT: Only counts PENDING backorders (status = 0), not activated ones (status = 1)
     * Activated backorders have already been fulfilled and shouldn't reduce the shipped quantity
     */
    public function getShippedQuantityAttribute(): int
    {
        // If shipped_quantity is set in database, use it (new behavior)
        if (isset($this->attributes['shipped_quantity']) && $this->attributes['shipped_quantity'] > 0) {
            return $this->attributes['shipped_quantity'];
        }

        // Fallback to calculation for legacy data (before migration)
        // Only calculate shipped quantity if the wholesale out is activated (status = 1)
        if (! $this->wholesaleOut || $this->wholesaleOut->status !== 1) {
            return 0;
        }

        if ($this->relationLoaded('backorderRecords')) {
            $totalBackordered = $this->backorderRecords
                ->filter(fn ($br) => $br->relationLoaded('backorder')
                    ? optional($br->backorder)->status === 0
                    : $br->backorder()->where('status', 0)->exists())
                ->sum('quantity');
        } else {
            $totalBackordered = $this->backorderRecords()
                ->whereHas('backorder', fn ($q) => $q->where('status', 0))
                ->sum('quantity');
        }

        return max(0, $this->quantity - $totalBackordered);
    }

    /**
     * Calculate the backordered quantity for this wholesale out record
     */
    public function getBackorderedQuantityAttribute(): int
    {
        if ($this->relationLoaded('backorderRecords')) {
            return (int) $this->backorderRecords->sum('quantity');
        }

        return (int) $this->backorderRecords()->sum('quantity');
    }
}
