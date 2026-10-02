<?php

namespace App\Models;

use App\Enums\SaleTypeEnum;
// use App\Traits\HasManageableAccess;
use App\Traits\HasAdminFilters;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Sale extends Model
{
    use HasAdminFilters, HasFactory; // , HasManageableAccess;

    protected $fillable = [
        'user_id',
        'location_id',
        'remote_customer_id',
        'remote_customer_name',
        'discogs_order_id',
        'type',
        'amount',
        'date',
        'description',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => MoneyIntegerCast::class,
        'type' => SaleTypeEnum::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function saleRecords(): HasMany
    {
        return $this->hasMany(SaleRecord::class);
    }

    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        /** @var User|null */
        $user = Auth::user();

        // Users with all permission can see all sales
        if ($user->hasAnyPermission(['all'])) {
            return $query;
        }

        // Other users can only see sales from their assigned locations
        return $query->whereIn('sales.location_id', $user->locations()->pluck('locations.id'));
    }
}
