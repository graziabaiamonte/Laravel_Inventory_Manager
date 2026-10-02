<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Permission;

class Artist extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'status',
    ];

    // @DISABLED-DYNAMIC-PERMS: Dynamic permission system commented out - never actually used in the application
    // protected static function boot()
    // {
    //     parent::boot();
    //
    //     static::created(function ($artist) {
    //         Permission::create([
    //             'name' => 'manage_artist_'.$artist->id,
    //             'guard_name' => 'web',
    //         ]);
    //     });
    //
    //     static::deleting(function ($artist) {
    //         Permission::where('name', 'manage_artist_'.$artist->id)->delete();
    //     });
    // }

    // @DISABLED-DYNAMIC-PERMS: Cache invalidation for dynamic permissions - no longer needed
    // protected static function booted()
    // {
    //     static::saved(fn () => cache()->forget('artist.permissions'));
    //     static::deleted(fn () => cache()->forget('artist.permissions'));
    // }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
