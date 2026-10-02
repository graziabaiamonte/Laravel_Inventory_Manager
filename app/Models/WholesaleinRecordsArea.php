<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleinRecordsArea extends Model
{
    use HasFactory;

    protected $table = 'wholesale_in_records_areas';

    protected $fillable = [
        'wholesale_in_records_id',
        'area_id',
        'quantity',
    ];

    public function wholesaleInRecord(): BelongsTo
    {
        return $this->belongsTo(WholesaleInRecord::class, 'wholesale_in_records_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }
}
