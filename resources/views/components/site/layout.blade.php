{{--
    Página pública de Tuku: la landing y, más adelante, la de cada organización.
    `icons`: los Material Symbols que usa la página (Google Fonts baja solo esos).
--}}
@props([
    'title',
    'description',
    'image' => asset('brand/tuku-tarjeta-redes.png'),
    'icons' => [],
])
@php($iconNames = collect($icons)->unique()->sort()->implode(','))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta name="theme-color" content="#f6f9f3" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0e1611" media="(prefers-color-scheme: dark)">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Tuku">
    <meta property="og:locale" content="es_PY">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@tukuhaapp">

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('brand/tuku-favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Nunito+Sans:wght@400;600;700;800&display=swap">
    @if ($iconNames !== '')
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..24,400,0..1,0&icon_names={{ $iconNames }}&display=block">
    @endif
    @vite('resources/css/site.css')
    {{ $head ?? '' }}
</head>
<body class="min-h-screen">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-md focus:bg-surface-raised focus:px-4 focus:py-2 focus:font-bold">
        Saltar al contenido
    </a>
    {{ $slot }}
</body>
</html>
