<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('/favicon.ico') }}">
    <link rel="mask-icon" href="{{ asset('/favicon.ico') }}" color="#ff2d20">

    {{-- Currency --}}
    <script type="text/javascript" src="https://cdn.jsdelivr.net/gh/robsontenorio/mary@0.44.2/libs/currency/currency.js"></script>

    {{-- ChartJS --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    {{-- Flatpickr --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    {{-- Cropper.js --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" />

    {{-- Sortable.js --}}
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.1/Sortable.min.js"></script>

    {{-- TinyMCE --}}
    <script src="https://cdn.tiny.cloud/1/16eam5yke73excub2z217rcau87xhcbs0pxs4y8wmr5r7z6x/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

    {{-- PhotoSwipe --}}
    <script src="https://cdn.jsdelivr.net/npm/photoswipe@5.4.3/dist/umd/photoswipe.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/photoswipe@5.4.3/dist/umd/photoswipe-lightbox.umd.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/photoswipe@5.4.3/dist/photoswipe.min.css" rel="stylesheet">

    {{-- Use only Vite directive for assets --}}
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
            {{-- User Profile Section --}}
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
                <x-menu-separator />
            @endif

            {{-- Dashboard - Available to all authenticated users --}}
            <x-menu-item title="Dashboard" icon="o-chart-pie" link="/dashboard" />

            {{-- Admin Menu Items --}}
            @if(auth()->user()?->hasRole('admin'))
                <x-menu-sub title="User Management" icon="o-users">
                    <x-menu-item title="Users" icon="o-user" link="/admin/users" />
                    <x-menu-item title="Roles" icon="o-shield-check" link="/admin/roles" />
                    <x-menu-item title="Teachers" icon="o-academic-cap" link="/admin/teachers" />
                    <x-menu-item title="Parents" icon="o-heart" link="/admin/parents" />
                    <x-menu-item title="Children" icon="o-face-smile" link="/admin/children" />
                </x-menu-sub>

                <x-menu-sub title="Academic Management" icon="o-book-open">
                    <x-menu-item title="Curricula" icon="o-document-text" link="/admin/curricula" />
                    <x-menu-item title="Subjects" icon="o-squares-2x2" link="/admin/subjects" />
                    <x-menu-item title="Academic Years" icon="o-calendar-days" link="/admin/academic-years" />
                    <x-menu-item title="Timetable" icon="o-clock" link="/admin/timetable" />
                </x-menu-sub>

                <x-menu-sub title="Enrollments & Finance" icon="o-banknotes">
                    <x-menu-item title="Enrollments" icon="o-user-plus" link="/admin/enrollments" />
                    <x-menu-item title="Payment Plans" icon="o-credit-card" link="/admin/payment-plans" />
                    <x-menu-item title="Invoices" icon="o-document-currency-dollar" link="/admin/invoices" />
                </x-menu-sub>

                <x-menu-sub title="Reports & Analytics" icon="o-chart-bar">
                    <x-menu-item title="Student Reports" icon="o-user-group" link="/admin/reports/students" />
                    <x-menu-item title="Attendance Reports" icon="o-check-circle" link="/admin/reports/attendance" />
                    <x-menu-item title="Exam Reports" icon="o-document-check" link="/admin/reports/exams" />
                    <x-menu-item title="Financial Reports" icon="o-currency-dollar" link="/admin/reports/finances" />
                </x-menu-sub>

                <x-menu-item title="Activity Logs" icon="o-eye" link="/admin/activity-logs" />
            @endif

            {{-- Teacher Menu Items --}}
            @if(auth()->user()?->hasRole('teacher'))
                <x-menu-sub title="Teaching" icon="o-academic-cap">
                    <x-menu-item title="My Subjects" icon="o-book-open" link="/teacher/subjects" />
                    <x-menu-item title="Sessions" icon="o-presentation-chart-bar" link="/teacher/sessions" />
                    <x-menu-item title="Take Attendance" icon="o-check-circle" link="/teacher/attendance" />
                    <x-menu-item title="Exams" icon="o-document-check" link="/teacher/exams" />
                </x-menu-sub>
                <x-menu-item title="My Profile" icon="o-user-circle" link="/teacher/profile" />
            @endif

            {{-- Parent Menu Items --}}
            @if(auth()->user()?->hasRole('parent'))
                <x-menu-sub title="My Children" icon="o-heart">
                    <x-menu-item title="Children List" icon="o-face-smile" link="/parent/children" />
                    <x-menu-item title="Add Child" icon="o-plus-circle" link="/parent/children/create" />
                </x-menu-sub>

                <x-menu-sub title="Academic" icon="o-book-open">
                    <x-menu-item title="Enrollments" icon="o-user-plus" link="/parent/enrollments" />
                    <x-menu-item title="Attendance" icon="o-check-circle" link="/parent/attendance" />
                    <x-menu-item title="Exams" icon="o-document-check" link="/parent/exams" />
                </x-menu-sub>

                <x-menu-item title="Invoices" icon="o-document-currency-dollar" link="/parent/invoices" />
            @endif

            {{-- Student Menu Items --}}
            @if(auth()->user()?->hasRole('student'))
                <x-menu-sub title="My Studies" icon="o-book-open">
                    <x-menu-item title="My Enrollments" icon="o-user-plus" link="/student/enrollments" />
                    <x-menu-item title="Sessions" icon="o-presentation-chart-bar" link="/student/sessions" />
                    <x-menu-item title="My Exams" icon="o-document-check" link="/student/exams" />
                </x-menu-sub>

                <x-menu-item title="My Invoices" icon="o-document-currency-dollar" link="/student/invoices" />
                <x-menu-item title="My Profile" icon="o-user-circle" link="/student/profile" />
            @endif

            {{-- Common Menu Items for All Authenticated Users --}}
            @auth
                <x-menu-separator />
                <x-menu-item title="Calendar" icon="o-calendar" link="/calendar" />
                <x-menu-item title="Notifications" icon="o-bell" link="/notifications" />
                <x-menu-item title="Profile Settings" icon="o-cog-6-tooth" link="/profile" />
                <x-menu-separator />
                <x-menu-item title="Search" @click.stop="$dispatch('mary-search-open')" icon="o-magnifying-glass" badge="Cmd + G" />
            @endauth
        </x-menu>
    </x-slot:sidebar>

    {{-- The `$slot` goes here --}}
    <x-slot:content>
        {{ $slot }}

        <div class="flex mt-5">
            <x-button label="Built with {{ config('app.name', 'maryUI') }}" icon="o-heart" link="https://mary-ui.com" class="btn-ghost !text-pink-500" external />
        </div>
    </x-slot:content>
</x-main>

{{-- Toast --}}
<x-toast />

{{-- Spotlight --}}
<x-spotlight search-text="Search users, enrollments, invoices, or any action..." />

{{-- Theme Toggle --}}
<x-theme-toggle class="hidden" />
</body>
</html>
