<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackorderRecordsArea extends Model
{
    use HasFactory;

    protected $table = 'backorder_records_areas';

    protected $fillable = [
        'backorder_records_id',
        'area_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function backorderRecord(): BelongsTo
    {
        return $this->belongsTo(BackorderRecord::class, 'backorder_records_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
