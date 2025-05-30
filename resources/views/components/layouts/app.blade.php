<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('/favicon.ico') }}">
    <link rel="mask-icon" href="{{ asset('/favicon.ico') }}" color="#ff2d20">

    {{--  Currency  --}}
    <script type="text/javascript" src="https://cdn.jsdelivr.net/gh/robsontenorio/mary@0.44.2/libs/currency/currency.js"></script>

    {{-- ChartJS --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    {{-- Flatpickr  --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    {{-- Cropper.js --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" />

    {{-- Sortable.js --}}
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.1/Sortable.min.js"></script>

    {{-- TinyMCE  --}}
    <script src="https://cdn.tiny.cloud/1/16eam5yke73excub2z217rcau87xhcbs0pxs4y8wmr5r7z6x/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

    {{-- PhotoSwipe --}}
    <script src="https://cdn.jsdelivr.net/npm/photoswipe@5.4.3/dist/umd/photoswipe.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/photoswipe@5.4.3/dist/umd/photoswipe-lightbox.umd.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/photoswipe@5.4.3/dist/photoswipe.min.css" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('build/assets/app-51dFZLmS.css') }}">
    <script src="{{ asset('build/assets/app-nq-1ADXJ.js') }}"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased bg-base-200/50 dark:bg-base-200">
<x-nav sticky class="lg:hidden">

    <x-slot:actions>
        <label for="main-drawer" class="mr-3 lg:hidden">
            <x-icon name="o-bars-2" class="cursor-pointer" />
        </label>
    </x-slot:actions>
</x-nav>

<x-main>
    <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100 lg:bg-inherit">

        <x-menu activate-by-route>

            {{-- User --}}
            @if($user = auth()->user())
                <x-menu-separator />

                <x-list-item :item="$user" value="first_name" sub-value="username" no-separator no-hover class="-mx-2 !-my-2 rounded">
                    <x-slot:actions>
                        <x-dropdown>
                            <x-slot:trigger>
                                <x-button icon="o-cog-6-tooth" class="btn-circle btn-ghost btn-xs" />
                            </x-slot:trigger>
                            <x-menu-item icon="o-power" label="Logout" link="/logout" no-wire-navigate />
                            <x-menu-item icon="o-swatch" label="Toggle theme" @click.stop="$dispatch('mary-toggle-theme')" />
                        </x-dropdown>
                    </x-slot:actions>
                </x-list-item>
            @endif

            <x-menu-separator />

            <x-menu-item title="Dashboard" icon="o-chart-pie" link="/" />
            <x-menu-item title="Orders" icon="o-gift" link="/orders" />
            <x-menu-item title="Customers" icon="o-user" link="/users" />

            <x-menu-sub title="Warehouse" icon="o-wrench-screwdriver">
                <x-menu-item title="Brands" icon="o-tag" link="/brands" />
                <x-menu-item title="Categories" icon="o-hashtag" link="/categories" />
                <x-menu-item title="Products" icon="o-cube" link="/products" />
            </x-menu-sub>

            <x-menu-separator />

            <x-menu-item title="Search" @click.stop="$dispatch('mary-search-open')" icon="o-magnifying-glass" badge="Cmd + G" />
        </x-menu>
    </x-slot:sidebar>

    {{-- The `$slot` goes here --}}
    <x-slot:content>
        {{ $slot }}

        <div class="flex mt-5">
            {{--            <x-button label="Source code" icon="o-code-bracket" link="/support-us" class="btn-ghost" />--}}
            <x-button label="Built with maryUI" icon="o-heart" link="https://mary-ui.com" class="btn-ghost !text-pink-500" external />
        </div>
    </x-slot:content>
</x-main>

{{-- Toast --}}
<x-toast />

{{-- Spotlight --}}
<x-spotlight search-text="Order number, customer, products or any action ..." />

{{-- Theme Toggle--}}
<x-theme-toggle class="hidden" />
</body>
</html>
