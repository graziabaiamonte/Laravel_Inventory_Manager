<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackorderRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'backorder_id',
        'wholesale_out_record_id',
        'quantity',
        'shipped_quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'shipped_quantity' => 'integer',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = [
        'shipped_quantity',
        'backordered_quantity',
    ];

    public function backorder(): BelongsTo
    {
        return $this->belongsTo(Backorder::class);
    }

    public function wholesaleOutRecord(): BelongsTo
    {
        return $this->belongsTo(WholesaleOutRecord::class);
    }

    public function backorderRecordsAreas(): HasMany
    {
        return $this->hasMany(BackorderRecordsArea::class, 'backorder_records_id');
    }

    /**
     * Calculate the shipped quantity for this backorder record
     * For backorders: Shows how much of this backorder quantity was actually shipped
     * Only calculated if the parent backorder is activated (status = 1)
     */
    public function getShippedQuantityAttribute(): int
    {
        // If shipped_quantity is set in database, use it (new behavior)
        if (isset($this->attributes['shipped_quantity']) && $this->attributes['shipped_quantity'] >= 0) {
            return $this->attributes['shipped_quantity'];
        }

        // Fallback to calculation for legacy data (before migration)
        // Only calculate shipped quantity if the backorder is activated (status = 1)
        if (! $this->backorder || $this->backorder->status !== 1) {
            return 0;
        }

        // For an activated backorder, we need to check if there are newer backorders
        // created for the same wholesale out record after this one was processed
        $newerBackorderQuantity = $this->wholesaleOutRecord
            ->backorderRecords()
            ->where('created_at', '>', $this->created_at)
            ->sum('quantity');

        // If there are newer backorders, it means part of this backorder couldn't be fulfilled
        // So shipped = original backorder quantity - newer backorder quantity
        return max(0, $this->quantity - $newerBackorderQuantity);
    }

    /**
     * Calculate the backordered quantity for this backorder record
     * For backorders: this is simply the current backorder quantity
     */
    public function getBackorderedQuantityAttribute(): int
    {
        return $this->quantity;
    }
}
