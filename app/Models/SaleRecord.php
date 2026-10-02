<?php

namespace App\Models;

use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleRecord extends Model
{
    use HasFactory;

    protected $casts = [
        'price' => MoneyIntegerCast::class,
        'total_price' => MoneyIntegerCast::class,
    ];

    protected $fillable = [
        'sale_id',
        'position',
        'record_id',
        'stock_id',
        'quantity',
        'price',
        'vat',
        'discount',
        'total_price',
        'discogs_id',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
