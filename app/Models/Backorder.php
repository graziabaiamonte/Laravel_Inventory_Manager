<?php

namespace App\Models;

use App\Traits\HasAdminFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Backorder extends Model
{
    use HasAdminFilters, HasFactory;

    protected $fillable = [
        'wholesale_out_id',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function wholesaleOut(): BelongsTo
    {
        return $this->belongsTo(WholesaleOut::class);
    }

    public function backorderRecords(): HasMany
    {
        return $this->hasMany(BackorderRecord::class);
    }

    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        /** @var User|null */
        $user = Auth::user();

        // Users with all permission can see all backorders
        if ($user->hasAnyPermission(['all'])) {
            return $query;
        }

        // Other users can only see backorders for wholesale outs in their locations
        // Backorders are related to WholesaleOut which has area_id
        return $query->whereHas('wholesaleOut', function ($subquery) use ($user) {
            $subquery->whereIn('area_id', function ($areaQuery) use ($user) {
                $areaQuery->select('area_id')
                    ->from('area_location')
                    ->whereIn('location_id', $user->locations()->pluck('locations.id'));
            });
        });
    }
}
