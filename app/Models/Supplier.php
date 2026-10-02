<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'status',
    ];

    // public function records(): HasMany
    // {
    //     return $this->hasMany(Record::class);
    // }

    public function wholesaleIns(): HasMany
    {
        return $this->hasMany(WholesaleIn::class);
    }
}
