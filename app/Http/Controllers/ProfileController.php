<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\ComboResource;
use App\Models\Location;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'locations' => ComboResource::collection(Location::filterByAdminRoles()->where('status', 1)->get()),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validatedData = $request->validated();

        // Handle default store change
        if (isset($validatedData['default_location_id'])) {
            $newDefaultLocationId = $validatedData['default_location_id'];
            $oldDefaultLocationId = $user->default_location_id;

            // NOTE: We're not having the locations assoc Combo in profile edit, but only the default store one,
            // so we can't merge the selected locations with the default one before sync() as done in userController

            // If the deafult store has changed, we need deatach() the old one not to create orphans
            if ($oldDefaultLocationId !== $newDefaultLocationId) {
                if ($oldDefaultLocationId) {
                    $user->locations()->detach($oldDefaultLocationId);
                }

                // Add new store association if provided
                if ($newDefaultLocationId) {
                    $user->locations()->syncWithoutDetaching([$newDefaultLocationId]);
                }
            }
        }

        $user->fill($validatedData);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    // public function destroy(Request $request): RedirectResponse
    // {
    //     $request->validate([
    //         'password' => ['required', 'current_password'],
    //     ]);

    //     $user = $request->user();

    //     Auth::logout();

    //     $user->delete();

    //     $request->session()->invalidate();
    //     $request->session()->regenerateToken();

    //     return Redirect::to('/');
    // }
}
