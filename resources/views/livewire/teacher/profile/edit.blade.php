<?php

use App\Models\TeacherProfile;
use App\Models\Subject;
use App\Models\User;
use App\Models\ActivityLog;
use App\Models\SubjectEnrollment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Edit Profile')] class extends Component {
    use WithFileUploads;
    use WithPagination;
    use Toast;

    // User and profile data
    public $user;
    public $teacherProfile;

    // Form fields
    public $name;
    public $email;
    public $bio;
    public $specialization;
    public $phone;
    public $photo;
    public $newPhoto;

    // Subject specializations
    public $subjects = [];
    public $selectedSubjects = [];

    // Password change
    public $currentPassword;
    public $newPassword;
    public $newPasswordConfirmation;

    public function mount(): void
    {
        $this->user = Auth::user();
        $this->teacherProfile = $this->user->teacherProfile;

        if (!$this->teacherProfile) {
            // Create a teacher profile if it doesn't exist
            $this->teacherProfile = TeacherProfile::create([
                'user_id' => $this->user->id,
                'bio' => '',
                'specialization' => '',
                'phone' => '',
            ]);
        }

        // Load user and profile data
        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->bio = $this->teacherProfile->bio;
        $this->specialization = $this->teacherProfile->specialization;
        $this->phone = $this->teacherProfile->phone;
        $this->photo = $this->user->profile_photo_path;

        // Load subject specializations
        $this->loadSubjects();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed profile edit page',
            TeacherProfile::class,
            $this->teacherProfile->id,
            ['ip' => request()->ip()]
        );
    }

    // Load available subjects
    private function loadSubjects(): void
    {
        // Get all subjects
        $this->subjects = Subject::orderBy('name')
            ->get()
            ->map(function($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code,
                    'level' => $subject->level,
                    'curriculum' => $subject->curriculum ? $subject->curriculum->name : 'Unknown'
                ];
            })
            ->toArray();

        // Initialize selected subjects as empty array
        $this->selectedSubjects = [];

        // Get teacher's subject specializations if the relationship exists
        try {
            if (method_exists($this->teacherProfile, 'subjects')) {
                $this->selectedSubjects = $this->teacherProfile->subjects()
                    ->pluck('subjects.id')
                    ->toArray();
            } else {
                // Try using a property directly if it exists and is a collection
                if (isset($this->teacherProfile->subjectSpecializations) &&
                    $this->teacherProfile->subjectSpecializations instanceof Collection) {
                    $this->selectedSubjects = $this->teacherProfile->subjectSpecializations
                        ->pluck('id')
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            // Log the error but continue
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading subject specializations: ' . $e->getMessage(),
                TeacherProfile::class,
                $this->teacherProfile->id,
                ['ip' => request()->ip()]
            );
        }
    }

    // Save profile information
    public function saveProfile(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $this->user->id,
            'bio' => 'nullable|string|max:1000',
            'specialization' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'newPhoto' => 'nullable|image|max:1024', // Max 1MB
        ]);

        // Update user data
        $this->user->name = $this->name;
        $this->user->email = $this->email;
        $this->user->save();

        // Update profile data
        $this->teacherProfile->bio = $this->bio;
        $this->teacherProfile->specialization = $this->specialization;
        $this->teacherProfile->phone = $this->phone;
        $this->teacherProfile->save();

        // Handle photo upload
        if ($this->newPhoto) {
            $filename = 'profile-photos/' . $this->user->id . '-' . time() . '.' . $this->newPhoto->getClientOriginalExtension();

            // Delete old photo if exists
            if ($this->user->profile_photo_path) {
                Storage::disk('public')->delete($this->user->profile_photo_path);
            }

            // Store new photo
            $this->newPhoto->storeAs('public', $filename);
            $this->user->profile_photo_path = $filename;
            $this->user->save();

            $this->photo = $filename;
            $this->newPhoto = null;
        }

        // Handle subject specializations
        $this->updateSubjectSpecializations();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'update',
            'Teacher updated profile information',
            TeacherProfile::class,
            $this->teacherProfile->id,
            ['ip' => request()->ip()]
        );

        $this->success('Profile updated successfully');
    }

    // Update subject specializations
    private function updateSubjectSpecializations(): void
    {
        try {
            // Check if the subjects relationship method exists in TeacherProfile model
            if (method_exists($this->teacherProfile, 'subjects')) {
                // Sync subject specializations using the pivot table
                $this->teacherProfile->subjects()->sync($this->selectedSubjects);
            } else {
                // Log a message about missing relationship
                ActivityLog::log(
                    Auth::id(),
                    'warning',
                    'Subject specializations not updated: subjects relation not found',
                    TeacherProfile::class,
                    $this->teacherProfile->id,
                    ['ip' => request()->ip()]
                );
            }
        } catch (\Exception $e) {
            // Log any errors that occur
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error updating subject specializations: ' . $e->getMessage(),
                TeacherProfile::class,
                $this->teacherProfile->id,
                ['ip' => request()->ip(), 'exception' => $e->getMessage()]
            );
        }
    }

    // Update password
    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => 'required|current_password',
            'newPassword' => 'required|string|min:8|confirmed',
            'newPasswordConfirmation' => 'required',
        ]);

        $this->user->password = bcrypt($this->newPassword);
        $this->user->save();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'update',
            'Teacher updated password',
            User::class,
            $this->user->id,
            ['ip' => request()->ip()]
        );

        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';

        $this->success('Password updated successfully');
    }

    // Get teaching statistics
    public function teachingStats()
    {
        // Initialize stats with default values
        $stats = [
            'totalSessions' => 0,
            'totalExams' => 0,
            'totalTimeSlots' => 0,
            'totalTimeHours' => 0,
            'studentCount' => 0,
        ];

        try {
            // Calculate teaching statistics if relationships exist
            if (method_exists($this->teacherProfile, 'sessions')) {
                $stats['totalSessions'] = $this->teacherProfile->sessions()->count();
            }

            if (method_exists($this->teacherProfile, 'exams')) {
                $stats['totalExams'] = $this->teacherProfile->exams()->count();
            }

            if (method_exists($this->teacherProfile, 'timetableSlots')) {
                $stats['totalTimeSlots'] = $this->teacherProfile->timetableSlots()->count();
                $stats['totalTimeHours'] = round($this->teacherProfile->timetableSlots()->sum('duration') / 60, 1);
            }

            // Get student count safely
            if (method_exists($this->teacherProfile, 'subjects')) {
                $subjectIds = $this->teacherProfile->subjects()->pluck('subjects.id')->toArray();

                if (!empty($subjectIds)) {
                    $stats['studentCount'] = SubjectEnrollment::whereIn('subject_id', $subjectIds)
                        ->distinct('program_enrollment_id')
                        ->count();
                }
            }
        } catch (\Exception $e) {
            // Log any errors but continue with default values
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error calculating teaching statistics: ' . $e->getMessage(),
                TeacherProfile::class,
                $this->teacherProfile->id,
                ['ip' => request()->ip()]
            );
        }

        return $stats;
    }

    public function with(): array
    {
        return [
            'teachingStats' => $this->teachingStats(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Teacher Profile" separator progress-indicator>
        <x-slot:subtitle>
            Update your personal information and profile settings
        </x-slot:subtitle>
    </x-header>

    <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-3">
        <!-- Teaching stats cards -->
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-users" class="w-8 h-8" />
            </div>
            <div class="stat-title">Students</div>
            <div class="stat-value">{{ $teachingStats['studentCount'] }}</div>
            <div class="stat-desc">Across all subjects</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-secondary">
                <x-icon name="o-academic-cap" class="w-8 h-8" />
            </div>
            <div class="stat-title">Sessions</div>
            <div class="stat-value">{{ $teachingStats['totalSessions'] }}</div>
            <div class="stat-desc">Classes conducted</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-accent">
                <x-icon name="o-clock" class="w-8 h-8" />
            </div>
            <div class="stat-title">Teaching Hours</div>
            <div class="stat-value">{{ $teachingStats['totalTimeHours'] }}</div>
            <div class="stat-desc">{{ $teachingStats['totalTimeSlots'] }} scheduled time slots</div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Profile Photo Card -->
        <div class="lg:col-span-1">
            <x-card title="Profile Photo">
                <div class="flex flex-col items-center justify-center">
                    <div class="avatar">
                        <div class="w-32 h-32 rounded-full">
                            @if ($photo)
                                <img src="{{ asset('storage/' . $photo) }}" alt="{{ $name }}" />
                            @else
                                <img src="{{ 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&color=7F9CF5&background=EBF4FF' }}" alt="{{ $name }}" />
                            @endif
                        </div>
                    </div>

                    <div class="mt-4">
                        <input type="file" wire:model="newPhoto" class="hidden" id="photo-upload" accept="image/*" />
                        <x-button label="Change Photo" icon="o-camera" @click="document.getElementById('photo-upload').click()" />
                    </div>

                    @error('newPhoto')
                        <div class="mt-2 text-sm text-error">{{ $message }}</div>
                    @enderror

                    @if ($newPhoto)
                        <div class="mt-2 text-sm">
                            Photo Preview:
                            <div class="avatar">
                                <div class="w-16 h-16 rounded-full">
                                    <img src="{{ $newPhoto->temporaryUrl() }}" alt="New photo preview" />
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Password Change Card -->
            <x-card title="Change Password" class="mt-6">
                <div class="space-y-4">
                    <x-input
                        label="Current Password"
                        wire:model="currentPassword"
                        type="password"
                        placeholder="Enter your current password"
                    />

                    <x-input
                        label="New Password"
                        wire:model="newPassword"
                        type="password"
                        placeholder="Enter new password"
                    />

                    <x-input
                        label="Confirm New Password"
                        wire:model="newPasswordConfirmation"
                        type="password"
                        placeholder="Confirm new password"
                    />

                    <x-button
                        label="Update Password"
                        icon="o-key"
                        wire:click="updatePassword"
                        class="w-full btn-primary"
                    />
                </div>
            </x-card>
        </div>

        <!-- Profile Information Card -->
        <div class="lg:col-span-2">
            <x-card title="Profile Information">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-input
                        label="Full Name"
                        wire:model="name"
                        placeholder="Your full name"
                    />

                    <x-input
                        label="Email Address"
                        wire:model="email"
                        type="email"
                        placeholder="your.email@example.com"
                    />

                    <x-input
                        label="Phone Number"
                        wire:model="phone"
                        placeholder="Your contact number"
                    />

                    <x-input
                        label="Specialization"
                        wire:model="specialization"
                        placeholder="e.g. Mathematics, Physics, etc."
                    />

                    <div class="md:col-span-2">
                        <x-textarea
                            label="Teacher Bio"
                            wire:model="bio"
                            placeholder="Tell students about yourself, your qualifications, teaching style, and experience..."
                            rows="6"
                        />
                    </div>
                </div>

                <x-slot:footer>
                    <div class="flex justify-end">
                        <x-button label="Save Profile" icon="o-check" wire:click="saveProfile" class="btn-primary" />
                    </div>
                </x-slot:footer>
            </x-card>

            <!-- Subject Specializations Card -->
            <x-card title="Subject Specializations" class="mt-6">
                <div class="mb-4">
                    <p class="text-sm text-gray-600">Select the subjects you are qualified to teach:</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th class="w-16"></th>
                                <th>Subject</th>
                                <th>Code</th>
                                <th>Level</th>
                                <th>Curriculum</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($subjects as $subject)
                                <tr>
                                    <td>
                                        <x-checkbox wire:model.live="selectedSubjects" value="{{ $subject['id'] }}" />
                                    </td>
                                    <td>{{ $subject['name'] }}</td>
                                    <td>{{ $subject['code'] }}</td>
                                    <td>{{ $subject['level'] }}</td>
                                    <td>{{ $subject['curriculum'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-center text-gray-500">
                                        No subjects available. Please contact an administrator.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <x-slot:footer>
                    <div class="flex justify-end">
                        <x-button label="Save Specializations" icon="o-check" wire:click="saveProfile" class="btn-primary" />
                    </div>
                </x-slot:footer>
            </x-card>
        </div>
    </div>
</div>
