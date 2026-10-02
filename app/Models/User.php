<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PermissionsEnum;
use App\Enums\RolesEnum;
use App\Traits\HasAdminFilters;
use App\Traits\HasManageableAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasAdminFilters, HasFactory, HasManageableAccess, HasRoles, Notifiable, SoftDeletes;

    /**
     * The company's own email domain.
     *
     * Used to pick who inherits a deleted user's work: an account outside this
     * domain may belong to an agency or a personal address, which is not somewhere
     * the shop's sales history should end up.
     */
    public const COMPANY_EMAIL_DOMAIN = 'company.example';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'last_name',
        'email',
        'password',
        'status',
        'default_location_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function scopeFilterByAdminRoles(Builder $query): Builder
    {
        /** @var User|null */
        $user = Auth::user();

        // Admins
        // Can see all Users
        $hasAllPermissions = $user->can(PermissionsEnum::All->value);

        if ($hasAllPermissions) {
            return $query;
        }

        // Others
        // Won't see his own User in the user.index
        $query = $query->where('users.id', '!=', $user->id)
            // Can only manage Users associated with their Store and with a role lower then Admin
            ->whereDoesntHave('roles', function ($query) { // not Admin
                $query->where('name', RolesEnum::Admin->value);
            });

        // Filter only the users that belong to the same locations as the current user
        return $query->whereHas('locations', function ($query) use ($user) {
            $query->whereIn('locations.id', $user->locations()->pluck('locations.id'));
        });
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function canBeManagedBy(User $user): bool
    {
        // Admins
        // Can manage all Users
        $hasAllPermissions = $user->can(PermissionsEnum::All->value);

        // Managers
        // Can only manage Users associated with their Store and with a role lower then Admin
        $modelIsNotAdmin = ! $this->hasRole(RolesEnum::Admin->value); // not Admin
        // $modelBelongsToSameStore = ; // belongs to same Store

        return $hasAllPermissions || $modelIsNotAdmin;
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class);
    }

    public function defaultLocation()
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }
}
