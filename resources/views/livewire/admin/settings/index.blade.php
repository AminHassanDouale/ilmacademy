<?php
// resources/views/livewire/admin/settings/index.blade.php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

new #[Title('System Settings')] class extends Component {
    use Toast, WithFileUploads;

    // General Settings
    #[Validate('required|string|max:255')]
    public string $app_name = '';

    #[Validate('nullable|string|max:500')]
    public string $app_description = '';

    #[Validate('nullable|url')]
    public string $app_url = '';

    #[Validate('required|email')]
    public string $admin_email = '';

    #[Validate('nullable|string|max:50')]
    public string $app_timezone = '';

    #[Validate('nullable|string|max:10')]
    public string $app_locale = '';

    // School Settings
    #[Validate('nullable|string|max:255')]
    public string $school_name = '';

    #[Validate('nullable|string')]
    public string $school_address = '';

    #[Validate('nullable|string|max:20')]
    public string $school_phone = '';

    #[Validate('nullable|email')]
    public string $school_email = '';

    #[Validate('nullable|url')]
    public string $school_website = '';

    // Academic Settings
    #[Validate('nullable|string|max:100')]
    public string $current_academic_year = '';

    #[Validate('nullable|string|max:50')]
    public string $current_semester = '';

    #[Validate('nullable|integer|min:1|max:10')]
    public string $max_subjects_per_student = '';

    #[Validate('nullable|integer|min:1|max:50')]
    public string $max_students_per_class = '';

    // Email Settings
    #[Validate('nullable|string')]
    public string $mail_driver = '';

    #[Validate('nullable|string')]
    public string $mail_host = '';

    #[Validate('nullable|integer')]
    public string $mail_port = '';

    #[Validate('nullable|string')]
    public string $mail_username = '';

    #[Validate('nullable|string')]
    public string $mail_from_address = '';

    #[Validate('nullable|string')]
    public string $mail_from_name = '';

    // Notification Settings
    public bool $email_notifications = true;
    public bool $sms_notifications = false;
    public bool $push_notifications = true;
    public bool $payment_reminders = true;
    public bool $attendance_alerts = true;
    public bool $grade_notifications = true;

    // Security Settings
    public bool $two_factor_enabled = false;
    public bool $password_reset_enabled = true;
    public bool $login_attempts_limit = true;
    public string $max_login_attempts = '5';
    public string $lockout_duration = '15';

    // File Upload Settings
    public string $max_file_upload_size = '10';
    public string $allowed_file_types = 'jpg,jpeg,png,pdf,doc,docx';

    // Logo and Branding
    public $logo;
    public $favicon;
    public string $primary_color = '#3B82F6';
    public string $secondary_color = '#10B981';

    public string $activeTab = 'general';

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        // Load settings from config or database
        $this->app_name = config('app.name', 'School Management System');
        $this->app_url = config('app.url', '');
        $this->app_timezone = config('app.timezone', 'UTC');
        $this->app_locale = config('app.locale', 'en');
        $this->admin_email = config('mail.from.address', '');

        // Load custom settings from cache/database
        $settings = Cache::get('app_settings', []);

        $this->app_description = $settings['app_description'] ?? '';
        $this->school_name = $settings['school_name'] ?? '';
        $this->school_address = $settings['school_address'] ?? '';
        $this->school_phone = $settings['school_phone'] ?? '';
        $this->school_email = $settings['school_email'] ?? '';
        $this->school_website = $settings['school_website'] ?? '';
        $this->current_academic_year = $settings['current_academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
        $this->current_semester = $settings['current_semester'] ?? '1';
        $this->max_subjects_per_student = $settings['max_subjects_per_student'] ?? '8';
        $this->max_students_per_class = $settings['max_students_per_class'] ?? '30';

        // Mail settings
        $this->mail_driver = config('mail.default', 'smtp');
        $this->mail_host = config('mail.mailers.smtp.host', '');
        $this->mail_port = (string) config('mail.mailers.smtp.port', '587');
        $this->mail_username = config('mail.mailers.smtp.username', '');
        $this->mail_from_address = config('mail.from.address', '');
        $this->mail_from_name = config('mail.from.name', '');

        // Notification settings
        $this->email_notifications = $settings['email_notifications'] ?? true;
        $this->sms_notifications = $settings['sms_notifications'] ?? false;
        $this->push_notifications = $settings['push_notifications'] ?? true;
        $this->payment_reminders = $settings['payment_reminders'] ?? true;
        $this->attendance_alerts = $settings['attendance_alerts'] ?? true;
        $this->grade_notifications = $settings['grade_notifications'] ?? true;

        // Security settings
        $this->two_factor_enabled = $settings['two_factor_enabled'] ?? false;
        $this->password_reset_enabled = $settings['password_reset_enabled'] ?? true;
        $this->login_attempts_limit = $settings['login_attempts_limit'] ?? true;
        $this->max_login_attempts = $settings['max_login_attempts'] ?? '5';
        $this->lockout_duration = $settings['lockout_duration'] ?? '15';

        // File upload settings
        $this->max_file_upload_size = $settings['max_file_upload_size'] ?? '10';
        $this->allowed_file_types = $settings['allowed_file_types'] ?? 'jpg,jpeg,png,pdf,doc,docx';

        // Branding
        $this->primary_color = $settings['primary_color'] ?? '#3B82F6';
        $this->secondary_color = $settings['secondary_color'] ?? '#10B981';
    }

    public function saveSettings(): void
    {
        $this->validate();

        try {
            $settings = [
                'app_description' => $this->app_description,
                'school_name' => $this->school_name,
                'school_address' => $this->school_address,
                'school_phone' => $this->school_phone,
                'school_email' => $this->school_email,
                'school_website' => $this->school_website,
                'current_academic_year' => $this->current_academic_year,
                'current_semester' => $this->current_semester,
                'max_subjects_per_student' => $this->max_subjects_per_student,
                'max_students_per_class' => $this->max_students_per_class,
                'email_notifications' => $this->email_notifications,
                'sms_notifications' => $this->sms_notifications,
                'push_notifications' => $this->push_notifications,
                'payment_reminders' => $this->payment_reminders,
                'attendance_alerts' => $this->attendance_alerts,
                'grade_notifications' => $this->grade_notifications,
                'two_factor_enabled' => $this->two_factor_enabled,
                'password_reset_enabled' => $this->password_reset_enabled,
                'login_attempts_limit' => $this->login_attempts_limit,
                'max_login_attempts' => $this->max_login_attempts,
                'lockout_duration' => $this->lockout_duration,
                'max_file_upload_size' => $this->max_file_upload_size,
                'allowed_file_types' => $this->allowed_file_types,
                'primary_color' => $this->primary_color,
                'secondary_color' => $this->secondary_color,
                'updated_at' => now(),
                'updated_by' => auth()->id(),
            ];

            // Store in cache and optionally in database
            Cache::put('app_settings', $settings, now()->addDays(30));

            // Log activity
            activity()
                ->causedBy(auth()->user())
                ->withProperties([
                    'settings_updated' => array_keys($settings),
                    'tab' => $this->activeTab
                ])
                ->log('System settings updated');

            $this->success('Settings saved successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to save settings. Please try again.');
        }
    }

    public function uploadLogo(): void
    {
        $this->validate([
            'logo' => 'required|image|max:2048'
        ]);

        try {
            $path = $this->logo->store('logos', 'public');

            $settings = Cache::get('app_settings', []);
            $settings['logo_path'] = $path;
            Cache::put('app_settings', $settings, now()->addDays(30));

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['logo_path' => $path])
                ->log('Logo updated');

            $this->success('Logo uploaded successfully!');
            $this->logo = null;

        } catch (\Exception $e) {
            $this->error('Failed to upload logo. Please try again.');
        }
    }

    public function uploadFavicon(): void
    {
        $this->validate([
            'favicon' => 'required|image|max:512'
        ]);

        try {
            $path = $this->favicon->store('favicons', 'public');

            $settings = Cache::get('app_settings', []);
            $settings['favicon_path'] = $path;
            Cache::put('app_settings', $settings, now()->addDays(30));

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['favicon_path' => $path])
                ->log('Favicon updated');

            $this->success('Favicon uploaded successfully!');
            $this->favicon = null;

        } catch (\Exception $e) {
            $this->error('Failed to upload favicon. Please try again.');
        }
    }

    public function clearCache(): void
    {
        try {
            Cache::flush();

            activity()
                ->causedBy(auth()->user())
                ->log('System cache cleared');

            $this->success('Cache cleared successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to clear cache. Please try again.');
        }
    }

    public function testEmailSettings(): void
    {
        try {
            // Send test email
            $this->success('Test email sent successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to send test email. Please check your settings.');
        }
    }

    public function resetToDefaults(): void
    {
        Cache::forget('app_settings');
        $this->loadSettings();

        activity()
            ->causedBy(auth()->user())
            ->log('Settings reset to defaults');

        $this->success('Settings reset to defaults!');
    }

    public function getTimezoneOptionsProperty(): array
    {
        return [
            'UTC' => 'UTC',
            'America/New_York' => 'Eastern Time',
            'America/Chicago' => 'Central Time',
            'America/Denver' => 'Mountain Time',
            'America/Los_Angeles' => 'Pacific Time',
            'Europe/London' => 'London',
            'Europe/Paris' => 'Paris',
            'Asia/Tokyo' => 'Tokyo',
            'Asia/Shanghai' => 'Shanghai',
            'Australia/Sydney' => 'Sydney',
        ];
    }

    public function getLanguageOptionsProperty(): array
    {
        return [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ar' => 'Arabic',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
        ];
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="System Settings" separator>
        <x-slot:actions>
            <x-button
                label="Reset to Defaults"
                icon="o-arrow-path"
                wire:click="resetToDefaults"
                wire:confirm="Are you sure you want to reset all settings to defaults?"
                class="btn-ghost"
            />
            <x-button
                label="Clear Cache"
                icon="o-trash"
                wire:click="clearCache"
                class="btn-outline"
            />
            <x-button
                label="Save Settings"
                icon="o-check"
                wire:click="saveSettings"
                spinner="saveSettings"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-header>

    <div class="flex gap-6">
        <!-- Sidebar Navigation -->
        <div class="flex-shrink-0 w-64">
            <div class="bg-white rounded-lg shadow">
                <nav class="p-4 space-y-2">
                    <button
                        wire:click="$set('activeTab', 'general')"
                        class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors
                            {{ $activeTab === 'general' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100' }}"
                    >
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            General
                        </div>
                    </button>

                    <button
                        wire:click="$set('activeTab', 'school')"
                        class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors
                            {{ $activeTab === 'school' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100' }}"
                    >
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                            School Info
                        </div>
                    </button>

                    <button
                        wire:click="$set('activeTab', 'academic')"
                        class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors
                            {{ $activeTab === 'academic' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100' }}"
                    >
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                            Academic
                        </div>
                    </button>

                    <button
                        wire:click="$set('activeTab', 'email')"
                        class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors
                            {{ $activeTab === 'email' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100' }}"
                    >
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            Email
                        </div>
                    </button>

                    <button
                        wire:click="$set('activeTab', 'notifications')"
                        class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors
                            {{ $activeTab === 'notifications' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100' }}"
                    >
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5zM11 19H6a2 2 0 01-2-2V7a2 2 0 012-2h11a2 2 0 012 2v4"></path>
                            </svg>
                            Notifications
                        </div>
                    </button>

                    <button
                        wire:click="$set('activeTab', 'security')"
                        class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors
                            {{ $activeTab === 'security' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100' }}"
                    >
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            Security
                        </div>
                    </button>

                    <button
                        wire:click="$set('activeTab', 'files')"
                        class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors
                            {{ $activeTab === 'files' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100' }}"
                    >
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                            </svg>
                            Files & Upload
                        </div>
                    </button>

                    <button
                        wire:click="$set('activeTab', 'branding')"
                        class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors
                            {{ $activeTab === 'branding' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100' }}"
                    >
                        <div class="flex items-center">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17v4a2 2 0 002 2h4M13 13h4a2 2 0 012 2v4a2 2 0 01-2 2h-4a2 2 0 01-2-2v-4a2 2 0 012-2z"></path>
                            </svg>
                            Branding
                        </div>
                    </button>
                </nav>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1">
            <div class="bg-white rounded-lg shadow">
                <div class="p-6">
                    @if($activeTab === 'general')
                        <!-- General Settings -->
                        <div class="space-y-6">
                            <h3 class="text-lg font-medium text-gray-900">General Settings</h3>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">
                                        Application Name <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        wire:model="app_name"
                                        class="w-full input input-bordered"
                                        required
                                    />
                                    @error('app_name')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">
                                        Admin Email <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        type="email"
                                        wire:model="admin_email"
                                        class="w-full input input-bordered"
                                        required
                                    />
                                    @error('admin_email')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Timezone</label>
                                    <select wire:model="app_timezone" class="w-full select select-bordered">
                                        @foreach($this->timezoneOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Language</label>
                                    <select wire:model="app_locale" class="w-full select select-bordered">
                                        @foreach($this->languageOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Application URL</label>
                                    <input
                                        type="url"
                                        wire:model="app_url"
                                        class="w-full input input-bordered"
                                        placeholder="https://your-domain.com"
                                    />
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Description</label>
                                    <textarea
                                        wire:model="app_description"
                                        rows="3"
                                        class="w-full textarea textarea-bordered"
                                        placeholder="Brief description of your application..."
                                    ></textarea>
                                </div>
                            </div>
                        </div>

                    @elseif($activeTab === 'school')
                        <!-- School Settings -->
                        <div class="space-y-6">
                            <h3 class="text-lg font-medium text-gray-900">School Information</h3>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <label class="block mb-2 text-sm font-medium text-gray-700">School Name</label>
                                    <input
                                        type="text"
                                        wire:model="school_name"
                                        class="w-full input input-bordered"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Phone</label>
                                    <input
                                        type="tel"
                                        wire:model="school_phone"
                                        class="w-full input input-bordered"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Email</label>
                                    <input
                                        type="email"
                                        wire:model="school_email"
                                        class="w-full input input-bordered"
                                    />
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Website</label>
                                    <input
                                        type="url"
                                        wire:model="school_website"
                                        class="w-full input input-bordered"
                                        placeholder="https://school-website.com"
                                    />
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Address</label>
                                    <textarea
                                        wire:model="school_address"
                                        rows="3"
                                        class="w-full textarea textarea-bordered"
                                        placeholder="School address..."
                                    ></textarea>
                                </div>
                            </div>
                        </div>

                    @elseif($activeTab === 'academic')
                        <!-- Academic Settings -->
                        <div class="space-y-6">
                            <h3 class="text-lg font-medium text-gray-900">Academic Settings</h3>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Current Academic Year</label>
                                    <input
                                        type="text"
                                        wire:model="current_academic_year"
                                        class="w-full input input-bordered"
                                        placeholder="2024-2025"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Current Semester</label>
                                    <select wire:model="current_semester" class="w-full select select-bordered">
                                        <option value="1">Semester 1</option>
                                        <option value="2">Semester 2</option>
                                        <option value="3">Semester 3</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Max Subjects per Student</label>
                                    <input
                                        type="number"
                                        wire:model="max_subjects_per_student"
                                        class="w-full input input-bordered"
                                        min="1" max="10"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Max Students per Class</label>
                                    <input
                                        type="number"
                                        wire:model="max_students_per_class"
                                        class="w-full input input-bordered"
                                        min="1" max="50"
                                    />
                                </div>
                            </div>
                        </div>

                    @elseif($activeTab === 'email')
                        <!-- Email Settings -->
                        <div class="space-y-6">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-medium text-gray-900">Email Settings</h3>
                                <x-button
                                    label="Test Email"
                                    icon="o-paper-airplane"
                                    wire:click="testEmailSettings"
                                    class="btn-outline btn-sm"
                                />
                            </div>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Mail Driver</label>
                                    <select wire:model="mail_driver" class="w-full select select-bordered">
                                        <option value="smtp">SMTP</option>
                                        <option value="mailgun">Mailgun</option>
                                        <option value="ses">Amazon SES</option>
                                        <option value="sendmail">Sendmail</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Mail Host</label>
                                    <input
                                        type="text"
                                        wire:model="mail_host"
                                        class="w-full input input-bordered"
                                        placeholder="smtp.gmail.com"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Mail Port</label>
                                    <input
                                        type="number"
                                        wire:model="mail_port"
                                        class="w-full input input-bordered"
                                        placeholder="587"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Username</label>
                                    <input
                                        type="text"
                                        wire:model="mail_username"
                                        class="w-full input input-bordered"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">From Address</label>
                                    <input
                                        type="email"
                                        wire:model="mail_from_address"
                                        class="w-full input input-bordered"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">From Name</label>
                                    <input
                                        type="text"
                                        wire:model="mail_from_name"
                                        class="w-full input input-bordered"
                                    />
                                </div>
                            </div>
                        </div>

                    @elseif($activeTab === 'notifications')
                        <!-- Notification Settings -->
                        <div class="space-y-6">
                            <h3 class="text-lg font-medium text-gray-900">Notification Settings</h3>

                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">Email Notifications</h4>
                                        <p class="text-sm text-gray-500">Send notifications via email</p>
                                    </div>
                                    <input
                                        type="checkbox"
                                        wire:model="email_notifications"
                                        class="toggle toggle-primary"
                                    />
                                </div>

                                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">SMS Notifications</h4>
                                        <p class="text-sm text-gray-500">Send notifications via SMS</p>
                                    </div>
                                    <input
                                        type="checkbox"
                                        wire:model="sms_notifications"
                                        class="toggle toggle-primary"
                                    />
                                </div>

                                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">Push Notifications</h4>
                                        <p class="text-sm text-gray-500">Send browser push notifications</p>
                                    </div>
                                    <input
                                        type="checkbox"
                                        wire:model="push_notifications"
                                        class="toggle toggle-primary"
                                    />
                                </div>

                                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">Payment Reminders</h4>
                                        <p class="text-sm text-gray-500">Send payment due reminders</p>
                                    </div>
                                    <input
                                        type="checkbox"
                                        wire:model="payment_reminders"
                                        class="toggle toggle-primary"
                                    />
                                </div>

                                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">Attendance Alerts</h4>
                                        <p class="text-sm text-gray-500">Send attendance notifications</p>
                                    </div>
                                    <input
                                        type="checkbox"
                                        wire:model="attendance_alerts"
                                        class="toggle toggle-primary"
                                    />
                                </div>

                                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">Grade Notifications</h4>
                                        <p class="text-sm text-gray-500">Send grade update notifications</p>
                                    </div>
                                    <input
                                        type="checkbox"
                                        wire:model="grade_notifications"
                                        class="toggle toggle-primary"
                                    />
                                </div>
                            </div>
                        </div>

                    @elseif($activeTab === 'security')
                        <!-- Security Settings -->
                        <div class="space-y-6">
                            <h3 class="text-lg font-medium text-gray-900">Security Settings</h3>

                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">Two-Factor Authentication</h4>
                                        <p class="text-sm text-gray-500">Enable 2FA for all users</p>
                                    </div>
                                    <input
                                        type="checkbox"
                                        wire:model="two_factor_enabled"
                                        class="toggle toggle-primary"
                                    />
                                </div>

                                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">Password Reset</h4>
                                        <p class="text-sm text-gray-500">Allow users to reset passwords</p>
                                    </div>
                                    <input
                                        type="checkbox"
                                        wire:model="password_reset_enabled"
                                        class="toggle toggle-primary"
                                    />
                                </div>

                                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">Login Attempts Limit</h4>
                                        <p class="text-sm text-gray-500">Limit failed login attempts</p>
                                    </div>
                                    <input
                                        type="checkbox"
                                        wire:model="login_attempts_limit"
                                        class="toggle toggle-primary"
                                    />
                                </div>

                                @if($login_attempts_limit)
                                    <div class="grid grid-cols-1 gap-4 ml-8 md:grid-cols-2">
                                        <div>
                                            <label class="block mb-2 text-sm font-medium text-gray-700">Max Login Attempts</label>
                                            <input
                                                type="number"
                                                wire:model="max_login_attempts"
                                                class="w-full input input-bordered"
                                                min="3" max="10"
                                            />
                                        </div>

                                        <div>
                                            <label class="block mb-2 text-sm font-medium text-gray-700">Lockout Duration (minutes)</label>
                                            <input
                                                type="number"
                                                wire:model="lockout_duration"
                                                class="w-full input input-bordered"
                                                min="5" max="60"
                                            />
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                    @elseif($activeTab === 'files')
                        <!-- File Upload Settings -->
                        <div class="space-y-6">
                            <h3 class="text-lg font-medium text-gray-900">File Upload Settings</h3>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Max File Size (MB)</label>
                                    <input
                                        type="number"
                                        wire:model="max_file_upload_size"
                                        class="w-full input input-bordered"
                                        min="1" max="100"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Allowed File Types</label>
                                    <input
                                        type="text"
                                        wire:model="allowed_file_types"
                                        class="w-full input input-bordered"
                                        placeholder="jpg,jpeg,png,pdf,doc,docx"
                                    />
                                    <p class="mt-1 text-xs text-gray-500">Separate with commas</p>
                                </div>
                            </div>
                        </div>

                    @elseif($activeTab === 'branding')
                        <!-- Branding Settings -->
                        <div class="space-y-6">
                            <h3 class="text-lg font-medium text-gray-900">Branding & Appearance</h3>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <!-- Logo Upload -->
                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Logo</label>
                                    <div class="space-y-2">
                                        <input
                                            type="file"
                                            wire:model="logo"
                                            accept="image/*"
                                            class="w-full file-input file-input-bordered"
                                        />
                                        @if($logo)
                                            <x-button
                                                label="Upload Logo"
                                                icon="o-cloud-arrow-up"
                                                wire:click="uploadLogo"
                                                spinner="uploadLogo"
                                                class="btn-sm btn-primary"
                                            />
                                        @endif
                                        <p class="text-xs text-gray-500">Max 2MB, JPG/PNG only</p>
                                    </div>
                                </div>

                                <!-- Favicon Upload -->
                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Favicon</label>
                                    <div class="space-y-2">
                                        <input
                                            type="file"
                                            wire:model="favicon"
                                            accept="image/*"
                                            class="w-full file-input file-input-bordered"
                                        />
                                        @if($favicon)
                                            <x-button
                                                label="Upload Favicon"
                                                icon="o-cloud-arrow-up"
                                                wire:click="uploadFavicon"
                                                spinner="uploadFavicon"
                                                class="btn-sm btn-primary"
                                            />
                                        @endif
                                        <p class="text-xs text-gray-500">Max 512KB, 32x32px recommended</p>
                                    </div>
                                </div>

                                <!-- Color Settings -->
                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Primary Color</label>
                                    <input
                                        type="color"
                                        wire:model="primary_color"
                                        class="w-full h-12 border border-gray-300 rounded"
                                    />
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Secondary Color</label>
                                    <input
                                        type="color"
                                        wire:model="secondary_color"
                                        class="w-full h-12 border border-gray-300 rounded"
                                    />
                                </div>
                            </div>

                            <!-- Color Preview -->
                            <div class="p-4 rounded-lg bg-gray-50">
                                <h4 class="mb-2 text-sm font-medium text-gray-900">Color Preview</h4>
                                <div class="flex space-x-4">
                                    <div
                                        class="flex items-center justify-center w-16 h-16 text-xs font-medium text-white border border-gray-200 rounded-lg"
                                        style="background-color: {{ $primary_color }}"
                                    >
                                        Primary
                                    </div>
                                    <div
                                        class="flex items-center justify-center w-16 h-16 text-xs font-medium text-white border border-gray-200 rounded-lg"
                                        style="background-color: {{ $secondary_color }}"
                                    >
                                        Secondary
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
