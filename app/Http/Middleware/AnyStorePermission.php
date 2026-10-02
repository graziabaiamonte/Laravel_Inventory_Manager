<?php

namespace App\Http\Middleware;

use App\Enums\PermissionsEnum;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

// @DISABLED-DYNAMIC-PERMS: This middleware is part of the disabled dynamic permissions system
// It calls getStores() which doesn't exist (should be getLocations()) and is never used in routes
class AnyStorePermission
{
    // public function handle(Request $request, Closure $next)
    // {
    //     $permissions = array_merge(
    //         ['admin', 'all', 'manage_locations'],
    //         collect(PermissionsEnum::getStores())->pluck('value')->toArray()
    //     );
    //
    //     return app(RoleOrPermissionMiddleware::class)
    //         ->handle($request, $next, $permissions);
    // }
}
