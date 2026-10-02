<?php

namespace App\Http\Middleware;

use App\Enums\StatesEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ?
                    $request->user()->load(['locations']) : null,  // Load locations relationship
                'roles' => Auth::user() ? Auth::user()->roles->pluck('name') : [],
                'permissions' => Permission::when($request->user()?->roles)
                    ->whereHas('roles', function (Builder $q) use ($request) {
                        // NOTE: Role's permissions only
                        $q->whereIn('id', $request->user()->roles->pluck('id'));
                    })
                    // Force the return of an empty array if permission has no roles
                    ->orWhere('id', null)
                    ->get()
                    ->pluck('name')
                    // Add user permissions to the collection
                    ->concat(
                        // NOTE: Direct permissions only
                        Auth::user() ? Auth::user()->permissions->pluck('name') : []
                    )
                    // Make the collection's values unique in case the user has one same permission as his role already have
                    ->unique()
                    ->values()
                    ->toArray(),
            ],
            'flash' => function () use ($request) {
                return [
                    'success' => $request->session()->get('success'),
                    'warning' => $request->session()->get('warning'),
                    'discogsData' => $request->session()->get('discogsData'),
                ];
            },
            'appEnv' => app()->environment(),
            'filter' => $request->get('filter'),
            'activeStates' => StatesEnum::getJsonValues(),
            'ziggy' => function () use ($request) {
                return array_merge((new Ziggy)->toArray(), [
                    'location' => $request->url(),
                ]);
            },
        ]);
    }
}
