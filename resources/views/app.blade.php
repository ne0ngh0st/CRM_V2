<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- `viewport-fit=cover`: a barra inferior já usa `safe-area-inset-bottom`; sem isto o inset vale 0 no PWA do iPhone e a barra encosta no home indicator. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#1a1a1a">
        {{-- Os dois: iOS ainda lê o prefixo `apple-`; o Chrome lê o sem prefixo. --}}
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="PALMA">
        <meta name="apple-mobile-web-app-status-bar-style" content="black">
        <link rel="manifest" href="/manifest.json">
        <link rel="icon" type="image/png" sizes="32x32" href="/images/pwa/favicon-32.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/images/pwa/icon-192.png">
        <link rel="apple-touch-icon" href="/images/pwa/apple-touch-icon.png">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
