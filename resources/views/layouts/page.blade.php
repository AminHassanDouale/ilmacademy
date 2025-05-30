<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('/favicon.ico') }}">
    <link rel="mask-icon" href="{{ asset('/favicon.ico') }}" color="#ff2d20">

    <!-- Add Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#3c8e5f', // Islamic green shade
                        secondary: '#f9b53b', // Gold/amber accent
                        base: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            200: '#e2e8f0',
                            300: '#cbd5e1',
                            400: '#94a3b8',
                            500: '#64748b',
                            600: '#475569',
                            700: '#334155',
                            800: '#1e293b',
                            900: '#0f172a',
                            950: '#020617'
                        }
                    },
                    fontFamily: {
                        display: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.47.0/tabler-icons.min.css" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        .title-gradient {
            background: linear-gradient(to right, #3c8e5f, #3c6e8e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .icon {
            font-family: 'tabler-icons';
            display: inline-block;
        }

        .dark {
            color-scheme: dark;
        }

       @layer utilities {
            .text-title {
                @apply text-base-800 dark:text-base-100;
            }
            .text-base {
                @apply text-base-600 dark:text-base-400;
            }
            .text-muted {
                @apply text-base-500;
            }
            .border-base {
                @apply border-base-300 dark:border-base-700;
            }
            .absolute-center {
                @apply absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2;
            }
        }

        @layer components {
            .title-gradient {
                @apply bg-gradient-to-b from-primary to-base-800 dark:from-primary dark:to-base-300 bg-clip-text text-transparent;
            }
        }
    </style>
</head>
<body class="antialiased bg-base-100 text-base-900 dark:bg-base-950 dark:text-base-100">
    <!-- Header Section -->
    <header class="fixed z-10 w-full bg-base-50/50 dark:bg-base-950/50 backdrop-blur-xl">
        <nav class="container relative flex flex-wrap items-center justify-start gap-4 px-4 mx-auto border-b h-14 border-base lg:gap-8">
            <a href="{{ route('welcome') }}">
                <img src="{{ asset('images/logo-mark.png') }}" alt="Logo" class="w-auto h-10 hover:animate-spin dark:invert">
            </a>

            <div id="mobile-menu" class="hidden md:block md:w-auto">
                <ul class="flex flex-col gap-2 p-4 font-medium md:p-0 md:flex-row md:space-x-8 rtl:space-x-reverse md:mt-0 md:border-0">
                    <a href="{{ route('welcome') }}#features" class="text-sm font-normal text-base-600 dark:text-base-400 hover:text-base-800 dark:hover:text-base-300">
                        Programs
                    </a>
                    <a href="{{ route('welcome') }}#pricing" class="text-sm font-normal text-base-600 dark:text-base-400 hover:text-base-800 dark:hover:text-base-300">
                        Plans
                    </a>
                    <a href="{{ route('about') }}" class="text-sm font-normal text-base-600 dark:text-base-400 hover:text-base-800 dark:hover:text-base-300">
                        About Us
                    </a>
                    <a href="{{ route('contact') }}" class="text-sm font-normal text-base-600 dark:text-base-400 hover:text-base-800 dark:hover:text-base-300">
                        Contact
                    </a>
                </ul>
            </div>

            <div class="flex gap-2 ml-auto">
                <!-- Theme Toggle -->
                <button id="theme-toggle" class="inline-flex items-center justify-center p-1 rounded-md hover:bg-base-200 dark:hover:bg-base-800">
                    <i class="ti ti-sun dark:hidden"></i>
                    <i class="hidden ti ti-moon dark:inline-block"></i>
                </button>

                <!-- Sign In Button -->
                <a href="{{ route('login') }}" class="inline-flex items-center justify-center gap-2 px-3 py-1 text-sm font-medium transition-colors duration-300 rounded-md hover:bg-base-200 dark:hover:bg-base-800">
                    Sign In
                </a>

                <!-- Sign Up Button -->
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 px-3 py-1 text-sm font-medium text-white transition-colors duration-300 rounded-md bg-primary hover:bg-primary/80 dark:bg-primary dark:text-white dark:hover:bg-primary/90">
                    Sign Up
                </a>
            </div>

            <button id="mobile-menu-button" class="inline-flex items-center justify-center gap-2 p-2 font-medium transition-colors duration-300 rounded-md hover:bg-base-200 dark:hover:bg-base-800 md:hidden">
                <i class="ti ti-menu-2"></i>
            </button>
        </nav>

        <!-- Mobile Menu (Hidden by Default) -->
        <div id="mobile-menu-dropdown" class="absolute right-0 hidden w-2/3 m-2 overflow-hidden border rounded-lg shadow-xl top-14 border-base dark:border-base-900 bg-base-50 dark:bg-base-900 md:hidden">
            <ul class="flex flex-col gap-2 p-4 font-medium">
                <a href="{{ route('welcome') }}#features" class="px-4 py-3 text-sm font-normal rounded-md text-base-600 dark:text-base-400 hover:bg-base-100 dark:hover:bg-base-950">
                    Programs
                </a>
                <a href="{{ route('welcome') }}#pricing" class="px-4 py-3 text-sm font-normal rounded-md text-base-600 dark:text-base-400 hover:bg-base-100 dark:hover:bg-base-950">
                    Plans
                </a>
                <a href="{{ route('about') }}" class="px-4 py-3 text-sm font-normal rounded-md text-base-600 dark:text-base-400 hover:bg-base-100 dark:hover:bg-base-950">
                    About Us
                </a>
                <a href="{{ route('contact') }}" class="px-4 py-3 text-sm font-normal rounded-md text-base-600 dark:text-base-400 hover:bg-base-100 dark:hover:bg-base-950">
                    Contact
                </a>
            </ul>
        </div>
    </header>

    <!-- Page Content -->
    <div class="pt-20">
        @yield('content')
    </div>

    <!-- Footer -->
    <footer class="pt-6 bg-base-100 dark:bg-base-900" id="footer">
        <div class="container px-4 mx-auto">
            <div class="flex flex-col items-center justify-between gap-4 py-6 md:flex-row">
                <img src="{{ asset('images/logo.png') }}" alt="Islamic Learning Platform Logo" class="w-auto h-10 opacity-70 hover:opacity-100 dark:invert">

                <div class="flex flex-row gap-4 text-sm">
                    <a href="{{ route('privacy') }}">Privacy Policy</a>
                    <a href="{{ route('terms') }}">Terms of Service</a>
                    <a href="{{ route('about') }}">About Us</a>
                    <a href="{{ route('contact') }}">Contact</a>
                </div>

                <div class="inline-flex items-center gap-2">
                    <a href="#" class="inline-flex items-center justify-center gap-2 p-3 font-medium transition-colors duration-300 rounded-md hover:bg-base-200 dark:hover:bg-base-800">
                        <i class="ti ti-brand-facebook"></i>
                    </a>
                    <a href="#" class="inline-flex items-center justify-center gap-2 p-3 font-medium transition-colors duration-300 rounded-md hover:bg-base-200 dark:hover:bg-base-800">
                        <i class="ti ti-brand-instagram"></i>
                    </a>
                    <a href="#" class="inline-flex items-center justify-center gap-2 p-3 font-medium transition-colors duration-300 rounded-md hover:bg-base-200 dark:hover:bg-base-800">
                        <i class="ti ti-brand-youtube"></i>
                    </a>
                    <a href="#" class="inline-flex items-center justify-center gap-2 p-3 font-medium transition-colors duration-300 rounded-md hover:bg-base-200 dark:hover:bg-base-800">
                        <i class="ti ti-brand-telegram"></i>
                    </a>
                </div>
            </div>

            <div class="flex justify-between py-4 text-center border-t border-base">
                <p class="text-sm">&copy; 2024 Islamic Learning Platform. All rights reserved.</p>
                <p class="text-sm">Designed with <span class="text-primary">❤</span> for the Muslim Ummah</p>
            </div>
        </div>
    </footer>

    <!-- JavaScript -->
    <script>
        // Theme Toggle
        document.addEventListener('DOMContentLoaded', function () {
            // Theme Toggle
            const themeToggle = document.getElementById('theme-toggle');

            // Check for saved theme preference or use user's system preference
            const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

            // Apply the theme
            document.documentElement.classList.toggle('dark', savedTheme === 'dark');

            // Theme toggle click handler
            themeToggle.addEventListener('click', function () {
                const currentTheme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

                document.documentElement.classList.toggle('dark', newTheme === 'dark');
                localStorage.setItem('theme', newTheme);
            });

            // Mobile Menu Toggle
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenuDropdown = document.getElementById('mobile-menu-dropdown');

            mobileMenuButton.addEventListener('click', function () {
                mobileMenuDropdown.classList.toggle('hidden');

                // Change icon between menu and close
                const icon = mobileMenuButton.querySelector('i');
                if (icon.classList.contains('ti-menu-2')) {
                    icon.classList.remove('ti-menu-2');
                    icon.classList.add('ti-x');
                } else {
                    icon.classList.remove('ti-x');
                    icon.classList.add('ti-menu-2');
                }
            });
        });
    </script>

    @stack('scripts')
</body>
</html>
