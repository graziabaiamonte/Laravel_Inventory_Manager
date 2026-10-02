<?php

namespace App\Models;

use App\Traits\HasAdminFilters;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class WholesaleOut extends Model
{
    use HasAdminFilters, HasFactory;

    protected $casts = [
        'total_price' => MoneyIntegerCast::class,
    ];

    protected $fillable = [
        'customer_id',
        'area_id',
        'total_price',
        'file',
        'description',
        'doc_num',
        'status',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(WholesaleOutRecord::class);
    }

    public function labelDiscounts(): HasMany
    {
        return $this->hasMany(WholesaleOutLabelDiscount::class);
    }

    public function backorders(): HasMany
    {
        return $this->hasMany(Backorder::class);
    }

    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        /** @var User|null */
        $user = Auth::user();

        // Users with all permission can see all wholesale outs
        if ($user->hasAnyPermission(['all'])) {
            return $query;
        }

        // Other users can only see wholesale outs for areas connected to their locations
        return $query->whereIn('wholesale_outs.area_id', function ($subquery) use ($user) {
            $subquery->select('area_id')
                ->from('area_location')
                ->whereIn('location_id', $user->locations()->pluck('locations.id'));
        });
    }
}
