<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">

        <title inertia>{{ config('app.name', 'Company Software') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="shortcut icon" href="/favicon.ico">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased" @unless(app()->environment('production')) style="padding-top:2rem;" @endunless>
        @unless(app()->environment('production'))
            <div style="position:fixed;top:0;left:0;right:0;height:1.75rem;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:hsl(0 84.2% 60.2%);color:#fff;font-family:sans-serif;font-size:0.8125rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;box-shadow:0 0.0625rem 0.375rem rgba(0,0,0,.35);pointer-events:none;">
                Versione di sviluppo
            </div>
        @endunless
        @inertia
    </body>
</html>
