@extends('layouts.page')

@section('content')
<div class="container px-4 py-12 mx-auto">
    <!-- Hero Section -->
    <div class="mb-12 text-center">
        <h1 class="mb-4 text-4xl font-bold md:text-5xl font-display title-gradient">Contact Us</h1>
        <p class="max-w-2xl mx-auto text-xl">
            We're here to help with any questions or concerns. Reach out to our team using any of the methods below.
        </p>
    </div>

    <div class="grid gap-8 md:grid-cols-2">
        <!-- Contact Information -->
        <div>
            <div class="p-8 bg-white shadow-sm rounded-xl dark:bg-base-900">
                <h2 class="mb-6 text-2xl font-semibold">Get in Touch</h2>

                <!-- Email -->
                <div class="flex items-start mb-6 gap-x-4">
                    <div class="flex items-center justify-center w-12 h-12 text-white rounded-full bg-primary shrink-0">
                        <i class="text-xl ti ti-mail"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-medium">Email Us</h3>
                        <p class="mb-1 text-base-600 dark:text-base-400">For general inquiries:</p>
                        <a href="mailto:info@islamiclearningplatform.com" class="text-primary hover:underline">info@islamiclearningplatform.com</a>
                        <p class="mt-2 mb-1 text-base-600 dark:text-base-400">For technical support:</p>
                        <a href="mailto:support@islamiclearningplatform.com" class="text-primary hover:underline">support@islamiclearningplatform.com</a>
                    </div>
                </div>

                <!-- Phone -->
                <div class="flex items-start mb-6 gap-x-4">
                    <div class="flex items-center justify-center w-12 h-12 text-white rounded-full bg-primary shrink-0">
                        <i class="text-xl ti ti-phone"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-medium">Call Us</h3>
                        <p class="mb-1 text-base-600 dark:text-base-400">Customer Service:</p>
                        <a href="tel:+12345678910" class="text-primary hover:underline">+1 (234) 567-8910</a>
                        <p class="mt-2 mb-1 text-base-600 dark:text-base-400">Technical Support:</p>
                        <a href="tel:+12345678911" class="text-primary hover:underline">+1 (234) 567-8911</a>
                    </div>
                </div>

                <!-- Address -->
                <div class="flex items-start mb-6 gap-x-4">
                    <div class="flex items-center justify-center w-12 h-12 text-white rounded-full bg-primary shrink-0">
                        <i class="text-xl ti ti-map-pin"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-medium">Visit Us</h3>
                        <p class="text-base-600 dark:text-base-400">
                            123 Education Street<br>
                            Learning City, LC 12345<br>
                            United States
                        </p>
                        <a href="https://maps.google.com" target="_blank" class="inline-block mt-2 text-primary hover:underline">View on Google Maps</a>
                    </div>
                </div>

                <!-- Social Media -->
                <div class="flex items-start gap-x-4">
                    <div class="flex items-center justify-center w-12 h-12 text-white rounded-full bg-primary shrink-0">
                        <i class="text-xl ti ti-brand-telegram"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-medium">Follow Us</h3>
                        <p class="mb-3 text-base-600 dark:text-base-400">Stay connected with us on social media:</p>
                        <div class="flex space-x-4">
                            <a href="#" class="text-2xl text-primary hover:text-primary/80">
                                <i class="ti ti-brand-facebook"></i>
                            </a>
                            <a href="#" class="text-2xl text-primary hover:text-primary/80">
                                <i class="ti ti-brand-twitter"></i>
                            </a>
                            <a href="#" class="text-2xl text-primary hover:text-primary/80">
                                <i class="ti ti-brand-instagram"></i>
                            </a>
                            <a href="#" class="text-2xl text-primary hover:text-primary/80">
                                <i class="ti ti-brand-youtube"></i>
                            </a>
                            <a href="#" class="text-2xl text-primary hover:text-primary/80">
                                <i class="ti ti-brand-telegram"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Office Hours -->
            <div class="p-8 mt-6 bg-white shadow-sm rounded-xl dark:bg-base-900">
                <h2 class="mb-4 text-2xl font-semibold">Office Hours</h2>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span>Monday - Friday:</span>
                        <span>9:00 AM - 6:00 PM EST</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Saturday:</span>
                        <span>10:00 AM - 4:00 PM EST</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Sunday:</span>
                        <span>Closed</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact Form -->
        <div class="p-8 bg-white shadow-sm rounded-xl dark:bg-base-900">
            <h2 class="mb-6 text-2xl font-semibold">Send Us a Message</h2>

            @if(session('success'))
                <div class="p-4 mb-6 text-green-700 bg-green-100 rounded-md dark:bg-green-900/50 dark:text-green-200">
                    {{ session('success') }}
                </div>
            @endif

            <form action="{{ route('contact.submit') }}" method="POST">
                @csrf

                <div class="mb-4">
                    <label for="name" class="block mb-2 text-sm font-medium">Name</label>
                    <input type="text" id="name" name="name" class="w-full px-4 py-2 border rounded-md border-base-300 focus:outline-none focus:ring-2 focus:ring-primary/50 dark:bg-base-800 dark:border-base-700" required>
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="email" class="block mb-2 text-sm font-medium">Email</label>
                    <input type="email" id="email" name="email" class="w-full px-4 py-2 border rounded-md border-base-300 focus:outline-none focus:ring-2 focus:ring-primary/50 dark:bg-base-800 dark:border-base-700" required>
                    @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="subject" class="block mb-2 text-sm font-medium">Subject</label>
                    <select id="subject" name="subject" class="w-full px-4 py-2 border rounded-md border-base-300 focus:outline-none focus:ring-2 focus:ring-primary/50 dark:bg-base-800 dark:border-base-700">
                        <option value="General Inquiry">General Inquiry</option>
                        <option value="Technical Support">Technical Support</option>
                        <option value="Billing Question">Billing Question</option>
                        <option value="Partnership Opportunity">Partnership Opportunity</option>
                        <option value="Feedback">Feedback</option>
                    </select>
                </div>

                <div class="mb-6">
                    <label for="message" class="block mb-2 text-sm font-medium">Message</label>
                    <textarea id="message" name="message" rows="5" class="w-full px-4 py-2 border rounded-md border-base-300 focus:outline-none focus:ring-2 focus:ring-primary/50 dark:bg-base-800 dark:border-base-700" required></textarea>
                    @error('message')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full px-6 py-3 font-medium text-white transition-colors duration-300 rounded-md bg-primary hover:bg-primary/80">
                    Send Message
                </button>
            </form>
        </div>
    </div>

    <!-- FAQ Section -->
    <div class="max-w-3xl mx-auto mt-16">
        <h2 class="mb-6 text-2xl font-semibold text-center">Frequently Asked Questions</h2>
        <div class="space-y-4">
            <div class="p-6 bg-white shadow-sm rounded-xl dark:bg-base-900">
                <h3 class="mb-2 text-lg font-medium">How quickly will I receive a response?</h3>
                <p>We strive to respond to all inquiries within 24-48 hours during business days. For urgent matters, please call our customer service line.</p>
            </div>

            <div class="p-6 bg-white shadow-sm rounded-xl dark:bg-base-900">
                <h3 class="mb-2 text-lg font-medium">Can I schedule a virtual meeting with a teacher?</h3>
                <p>Yes, registered students can schedule one-on-one meetings with their teachers through our platform. For prospective students, please contact us to arrange a demonstration session.</p>
            </div>

            <div class="p-6 bg-white shadow-sm rounded-xl dark:bg-base-900">
                <h3 class="mb-2 text-lg font-medium">How do I report a technical issue?</h3>
                <p>You can report technical issues by emailing our support team at support@islamiclearningplatform.com or by using the contact form above and selecting "Technical Support" as the subject.</p>
            </div>
        </div>
    </div>
</div>
@endsection
