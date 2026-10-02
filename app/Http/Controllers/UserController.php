<?php

namespace App\Http\Controllers;

use App\Enums\RolesEnum;
use App\Http\Requests\UserRequest;
use App\Http\Resources\ComboResource;
use App\Http\Resources\UserResource;
use App\Models\Location;
use App\Models\Sale;
use App\Models\User;
use App\Traits\LogsToChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class UserController extends Controller
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'users';
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $baseQuery = QueryBuilder::for(User::class)
            ->filterByAdminRoles()
            ->with(['roles'])
            ->select(['users.*', 'roles.name as role_name'])
            ->leftJoin('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->allowedSorts(['name', 'last_name', 'role_name'])
            ->allowedFilters([
                AllowedFilter::exact('roles.id'),
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['users.name', 'users.last_name']);
                }),
            ]);

        return Inertia::render('User/Index', [
            'users' => $baseQuery->paginate($this->perPage($request)),
            // NOTE: W're merging the DB roles with the Enum ones to get both id and description.
            // By passing the id, we leverage the default behaviour for relationship filters defined in the indexFilters Helper,
            // which will get/set roles.id as required above in AllowedFilter::exact('roles.id')
            'roles' => Role::all()->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => RolesEnum::from($role->name) ?
                        RolesEnum::from($role->name)->getDescription() : $role->name,
                ];
            }),
            // 'roles' => ComboResource::collection(Role::all()),
            // 'roles' => RolesEnum::getJsonValues(),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    /**
     * Display a listing of the trash resource.
     */
    public function indexTrash(Request $request)
    {
        $baseQuery = QueryBuilder::for(User::class)
            ->onlyTrashed()
            ->filterByAdminRoles()
            ->with(['roles'])
            ->select(['users.*', 'roles.name as role_name'])
            ->leftJoin('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->allowedSorts(['name', 'last_name', 'role_name'])
            ->allowedFilters([
                AllowedFilter::exact('roles.id'),
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['users.name', 'users.last_name']);
                }),
            ]);

        return Inertia::render('User/Trash/Index', [
            'users' => $baseQuery->paginate($this->perPage($request)),
            // NOTE: W're merging the DB roles with the Enum ones to get both id and description.
            // By passing the id, we leverage the default behaviour for relationship filters defined in the indexFilters Helper,
            // which will get/set roles.id as required above in AllowedFilter::exact('roles.id')
            'roles' => Role::all()->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => RolesEnum::from($role->name) ?
                        RolesEnum::from($role->name)->getDescription() : $role->name,
                ];
            }),
            // 'roles' => ComboResource::collection(Role::all()),
            // 'roles' => RolesEnum::getJsonValues(),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('User/Create', [
            'roles' => RolesEnum::getJsonValues(),
            'locations' => ComboResource::collection(Location::filterByAdminRoles()->where('status', 1)->get()),
        ]);
    }

    /**
     * Location a newly created resource in storage.
     */
    public function store(UserRequest $request)
    {
        $user = User::create($request->except(['role', 'locations']));
        $user->assignRole(RolesEnum::from($request->get('role'))->value);

        // Handle locations
        if ($locations = $request->get('locations')) {
            $locationIds = collect($locations)->pluck('id')->values()->toArray();
            $user->locations()->sync($locationIds);

            // If default store is set, ensure it's in the pivot table
            if ($defaultLocationId = $request->get('default_location_id')) {
                if (! in_array($defaultLocationId, $locationIds)) {
                    $user->locations()->attach($defaultLocationId);
                }
            }
        } elseif ($defaultLocationId = $request->get('default_location_id')) {
            // If no locations but default store is set
            $user->locations()->attach($defaultLocationId);
        }

        return redirect()
            ->route('user.edit', $user->id)
            ->with('success', 'Utente creato con successo!');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(UserRequest $request, User $user)
    {
        return Inertia::render('User/Edit', [
            'user' => UserResource::make($user->load('locations')),
            'roles' => RolesEnum::getJsonValues(),
            'locations' => ComboResource::collection(Location::filterByAdminRoles()->where('status', 1)->get()),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UserRequest $request, User $user)
    {
        $user->update($request->except(['role_value', 'locations']));

        if ($newRole = $request->get('role_value')) {
            $user->syncRoles([]);
            $user->assignRole(RolesEnum::from($newRole)->value);
        }

        // Get all locations including default_location_id
        $locationIds = collect($request->get('locations', []))
            ->pluck('id')
            ->push($request->get('default_location_id'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // Sync all locations
        $user->locations()->sync($locationIds);

        return redirect()
            ->back()
            ->with('success', 'Utente salvato!');
    }

    public function restore(User $user)
    {
        // $user = User::withTrashed();
        if (! $user->trashed()) {
            return back()->withErrors('l\'utente non è nel cestino.');
        }

        $user->restore();

        return back()->with('success', 'Utente ripristinato con successo');
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * Permanently delete a user, handing their sales to another administrator.
     *
     * sales.user_id is ON DELETE CASCADE and nothing in the schema restricts it,
     * so a force delete would take every sale the account ever recorded, and
     * their sale_records with them. The sales are handed to another administrator
     * instead, so the history outlives the account. location_user rows are left
     * to cascade: they are this user's own location assignments and mean nothing
     * once the account is gone.
     *
     * The successor must sit on the company's own email domain. Administrators
     * exist on other domains, agency and personal addresses among them, and the
     * shop's sales history should not come to rest on one of those. Where no such
     * account remains the deletion is refused rather than falling back, since a
     * refusal loses nothing and the cascade loses everything.
     *
     * Returns null when the user was deleted, or a message explaining why not.
     */
    private function forceDeleteReassigningSales(User $user): ?string
    {
        $successor = User::role(RolesEnum::ADMIN)
            ->whereKeyNot($user->id)
            ->where('email', 'LIKE', '%@'.User::COMPANY_EMAIL_DOMAIN)
            ->orderBy('id')
            ->first();

        if (! $successor) {
            return 'Non è possibile eliminare definitivamente questo utente: '.
                'non esiste un altro amministratore con un indirizzo @'.User::COMPANY_EMAIL_DOMAIN.
                ' a cui assegnare le sue vendite.';
        }

        DB::transaction(function () use ($user, $successor) {
            $reassigned = Sale::where('user_id', $user->id)->update(['user_id' => $successor->id]);

            $this->logInfo('Sales reassigned before permanently deleting a user.', [
                'deleted_user_id' => $user->id,
                'successor_user_id' => $successor->id,
                'sales_reassigned' => $reassigned,
            ]);

            $user->forceDelete();
        });

        return null;
    }

    public function destroy(?User $user, UserRequest $request)
    {

        if ($request->ids) {

            $userCount = User::count();

            foreach ($request->ids as $id) {

                $id = intval($id);

                $userMulti = User::withTrashed()->find($id);

                if (! $userMulti) {
                    continue;
                }

                if ($userMulti->trashed()) {
                    if ($error = $this->forceDeleteReassigningSales($userMulti)) {
                        return back()->withErrors($error);
                    }
                } else {

                    if ($userCount < 2 || $userMulti->id == auth()->id() || $userCount == count($request->ids)) {
                        return back()->withErrors('Non è possibile eliminare l\'ultimo utente o il proprio account.');
                    }

                    $userMulti->delete();
                }

            }

            return back()->with('success', 'Record eliminati con successo');
        }

        if ($user->trashed()) {
            if ($error = $this->forceDeleteReassigningSales($user)) {
                return back()->withErrors($error);
            }

            return back()->with('success', 'Utente eliminato definitivamente');
        }

        if ($user->id == auth()->id()) {
            return back()->withErrors('Non è possibile eliminarsi da soli.');
        }

        $userCount = User::count();
        if ($userCount < 2) {
            return back()->withErrors('Non è possibile eliminare l\'ultimo utente.');
        }

        $user->delete();

        return back()->with('success', 'Utente eliminato con successo');
    }

    // public function forceDestroy(UserRequest $request, User $user)
    // {

    //     if ($user->trashed()) {
    //         $user->forceDelete();
    //         return back()->with('success', 'Utente eliminato definitivamente');
    //     }

    //     // $userCount = User::count();
    //     // if ($userCount < 2) {
    //     //     return back()->withErrors('Non è possibile eliminare l\'ultimo utente.');
    //     // }

    //     $user->delete();

    //     return back()->with('success', 'Utente eliminato definitivamente con successo');
    // }

}
