<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use DanieleDeSantis\LaravelTurnstile\Http\Rules\TurnstileValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'turnstile_sitekey' => config('turnstile.sitekey'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        // Skip Turnstile validation for Cypress tests only in local/testing environments
        $isCypressTest = (app()->environment(['local', 'testing']) || config('app.env') === 'testing')
            && str_contains($request->userAgent() ?? '', 'Cypress');

        if (config('turnstile.sitekey') && ! $isCypressTest) {
            $request->validate([
                'cf-turnstile-response' => ['required', new TurnstileValidation],
            ]);
        }

        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
