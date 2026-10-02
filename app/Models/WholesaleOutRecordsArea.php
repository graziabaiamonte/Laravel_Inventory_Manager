<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOutRecordsArea extends Model
{
    use HasFactory;

    protected $table = 'wholesale_out_records_areas';

    protected $fillable = [
        'wholesale_out_records_id',
        'area_id',
        'quantity',
    ];

    public function wholesaleOutRecord(): BelongsTo
    {
        return $this->belongsTo(WholesaleOutRecord::class, 'wholesale_out_records_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }
}
