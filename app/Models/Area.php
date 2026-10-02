<?php

namespace App\Models;

use App\Enums\LocationTypeEnum;
use App\Traits\HasAdminFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Area extends Model
{
    use HasAdminFilters, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'status',
    ];

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        /** @var User|null */
        $user = Auth::user();

        // Users with all permission can see all areas
        if ($user->hasAnyPermission(['all'])) {
            return $query;
        }

        // Other users can only see locations associated with their stores
        return $query->whereExists(function ($subquery) use ($user) {
            $subquery->from('area_location')
                ->join('location_user', 'location_user.location_id', '=', 'area_location.location_id')
                ->whereColumn('area_location.area_id', 'areas.id')
                ->where('location_user.user_id', $user->id);
        });
    }

    public function scopeDefaultWarehouseAreas(Builder $query): Builder
    {
        return $query->where('status', 1)
            ->whereIn('id', function ($subquery) {
                $subquery->select('default_area_id')
                    ->from('locations')
                    ->where('status', 1)
                    ->where('type', LocationTypeEnum::WAREHOUSE)
                    ->whereNotNull('default_area_id');
            });
    }

    public function scopeDefaulLocationsAreas(Builder $query): Builder
    {
        return $query->where('status', 1)
            ->whereIn('id', function ($subquery) {
                $subquery->select('default_area_id')
                    ->from('locations')
                    ->where('status', 1)
                    ->whereNotNull('default_area_id');
            });
    }

    public function wholesaleinRecordsArea(): HasMany
    {
        return $this->hasMany(WholesaleinRecordsArea::class, 'wholesale_in_records_id');
    }

    public function backorderRecordsAreas(): HasMany
    {
        return $this->hasMany(BackorderRecordsArea::class);
    }
}
