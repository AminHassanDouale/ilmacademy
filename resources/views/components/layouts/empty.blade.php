<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('/favicon.ico') }}">
    <link rel="mask-icon" href="{{ asset('/favicon.ico') }}" color="#ff2d20">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

    <!-- Debug info -->
    <script>
        console.log('Environment: {{ app()->environment() }}');
        console.log('Hot file exists: {{ file_exists(public_path('hot')) ? 'true' : 'false' }}');
    </script>

    <!-- Vite directive - automatically handles dev/production assets -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
<x-main full-width class="h-full">
    <x-slot:content>
        {{ $slot }}
    </x-slot:content>
</x-main>

{{-- Toast --}}
<x-toast />
</body>
</html>
