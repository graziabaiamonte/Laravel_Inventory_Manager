<?php

namespace App\Models;

use App\Traits\HasAdminFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;

class Location extends Model
{
    use HasAdminFilters, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'default_area_id',
        'status',
        'default_wholesaleout_location',
    ];

    // @DISABLED-DYNAMIC-PERMS: Dynamic permission system commented out - using location_user pivot table instead
    // protected static function boot()
    // {
    //     parent::boot();
    //
    //     static::created(function ($location) {
    //         // Create a unique permission for this store
    //         Permission::create([
    //             'name' => 'manage_location_'.$location->id,
    //             'guard_name' => 'web',
    //         ]);
    //     });
    //
    //     static::deleting(function ($location) {
    //         // Clean up the permission when store is deleted
    //         Permission::where('name', 'manage_location_'.$location->id)->delete();
    //     });
    // }

    // @DISABLED-DYNAMIC-PERMS: Cache invalidation for dynamic permissions - no longer needed
    // protected static function booted()
    // {
    //     // Clean up location.permissions cache used in PermissionsEnum
    //     static::saved(fn () => cache()->forget('location.permissions'));
    //     static::deleted(fn () => cache()->forget('location.permissions'));
    // }

    // @DISABLED-DYNAMIC-PERMS: Dynamic permission accessor - no longer used
    // protected function permission(): Attribute
    // {
    //     return Attribute::make(
    //         get: fn () => 'manage_location_'.$this->id,
    //     );
    // }

    public function areas()
    {
        return $this->belongsToMany(Area::class);
    }

    public function defaultArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'default_area_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    // public function records(): hasMany
    // {
    //     return $this->hasMany(Record::class);
    // }

    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        /** @var User|null */
        $user = Auth::user();

        // Users with all permission can see all stores
        if ($user->hasAnyPermission(['all'])) {
            return $query;
        }

        // Other users can only see stores they're associated with
        return $query->whereExists(function ($subquery) use ($user) {
            $subquery->from('location_user')
                ->whereColumn('location_user.location_id', 'locations.id')
                ->where('location_user.user_id', $user->id);
        });
    }
}
