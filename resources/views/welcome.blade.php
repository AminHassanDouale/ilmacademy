<?php

use function Livewire\Volt\{state};

?>

<div>
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
            <a href="/">
                <img src="{{ asset('images/logo-mark.png') }}" alt="Logo" class="w-auto h-10 hover:animate-spin dark:invert">
            </a>

            <div id="mobile-menu" class="hidden md:block md:w-auto">
                <ul class="flex flex-col gap-2 p-4 font-medium md:p-0 md:flex-row md:space-x-8 rtl:space-x-reverse md:mt-0 md:border-0">
                    <a href="#features" class="text-sm font-normal text-base-600 dark:text-base-400 hover:text-base-800 dark:hover:text-base-300">
                        Programs
                    </a>
                    <a href="#pricing" class="text-sm font-normal text-base-600 dark:text-base-400 hover:text-base-800 dark:hover:text-base-300">
                        Plans
                    </a>
                    <a href="#testimonials" class="text-sm font-normal text-base-600 dark:text-base-400 hover:text-base-800 dark:hover:text-base-300">
                        Testimonials
                    </a>
                    <a href="#faqs" class="text-sm font-normal text-base-600 dark:text-base-400 hover:text-base-800 dark:hover:text-base-300">
                        FAQs
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
                <a href="#features" class="px-4 py-3 text-sm font-normal rounded-md text-base-600 dark:text-base-400 hover:bg-base-100 dark:hover:bg-base-950">
                    Programs
                </a>
                <a href="#pricing" class="px-4 py-3 text-sm font-normal rounded-md text-base-600 dark:text-base-400 hover:bg-base-100 dark:hover:bg-base-950">
                    Plans
                </a>
                <a href="#testimonials" class="px-4 py-3 text-sm font-normal rounded-md text-base-600 dark:text-base-400 hover:bg-base-100 dark:hover:bg-base-950">
                    Testimonials
                </a>
                <a href="#faqs" class="px-4 py-3 text-sm font-normal rounded-md text-base-600 dark:text-base-400 hover:bg-base-100 dark:hover:bg-base-950">
                    FAQs
                </a>
            </ul>
        </div>
    </header>

    <!-- Hero Section -->
    <section id="home">
        <div class="container px-4 mx-auto">
            <div class="flex flex-col items-center justify-center min-h-screen">
                <div class="flex flex-col items-center justify-center max-w-3xl gap-4 pb-12 mx-auto mt-32 text-center">
                    <!-- Badge -->
                    <div class="inline-flex flex-row-reverse items-center gap-1 px-3 py-1 text-xs font-medium rounded-full bg-base-200 dark:bg-base-800">
                        <i class="ti ti-arrow-right"></i>
                        <span>✨ Explore Our Programs</span>
                    </div>

                    <h1 class="text-5xl font-semibold sm:text-6xl font-display title-gradient">
                        Comprehensive Islamic Education
                    </h1>

                    <p class="text-xl">
                        Join our structured learning platform for Quran memorization, Hadith studies, Tafsir,
                        and Islamic sciences tailored for all age groups and learning levels.
                    </p>

                    <div class="flex flex-wrap items-center justify-center gap-4 mt-8">
                        <a href="#features" class="inline-flex items-center justify-center gap-2 px-4 py-2 font-medium text-white transition-colors duration-300 rounded-md bg-primary hover:bg-primary/80">
                            Explore Programs
                            <i class="ti ti-book"></i>
                        </a>

                        <a href="#" class="inline-flex items-center justify-center gap-2 px-4 py-2 font-medium transition-colors duration-300 rounded-md text-base-900 hover:underline dark:text-base-50">
                            Learn More
                            <i class="ti ti-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <div>
                    <img src="{{ asset('images/tablet-mockup.png') }}" alt="Islamic Learning Platform Screenshot" class="w-full h-auto">
                </div>

                <div class="text-sm">Trusted by Islamic Schools and Organizations Worldwide</div>

                <!-- Brand Logos -->
                <div class="flex flex-wrap items-center justify-center w-full gap-px">
                    <img src="{{ asset('images/logoipsum-288.svg') }}" alt="Partner Logo" class="m-4 h-7 filter grayscale opacity-70 hover:opacity-100 hover:filter-none md:m-8">
                    <img src="{{ asset('images/logoipsum-317.svg') }}" alt="Partner Logo" class="m-4 h-7 filter grayscale opacity-70 hover:opacity-100 hover:filter-none md:m-8">
                    <img src="{{ asset('images/logoipsum-321.svg') }}" alt="Partner Logo" class="m-4 h-7 filter grayscale opacity-70 hover:opacity-100 hover:filter-none md:m-8">
                    <img src="{{ asset('images/logoipsum-323.svg') }}" alt="Partner Logo" class="m-4 h-7 filter grayscale opacity-70 hover:opacity-100 hover:filter-none md:m-8">
                    <img src="{{ asset('images/logoipsum-330.svg') }}" alt="Partner Logo" class="m-4 h-7 filter grayscale opacity-70 hover:opacity-100 hover:filter-none md:m-8">
                    <img src="{{ asset('images/logoipsum-331.svg') }}" alt="Partner Logo" class="m-4 h-7 filter grayscale opacity-70 hover:opacity-100 hover:filter-none md:m-8">
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-24 bg-base-100 dark:bg-base-900">
        <div class="container px-4 mx-auto">
            <!-- Section Heading -->
            <div class="flex flex-col items-center max-w-3xl gap-3 mx-auto text-center">
                <h2 class="text-4xl font-semibold lg:text-5xl font-display text-title">
                    Our Comprehensive Platform Features
                </h2>
                <p>
                    Explore the wide range of powerful features designed to enhance Islamic education for students of all ages.
                    From Quran memorization to Hadith studies, we provide the tools needed for effective learning.
                </p>
            </div>

            <!-- Feature Cards Grid -->
            <div class="grid grid-cols-1 gap-4 my-10 md:grid-cols-3">
                <!-- Feature Card 1 -->
                <div class="p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <div class="p-2 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-12">
                        <i class="ti ti-users"></i>
                    </div>
                    <h3 class="mb-2 text-lg font-semibold">Multi-Profile System</h3>
                    <p class="text-base-600 dark:text-base-400">Tailored experiences for teachers, parents, children, and individual learners with personalized dashboards.</p>
                </div>

                <!-- Feature Card 2 -->
                <div class="p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <div class="p-2 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-12">
                        <i class="ti ti-book"></i>
                    </div>
                    <h3 class="mb-2 text-lg font-semibold">Program Management</h3>
                    <p class="text-base-600 dark:text-base-400">Curriculum-based programs for Quran memorization, Tajweed, Tafsir, and Hadith studies tailored by age and level.</p>
                </div>

                <!-- Feature Card 3 -->
                <div class="p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <div class="p-2 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-12">
                        <i class="ti ti-chart-line"></i>
                    </div>
                    <h3 class="mb-2 text-lg font-semibold">Study Tracking</h3>
                    <p class="text-base-600 dark:text-base-400">Monitor progress, track attendance, and receive notifications for lessons, assessments, and homework.</p>
                </div>

                <!-- Feature Card 4 -->
                <div class="p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <div class="p-2 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-12">
                        <i class="ti ti-clipboard-check"></i>
                    </div>
                    <h3 class="mb-2 text-lg font-semibold">Assessments & Exams</h3>
                    <p class="text-base-600 dark:text-base-400">Periodic quizzes, oral recitation assessments, and written exams with clear scheduling and preparation.</p>
                </div>

                <!-- Feature Card 5 -->
                <div class="p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <div class="p-2 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-12">
                        <i class="ti ti-message-circle-2"></i>
                    </div>
                    <h3 class="mb-2 text-lg font-semibold">Feedback & Results</h3>
                    <p class="text-base-600 dark:text-base-400">Detailed grading reports with teacher feedback and improvement suggestions accessible to students and parents.</p>
                </div>

                <!-- Feature Card 6 -->
                <div class="p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <div class="p-2 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-12">
                        <i class="ti ti-certificate"></i>
                    </div>
                    <h3 class="mb-2 text-lg font-semibold">Certificates & Recognition</h3>
                    <p class="text-base-600 dark:text-base-400">Earn certificates for completed levels, programs, and achievements to celebrate your Islamic learning journey.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Large Feature Section 1 - Quran Programs -->
    <section class="bg-base-100 dark:bg-base-900">
        <div class="container px-4 mx-auto">
            <div class="grid grid-cols-1 gap-6 py-10 md:grid-cols-2 md:gap-20">
                <div class="py-10">
                    <!-- Section Heading -->
                    <div class="flex flex-col items-start max-w-3xl gap-3 text-left">
                        <h2 class="text-4xl font-semibold lg:text-5xl font-display text-title">
                            Quran Learning & Memorization
                        </h2>
                        <p>
                            Structured programs to help you connect with the Quran through proper recitation,
                            memorization techniques, and understanding.
                        </p>
                    </div>

                    <!-- Feature List -->
                    <div class="flex flex-col gap-4 mt-4 lg:mt-10">
                        <!-- Feature Item 1 -->
                        <div class="p-0 bg-transparent">
                            <div class="p-1 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-8">
                                <i class="ti ti-check"></i>
                            </div>
                            <h3 class="mb-2 text-lg font-semibold">Tajweed Rules & Application</h3>
                            <p class="text-base-600 dark:text-base-400">Master proper pronunciation and recitation rules with interactive lessons.</p>
                        </div>

                        <!-- Feature Item 2 -->
                        <div class="p-0 bg-transparent">
                            <div class="p-1 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-8">
                                <i class="ti ti-check"></i>
                            </div>
                            <h3 class="mb-2 text-lg font-semibold">Hifz (Memorization) Program</h3>
                            <p class="text-base-600 dark:text-base-400">Systematic approach to memorizing the Quran with revision techniques.</p>
                        </div>

                        <!-- Feature Item 3 -->
                        <div class="p-0 bg-transparent">
                            <div class="p-1 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-8">
                                <i class="ti ti-check"></i>
                            </div>
                            <h3 class="mb-2 text-lg font-semibold">Audio Guidance & Feedback</h3>
                            <p class="text-base-600 dark:text-base-400">Record your recitation and receive personalized teacher feedback.</p>
                        </div>
                    </div>
                </div>

                <!-- Image Section -->
                <div class="relative flex items-center group isolate">
                    <div class="absolute w-3/4 duration-200 ease-in-out -translate-x-1/2 -translate-y-1/2 rounded-full left-1/2 top-1/2 group-hover:w-2/3 aspect-square bg-base-200 dark:bg-base-800"></div>
                    <img src="{{ asset('images/phone-mockup.png') }}" alt="Quran Learning Interface" class="object-contain w-full duration-300 ease-in-out aspect-square rotate-6 hover:rotate-0">
                </div>
            </div>
        </div>
    </section>

    <!-- Large Feature Section 2 (Reversed) - Hadith & Islamic Studies -->
    <section class="bg-base-100 dark:bg-base-900">
        <div class="container px-4 mx-auto">
            <div class="grid grid-cols-1 gap-6 py-10 md:grid-cols-2 md:gap-20">
                <!-- Image Section (Ordered First on MD Screens) -->
                <div class="relative flex items-center group isolate md:order-first">
                    <div class="absolute w-3/4 duration-200 ease-in-out -translate-x-1/2 -translate-y-1/2 rounded-full left-1/2 top-1/2 group-hover:w-2/3 aspect-square bg-base-200 dark:bg-base-800"></div>
                    <img src="{{ asset('images/phone-mockup.png') }}" alt="Hadith Studies Interface" class="object-contain w-full duration-300 ease-in-out aspect-square -rotate-6 hover:rotate-0">
                </div>

                <div class="py-10">
                    <!-- Section Heading -->
                    <div class="flex flex-col items-start max-w-3xl gap-3 text-left">
                        <h2 class="text-4xl font-semibold lg:text-5xl font-display text-title">
                            Hadith & Islamic Sciences
                        </h2>
                        <p>
                            Explore the teachings of the Prophet Muhammad (PBUH) and deepen your understanding
                            of Islamic principles and practices.
                        </p>
                    </div>

                    <!-- Feature List -->
                    <div class="flex flex-col gap-4 mt-4 lg:mt-10">
                        <!-- Feature Item 1 -->
                        <div class="p-0 bg-transparent">
                            <div class="p-1 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-8">
                                <i class="ti ti-check"></i>
                            </div>
                            <h3 class="mb-2 text-lg font-semibold">Hadith Studies by Collection</h3>
                            <p class="text-base-600 dark:text-base-400">Learn from authenticated collections with explanations and context.</p>
                        </div>

                        <!-- Feature Item 2 -->
                        <div class="p-0 bg-transparent">
                            <div class="p-1 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-8">
                                <i class="ti ti-check"></i>
                            </div>
                            <h3 class="mb-2 text-lg font-semibold">Tafsir & Quranic Exegesis</h3>
                            <p class="text-base-600 dark:text-base-400">Understand the meanings and context of Quranic verses with scholarly insights.</p>
                        </div>

                        <!-- Feature Item 3 -->
                        <div class="p-0 bg-transparent">
                            <div class="p-1 mb-4 bg-base-100 dark:bg-base-900 rounded-xl text-primary size-8">
                                <i class="ti ti-check"></i>
                            </div>
                            <h3 class="mb-2 text-lg font-semibold">Islamic History & Civilization</h3>
                            <p class="text-base-600 dark:text-base-400">Learn about the rich Islamic heritage and contributions to global knowledge.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-24 bg-base-100 dark:bg-base-900">
        <div class="container min-h-screen px-4 mx-auto">
            <!-- Section Heading -->
            <div class="flex flex-col items-center max-w-3xl gap-3 mx-auto text-center">
                <div class="inline-flex flex-row items-center gap-1 px-3 py-1 text-xs font-medium rounded-full bg-base-200 dark:bg-base-800">
                    <i class="ti ti-credit-card"></i>
                    <span>Subscription Plans</span>
                </div>

                <h2 class="text-4xl font-semibold lg:text-5xl font-display text-title">
                    Choose Your Learning Journey
                </h2>

                <p>
                    Select a plan that works best for your learning goals. All plans include access to our core platform features.
                </p>
            </div>

            <!-- Pricing Toggle -->
            <div class="my-10 text-center">
                <div class="inline-flex p-1 rounded-lg bg-base-200 dark:bg-base-800">
                    <button id="monthly-btn" class="px-4 py-2 text-sm font-medium capitalize rounded-md text-base-600 dark:text-base-400 hover:text-base-900 dark:hover:text-base-200">
                        Monthly
                    </button>
                    <button id="yearly-btn" class="px-4 py-2 text-sm font-medium capitalize bg-white rounded-md shadow-sm dark:bg-base-950">
                        Yearly
                    </button>
                </div>
                <div class="mt-4 text-sm">15% Discount on Yearly Subscription</div>
            </div>

            <!-- Pricing Cards -->
            <div class="grid max-w-5xl grid-cols-1 gap-6 mx-auto sm:grid-cols-3">
                <!-- Basic Plan -->
                <div class="flex flex-col h-full p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-2xl hover:shadow-lg">
                    <div class="mb-4">
                        <h3 class="text-2xl font-semibold">Basic</h3>
                        <p class="text-base-600 dark:text-base-400">For individual students</p>
                    </div>
                    <div class="mb-6">
                        <div class="flex items-end gap-1">
                            <span class="text-4xl font-bold">$19</span>
                            <span class="mb-1 text-base-600 dark:text-base-400">per month</span>
                        </div>
                    </div>
                    <ul class="flex-grow mb-6 space-y-3">
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>Access to all basic courses</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>Progress tracking tools</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>Basic assessment tools</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>Community forum access</span>
                        </li>
                    </ul>
                    <div class="mt-auto">
                        <a href="#" class="inline-flex items-center justify-center w-full gap-2 px-4 py-2 font-medium transition-colors duration-300 rounded-md bg-base-50 text-base-950 hover:bg-base-200 dark:bg-base-800 dark:text-base-50 dark:hover:bg-base-700">
                            Get started
                            <i class="ti ti-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <!-- Standard Plan -->
                <div class="flex flex-col h-full p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-2xl hover:shadow-lg">
                    <div class="mb-4">
                        <h3 class="text-2xl font-semibold">Standard</h3>
                        <p class="text-base-600 dark:text-base-400">For dedicated learners</p>
                    </div>
                    <div class="mb-6">
                        <div class="flex items-end gap-1">
                            <span class="text-4xl font-bold monthly-price">$39</span>
                            <span class="hidden text-4xl font-bold yearly-price">$33</span>
                            <span class="mb-1 text-base-600 dark:text-base-400">per month</span>
                        </div>
                    </div>
                    <ul class="flex-grow mb-6 space-y-3">
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>All Basic plan features</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>Weekly group sessions</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>Detailed feedback reports</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>Advanced learning materials</span>
                            </li>
                    </ul>
                    <div class="mt-auto">
                        <a href="#" class="inline-flex items-center justify-center w-full gap-2 px-4 py-2 font-medium text-white transition-colors duration-300 rounded-md bg-primary hover:bg-primary/80">
                            Start free trial
                            <i class="ti ti-books"></i>
                        </a>
                    </div>
                </div>

                <!-- Premium Plan -->
                <div class="flex flex-col h-full p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-2xl hover:shadow-lg">
                    <div class="mb-4">
                        <h3 class="text-2xl font-semibold">Premium</h3>
                        <p class="text-base-600 dark:text-base-400">For serious scholars</p>
                    </div>
                    <div class="mb-6">
                        <div class="flex items-end gap-1">
                            <span class="text-4xl font-bold monthly-price">$79</span>
                            <span class="hidden text-4xl font-bold yearly-price">$67</span>
                            <span class="mb-1 text-base-600 dark:text-base-400">per month</span>
                        </div>
                    </div>
                    <ul class="flex-grow mb-6 space-y-3">
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>All Standard plan features</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>One-on-one teacher sessions</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>Personalized learning plan</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-primary">✓</span>
                            <span>Advanced certification options</span>
                        </li>
                    </ul>
                    <div class="mt-auto">
                        <a href="#" class="inline-flex items-center justify-center w-full gap-2 px-4 py-2 font-medium transition-colors duration-300 rounded-md bg-base-50 text-base-950 hover:bg-base-200 dark:bg-base-800 dark:text-base-50 dark:hover:bg-base-700">
                            Contact us
                            <i class="ti ti-message-circle"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section id="testimonials" class="min-h-screen py-24 bg-base-100 dark:bg-base-900">
        <div class="container px-4 mx-auto">
            <!-- Section Heading -->
            <div class="flex flex-col items-center max-w-3xl gap-3 mx-auto text-center">
                <div class="inline-flex flex-row items-center gap-1 px-3 py-1 text-xs font-medium rounded-full bg-base-200 dark:bg-base-800">
                    <i class="ti ti-heart"></i>
                    <span>TESTIMONIALS</span>
                </div>

                <h2 class="text-4xl font-semibold lg:text-5xl font-display text-title">
                    Success Stories from Our Students
                </h2>

                <p>
                    Hear from our community of learners about how our platform has transformed their Islamic education journey
                </p>
            </div>

            <!-- Testimonials Grid -->
            <div class="grid grid-cols-1 gap-6 my-10 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                <!-- Testimonial 1 -->
                <div class="flex flex-col h-full p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <p class="flex-grow mb-4 text-base-600 dark:text-base-400">"The Quran memorization program has transformed my relationship with the Quran. The structured approach and teacher feedback made all the difference in my journey."</p>
                    <div class="flex items-center gap-3">
                        <img src="https://i.pravatar.cc/150?img=1" alt="Ahmed M." class="object-cover rounded-full size-10">
                        <div>
                            <h4 class="font-medium">Ahmed M.</h4>
                            <p class="text-sm text-base-600 dark:text-base-400">Hifz Student</p>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 2 -->
                <div class="flex flex-col h-full p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <p class="flex-grow mb-4 text-base-600 dark:text-base-400">"As a parent, the progress tracking and detailed reports have allowed me to stay engaged with my children's Islamic education. The teachers are excellent and very supportive."</p>
                    <div class="flex items-center gap-3">
                        <img src="https://i.pravatar.cc/150?img=2" alt="Fatima H." class="object-cover rounded-full size-10">
                        <div>
                            <h4 class="font-medium">Fatima H.</h4>
                            <p class="text-sm text-base-600 dark:text-base-400">Parent of 3 Students</p>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 3 -->
                <div class="flex flex-col h-full p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <p class="flex-grow mb-4 text-base-600 dark:text-base-400">"The Hadith studies program has given me a deeper understanding of the Prophet's teachings. The interactive lessons and assessments keep me engaged and motivated to learn more."</p>
                    <div class="flex items-center gap-3">
                        <img src="https://i.pravatar.cc/150?img=3" alt="Yusuf K." class="object-cover rounded-full size-10">
                        <div>
                            <h4 class="font-medium">Yusuf K.</h4>
                            <p class="text-sm text-base-600 dark:text-base-400">Adult Learner</p>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 4 -->
                <div class="flex flex-col h-full p-6 transition-all duration-300 ease-in-out bg-white dark:bg-base-950 rounded-xl hover:shadow-lg">
                    <p class="flex-grow mb-4 text-base-600 dark:text-base-400">"The tajweed program helped me correct my recitation mistakes that I wasn't even aware of. Now I feel confident reciting the Quran in any setting."</p>
                    <div class="flex items-center gap-3">
                        <img src="https://i.pravatar.cc/150?img=4" alt="Aisha R." class="object-cover rounded-full size-10">
                        <div>
                            <h4 class="font-medium">Aisha R.</h4>
                            <p class="text-sm text-base-600 dark:text-base-400">Tajweed Student</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Share Button -->
            <div class="mt-12 text-center">
                <a href="#" class="inline-flex items-center justify-center gap-2 px-4 py-2 font-medium transition-colors duration-300 bg-white rounded-md text-base-950 hover:bg-base-50">
                    <i class="ti ti-share"></i>
                    Share Your Learning Story
                </a>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section id="faqs" class="bg-base-100 dark:bg-base-900">
        <div class="container px-4 mx-auto">
            <div class="grid grid-cols-12 gap-4 p-4 bg-white lg:gap-20 dark:bg-base-950 sm:p-8 md:p-20 rounded-3xl">
                <div class="col-span-12 lg:col-span-5">
                    <!-- Section Heading -->
                    <div class="flex flex-col items-start max-w-3xl gap-3 text-left">
                        <h2 class="text-4xl font-semibold lg:text-5xl font-display text-title">
                            Frequently Asked Questions
                        </h2>
                        <p>
                            Find answers to common questions about our Islamic learning platform. If you don't see your question here, feel free to reach out to our support team.
                        </p>
                        <div class="flex items-center justify-start gap-4 mt-8">
                            <a href="#" class="inline-flex items-center justify-center gap-2 px-4 py-2 font-medium transition-colors duration-300 rounded-md text-primary hover:underline">
                                Contact Support
                                <i class="ti ti-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 lg:col-span-7">
                    <!-- FAQ Accordion -->
                    <div class="flex flex-col gap-4" id="faq-accordion">
                        <!-- FAQ Item 1 -->
                        <div class="pb-4 border-b border-base">
                            <button class="flex items-center justify-between w-full py-2 font-medium text-left faq-toggle" data-index="0">
                                <span>How do the live sessions work?</span>
                                <span class="text-xl">+</span>
                            </button>
                            <div class="mt-2 overflow-hidden transition-all duration-300 ease-in-out opacity-0 max-h-0 faq-content">
                                <p class="text-base-600 dark:text-base-400">Our live sessions are conducted via integrated video conferencing. You'll receive a notification before each scheduled session, and can join directly from your dashboard. Sessions can be one-on-one or in small groups, depending on your subscription plan.</p>
                            </div>
                        </div>

                        <!-- FAQ Item 2 -->
                        <div class="pb-4 border-b border-base">
                            <button class="flex items-center justify-between w-full py-2 font-medium text-left faq-toggle" data-index="1">
                                <span>Can parents monitor their children's progress?</span>
                                <span class="text-xl">+</span>
                            </button>
                            <div class="mt-2 overflow-hidden transition-all duration-300 ease-in-out opacity-0 max-h-0 faq-content">
                                <p class="text-base-600 dark:text-base-400">Yes, parents have access to a dedicated dashboard where they can monitor attendance, homework completion, assessment results, and teacher feedback for all their children in one place. You can also set up automated progress reports to be emailed to you.</p>
                            </div>
                        </div>

                        <!-- FAQ Item 3 -->
                        <div class="pb-4 border-b border-base">
                            <button class="flex items-center justify-between w-full py-2 font-medium text-left faq-toggle" data-index="2">
                                <span>What age groups are your programs designed for?</span>
                                <span class="text-xl">+</span>
                            </button>
                            <div class="mt-2 overflow-hidden transition-all duration-300 ease-in-out opacity-0 max-h-0 faq-content">
                                <p class="text-base-600 dark:text-base-400">Our platform offers programs for all age groups, from children (ages 5+) to adults. The curriculum, teaching methods, and materials are tailored to be age-appropriate, ensuring effective learning at every stage. We have special programs designed specifically for young learners to make Islamic education engaging and fun.</p>
                            </div>
                        </div>

                        <!-- FAQ Item 4 -->
                        <div class="pb-4 border-b border-base">
                            <button class="flex items-center justify-between w-full py-2 font-medium text-left faq-toggle" data-index="3">
                                <span>Are the teachers qualified in Islamic studies?</span>
                                <span class="text-xl">+</span>
                            </button>
                            <div class="mt-2 overflow-hidden transition-all duration-300 ease-in-out opacity-0 max-h-0 faq-content">
                                <p class="text-base-600 dark:text-base-400">All our teachers are certified and have formal education in Islamic studies from recognized institutions. Many hold ijazahs (chains of authentication) in Quran recitation and memorization. We maintain strict quality standards and regularly evaluate our teachers' performance to ensure the highest quality of education.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 bg-base-100 dark:bg-base-900 md:px-4">
        <div class="container px-4 py-10 mx-auto rounded-3xl">
            <div class="flex flex-col items-center justify-center max-w-2xl mx-auto">
                <!-- Section Heading -->
                <div class="flex flex-col items-center max-w-3xl gap-3 mx-auto text-center">
                    <h2 class="text-4xl font-semibold lg:text-5xl font-display text-title">
                        Begin Your Islamic Learning Journey Today
                    </h2>
                    <p>
                        Join thousands of students worldwide who are enriching their understanding of Islam through our comprehensive learning platform.
                    </p>
                    <div class="flex items-center justify-center gap-4 mt-8">
                        <a href="#" class="inline-flex items-center justify-center gap-2 px-4 py-2 font-medium text-white transition-colors duration-300 rounded-md bg-primary hover:bg-primary/80">
                            Start 7-Day Free Trial
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

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
                <p class="text-sm">&copy; 2025 IlmAcademie. All rights reserved.</p>
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

            // Pricing Toggle
            const monthlyBtn = document.getElementById('monthly-btn');
            const yearlyBtn = document.getElementById('yearly-btn');
            const monthlyPrices = document.querySelectorAll('.monthly-price');
            const yearlyPrices = document.querySelectorAll('.yearly-price');

            yearlyBtn.addEventListener('click', function () {
                yearlyBtn.classList.add('bg-white', 'dark:bg-base-950', 'shadow-sm');
                yearlyBtn.classList.remove('text-base-600', 'dark:text-base-400', 'hover:text-base-900', 'dark:hover:text-base-200');

                monthlyBtn.classList.remove('bg-white', 'dark:bg-base-950', 'shadow-sm');
                monthlyBtn.classList.add('text-base-600', 'dark:text-base-400', 'hover:text-base-900', 'dark:hover:text-base-200');

                monthlyPrices.forEach(price => price.classList.add('hidden'));
                yearlyPrices.forEach(price => price.classList.remove('hidden'));
            });

            monthlyBtn.addEventListener('click', function () {
                monthlyBtn.classList.add('bg-white', 'dark:bg-base-950', 'shadow-sm');
                monthlyBtn.classList.remove('text-base-600', 'dark:text-base-400', 'hover:text-base-900', 'dark:hover:text-base-200');

                yearlyBtn.classList.remove('bg-white', 'dark:bg-base-950', 'shadow-sm');
                yearlyBtn.classList.add('text-base-600', 'dark:text-base-400', 'hover:text-base-900', 'dark:hover:text-base-200');

                yearlyPrices.forEach(price => price.classList.add('hidden'));
                monthlyPrices.forEach(price => price.classList.remove('hidden'));
            });

            // FAQ Accordion
            const faqToggles = document.querySelectorAll('.faq-toggle');
            const faqContents = document.querySelectorAll('.faq-content');

            faqToggles.forEach((toggle, index) => {
                toggle.addEventListener('click', function () {
                    const wasOpen = toggle.querySelector('span:last-child').textContent === '−';

                    // Reset all
                    faqToggles.forEach(t => {
                        t.querySelector('span:last-child').textContent = '+';
                    });

                    faqContents.forEach(content => {
                        content.classList.add('max-h-0', 'opacity-0');
                        content.classList.remove('max-h-96', 'opacity-100');
                    });

                    // If it wasn't open before, open it
                    if (!wasOpen) {
                        toggle.querySelector('span:last-child').textContent = '−';
                        faqContents[index].classList.remove('max-h-0', 'opacity-0');
                        faqContents[index].classList.add('max-h-96', 'opacity-100');
                    }
                });
            });

            // Show first FAQ by default
            faqToggles[0].querySelector('span:last-child').textContent = '−';
            faqContents[0].classList.remove('max-h-0', 'opacity-0');
            faqContents[0].classList.add('max-h-96', 'opacity-100');
        });
    </script>
</div>
