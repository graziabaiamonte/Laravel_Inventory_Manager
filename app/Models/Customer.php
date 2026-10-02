<?php

namespace App\Models;

use App\Traits\HasAdminFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasAdminFilters, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'last_name',
        'address',
        'phone',
        'email',
        'status',
    ];

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return trim($this->name.' '.$this->last_name);
    }

    public function wholesaleOuts(): HasMany
    {
        return $this->hasMany(WholesaleOut::class);
    }

    /**
     * Scope a query to filter customers by admin roles.
     * Users with 'all' permission can see all customers.
     * Other users see only customers who have WholesaleOuts in their assigned locations.
     */
    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        /** @var User|null */
        $user = \Illuminate\Support\Facades\Auth::user();

        // Users with all permission can see all customers
        if ($user->hasAnyPermission(['all'])) {
            return $query;
        }

        // Other users can only see customers who have orders in their assigned locations
        $locationIds = $user->locations()->pluck('locations.id');

        return $query->whereHas('wholesaleOuts', function ($q) use ($locationIds) {
            $q->whereHas('area', function ($areaQuery) use ($locationIds) {
                $areaQuery->whereHas('locations', function ($locationQuery) use ($locationIds) {
                    $locationQuery->whereIn('locations.id', $locationIds);
                });
            });
        });
    }
}
