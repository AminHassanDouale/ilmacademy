@extends('layouts.page')

@section('content')
<div class="container px-4 mx-auto">
    <!-- Hero Section -->
    <div class="py-12 mb-8 text-center">
        <h1 class="mb-4 text-4xl font-bold md:text-5xl font-display title-gradient">About Our Islamic Learning Platform</h1>
        <p class="max-w-3xl mx-auto text-xl">
            Dedicated to providing comprehensive Islamic education that combines traditional knowledge with modern teaching methods.
        </p>
    </div>

    <!-- Our Mission -->
    <div class="max-w-4xl mx-auto mb-16">
        <div class="p-8 bg-white shadow-sm rounded-xl dark:bg-base-900">
            <h2 class="mb-6 text-3xl font-semibold text-center">Our Mission</h2>
            <p class="mb-4 text-lg">
                Our mission is to make authentic Islamic education accessible to Muslims worldwide, regardless of geographic location,
                age, or prior knowledge. We aim to nurture a generation of Muslims who are firmly rooted in their faith, confident in
                their identity, and equipped to contribute positively to society.
            </p>
            <p class="text-lg">
                We strive to provide a supportive and engaging learning environment that encourages lifelong learning and a
                deep connection with the Quran and Sunnah. Our platform bridges the gap between traditional Islamic scholarship
                and modern educational technology to create an effective and accessible learning experience.
            </p>
        </div>
    </div>

    <!-- Our Story -->
    <div class="mb-16">
        <h2 class="mb-8 text-3xl font-semibold text-center">Our Story</h2>
        <div class="grid gap-8 md:grid-cols-2">
            <div class="relative overflow-hidden rounded-xl">
                <img src="{{ asset('images/about-image.jpg') }}" alt="Islamic Learning Center" class="object-cover w-full h-full" onerror="this.src='https://placehold.co/800x600?text=Islamic+Learning+Center'">
            </div>
            <div class="flex flex-col justify-center space-y-4">
                <p>
                    The Islamic Learning Platform was founded in 2020 by a group of educators, Islamic scholars, and technology experts
                    who were concerned about the lack of structured, comprehensive Islamic education options available online.
                </p>
                <p>
                    Having witnessed firsthand the challenges many Muslims face in accessing quality Islamic education, our founders
                    set out to create a platform that would combine the depth and authenticity of traditional Islamic learning with
                    the convenience and accessibility of modern technology.
                </p>
                <p>
                    What began as a small initiative with a handful of courses has grown into a comprehensive platform serving thousands
                    of students worldwide. Throughout our growth, we have maintained our commitment to educational excellence, Islamic
                    authenticity, and technological innovation.
                </p>
            </div>
        </div>
    </div>

    <!-- Our Values -->
    <div class="mb-16">
        <h2 class="mb-8 text-3xl font-semibold text-center">Our Core Values</h2>
        <div class="grid gap-6 md:grid-cols-3">
            <div class="p-6 bg-white shadow-sm rounded-xl dark:bg-base-900">
                <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-primary/10">
                    <i class="text-2xl text-primary ti ti-book"></i>
                </div>
                <h3 class="mb-3 text-xl font-semibold text-center">Authentic Knowledge</h3>
                <p class="text-center">
                    We are committed to providing authentic Islamic knowledge based on the Quran and Sunnah,
                    following the understanding of the righteous predecessors (Salaf).
                </p>
            </div>

            <div class="p-6 bg-white shadow-sm rounded-xl dark:bg-base-900">
                <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-primary/10">
                    <i class="text-2xl text-primary ti ti-users"></i>
                </div>
                <h3 class="mb-3 text-xl font-semibold text-center">Inclusive Environment</h3>
                <p class="text-center">
                    We create a supportive and inclusive learning environment that welcomes Muslims of all backgrounds,
                    ages, and levels of Islamic knowledge.
                </p>
            </div>

            <div class="p-6 bg-white shadow-sm rounded-xl dark:bg-base-900">
                <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-primary/10">
                    <i class="text-2xl text-primary ti ti-bulb"></i>
                </div>
                <h3 class="mb-3 text-xl font-semibold text-center">Educational Excellence</h3>
                <p class="text-center">
                    We are dedicated to educational excellence, employing research-backed teaching methods
                    and continuous improvement in our curriculum and delivery.
                </p>
            </div>
        </div>
    </div>

    <!-- Our Team -->
    <div class="mb-16">
        <h2 class="mb-8 text-3xl font-semibold text-center">Meet Our Team</h2>
        <div class="grid gap-6 mb-8 md:grid-cols-4">
            <!-- Team Member 1 -->
            <div class="p-4 text-center bg-white shadow-sm rounded-xl dark:bg-base-900">
                <img src="https://i.pravatar.cc/150?img=11" alt="Sheikh Ahmad Ibrahim" class="object-cover w-32 h-32 mx-auto mb-4 rounded-full">
                <h3 class="mb-1 text-xl font-semibold">Sheikh Ahmad Ibrahim</h3>
                <p class="mb-2 text-sm text-primary">Founder & Academic Director</p>
                <p class="text-sm">
                    Graduate of Al-Azhar University with over 20 years of experience in Islamic education.
                </p>
            </div>

            <!-- Team Member 2 -->
            <div class="p-4 text-center bg-white shadow-sm rounded-xl dark:bg-base-900">
                <img src="https://i.pravatar.cc/150?img=12" alt="Dr. Sarah Ahmed" class="object-cover w-32 h-32 mx-auto mb-4 rounded-full">
                <h3 class="mb-1 text-xl font-semibold">Dr. Sarah Ahmed</h3>
                <p class="mb-2 text-sm text-primary">Curriculum Developer</p>
                <p class="text-sm">
                    PhD in Islamic Education with expertise in developing age-appropriate learning materials.
                </p>
            </div>

            <!-- Team Member 3 -->
            <div class="p-4 text-center bg-white shadow-sm rounded-xl dark:bg-base-900">
                <img src="https://i.pravatar.cc/150?img=13" alt="Ustadh Yusuf Hassan" class="object-cover w-32 h-32 mx-auto mb-4 rounded-full">
                <h3 class="mb-1 text-xl font-semibold">Ustadh Yusuf Hassan</h3>
                <p class="mb-2 text-sm text-primary">Lead Quran Instructor</p>
                <p class="text-sm">
                    Certified Quran teacher with multiple ijazahs in various recitation methods (qira'at).
                </p>
            </div>

            <!-- Team Member 4 -->
            <div class="p-4 text-center bg-white shadow-sm rounded-xl dark:bg-base-900">
                <img src="https://i.pravatar.cc/150?img=14" alt="Amina Malik" class="object-cover w-32 h-32 mx-auto mb-4 rounded-full">
                <h3 class="mb-1 text-xl font-semibold">Amina Malik</h3>
                <p class="mb-2 text-sm text-primary">Technology Director</p>
                <p class="text-sm">
                    Technology expert with experience in developing educational platforms and e-learning solutions.
                </p>
            </div>
        </div>
        <div class="text-center">
            <a href="#" class="inline-flex items-center justify-center gap-2 px-6 py-3 font-medium text-white transition-colors duration-300 rounded-md bg-primary hover:bg-primary/80">
                Meet Our Full Team
                <i class="ti ti-arrow-right"></i>
            </a>
        </div>
    </div>

    <!-- Achievements -->
    <div class="mb-16">
        <h2 class="mb-8 text-3xl font-semibold text-center">Our Impact</h2>
        <div class="grid gap-6 md:grid-cols-3">
            <div class="p-6 text-center bg-white shadow-sm rounded-xl dark:bg-base-900">
                <div class="text-4xl font-bold text-primary">10,000+</div>
                <p class="text-lg">Active Students</p>
            </div>
            <div class="p-6 text-center bg-white shadow-sm rounded-xl dark:bg-base-900">
                <div class="text-4xl font-bold text-primary">50+</div>
                <p class="text-lg">Countries Reached</p>
            </div>
            <div class="p-6 text-center bg-white shadow-sm rounded-xl dark:bg-base-900">
                <div class="text-4xl font-bold text-primary">500+</div>
                <p class="text-lg">Quran Completions</p>
            </div>
        </div>
    </div>

    <!-- CTA -->
    <div class="p-8 mb-16 text-center bg-primary/10 rounded-xl">
        <h2 class="mb-4 text-2xl font-semibold">Join Our Growing Community</h2>
        <p class="max-w-2xl mx-auto mb-6">
            Become part of our journey to make quality Islamic education accessible to all.
            Start your learning journey today or join our team of dedicated educators.
        </p>
        <div class="flex flex-wrap justify-center gap-4">
            <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 px-6 py-3 font-medium text-white transition-colors duration-300 rounded-md bg-primary hover:bg-primary/80">
                Enroll Now
            </a>
            <a href="{{ route('contact') }}" class="inline-flex items-center justify-center gap-2 px-6 py-3 font-medium transition-colors duration-300 bg-white rounded-md hover:bg-gray-100 dark:bg-base-800 dark:hover:bg-base-700">
                Contact Us
            </a>
        </div>
    </div>
</div>
@endsection
