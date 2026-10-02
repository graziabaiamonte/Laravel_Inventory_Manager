<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOutLabelDiscount extends Model
{
    use HasFactory;

    protected $fillable = [
        'wholesale_out_id',
        'label_id',
        'discount',
    ];

    protected $casts = [
        'discount' => 'integer',
    ];

    public function wholesaleOut(): BelongsTo
    {
        return $this->belongsTo(WholesaleOut::class);
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(Label::class);
    }
}
