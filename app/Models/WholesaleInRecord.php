<?php

namespace App\Models;

use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleInRecord extends Model
{
    use HasFactory;

    protected $casts = [
        'unit_price' => MoneyIntegerCast::class,
        'total_price' => MoneyIntegerCast::class,
        'retail_price' => MoneyIntegerCast::class,
        'wholesale_price' => MoneyIntegerCast::class,
        'purchase_price' => MoneyIntegerCast::class,
    ];

    protected $fillable = [
        'wholesale_in_id',
        'position',
        'record_id',
        'quantity',
        'unit_price',
        'discount',
        'total_price',
        'vat',
        'retail_price',
        'wholesale_price',
        'purchase_price',
    ];

    public function wholesaleIn(): BelongsTo
    {
        return $this->belongsTo(WholesaleIn::class);
    }

    public function parentRecord(): BelongsTo
    {
        // Explicitly define the foreign key as the "parentRecord" nomenclature makes laravel look for parent_record_id
        return $this->belongsTo(Record::class, 'record_id');
    }

    public function wholesaleInRecordsArea(): HasMany
    {
        return $this->hasMany(WholesaleinRecordsArea::class, 'wholesale_in_records_id');
    }
}
