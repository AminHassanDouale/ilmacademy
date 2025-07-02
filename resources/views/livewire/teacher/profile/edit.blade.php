<?php

use App\Models\User;
use App\Models\TeacherProfile;
use App\Models\Subject;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Teacher Profile')] class extends Component {
    use Toast;

    // Current user and teacher profile
    public User $user;
    public ?TeacherProfile $teacherProfile = null;

    // Form data
    public string $name = '';
    public string $email = '';
    public ?string $password = '';
    public ?string $password_confirmation = '';
    public string $phone = '';
    public string $bio = '';
    public string $specialization = '';
    public array $selectedSubjects = [];

    // Options
    public array $subjectOptions = [];

    // Original data for change tracking
    protected array $originalData = [];

    // Mount the component
    public function mount(): void
    {
        $this->user = Auth::user()->load(['teacherProfile', 'teacherProfile.subjects']);
        $this->teacherProfile = $this->user->teacherProfile;

        Log::info('Teacher Profile Edit Component Mounted', [
            'teacher_user_id' => $this->user->id,
            'teacher_profile_id' => $this->teacherProfile?->id,
            'ip' => request()->ip()
        ]);

        // Load current data into form
        $this->loadUserData();

        // Store original data for change tracking
        $this->storeOriginalData();

        // Load subject options
        $this->loadSubjectOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed teacher profile edit page",
            TeacherProfile::class,
            $this->teacherProfile?->id,
            ['ip' => request()->ip()]
        );
    }

    // Load user data into form
    protected function loadUserData(): void
    {
        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->phone = $this->user->phone ?? '';

        if ($this->teacherProfile) {
            $this->bio = $this->teacherProfile->bio ?? '';
            $this->specialization = $this->teacherProfile->specialization ?? '';
            $this->selectedSubjects = $this->teacherProfile->subjects ?
                $this->teacherProfile->subjects->pluck('id')->toArray() : [];
        }

        Log::info('Teacher Profile Data Loaded', [
            'teacher_id' => $this->user->id,
            'form_data' => [
                'name' => $this->name,
                'email' => $this->email,
                'specialization' => $this->specialization,
                'subjects_count' => count($this->selectedSubjects),
            ]
        ]);
    }

    // Store original data for change tracking
    protected function storeOriginalData(): void
    {
        $this->originalData = [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->user->phone ?? '',
            'bio' => $this->teacherProfile->bio ?? '',
            'specialization' => $this->teacherProfile->specialization ?? '',
            'subjects' => $this->teacherProfile?->subjects ?
                $this->teacherProfile->subjects->pluck('id')->toArray() : [],
        ];
    }

    // Load subject options
    protected function loadSubjectOptions(): void
    {
        try {
            $subjects = Subject::with('curriculum')->orderBy('name')->get();
            $this->subjectOptions = $subjects->map(fn($subject) => [
                'id' => $subject->id,
                'name' => $subject->name,
                'code' => $subject->code,
                'curriculum' => $subject->curriculum ? $subject->curriculum->name : 'Unknown',
                'level' => $subject->level
            ])->toArray();

            Log::info('Subject Options Loaded', [
                'subjects_count' => count($this->subjectOptions),
                'subjects' => array_column($this->subjectOptions, 'name')
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to Load Subject Options', [
                'error' => $e->getMessage()
            ]);

            $this->subjectOptions = [];
        }
    }

    // Save the profile
    public function save(): void
    {
        Log::info('Teacher Profile Update Started', [
            'teacher_user_id' => $this->user->id,
            'form_data' => [
                'name' => $this->name,
                'email' => $this->email,
                'specialization' => $this->specialization,
                'selectedSubjects' => $this->selectedSubjects,
            ]
        ]);

        try {
            // Validate form data
            Log::debug('Starting Validation');

            $validationRules = [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,' . $this->user->id,
                'phone' => 'nullable|string|max:20',
                'bio' => 'nullable|string|max:1000',
                'specialization' => 'nullable|string|max:255',
                'selectedSubjects' => 'nullable|array',
                'selectedSubjects.*' => 'integer|exists:subjects,id',
            ];

            // Add password validation only if password is provided
            if (!empty($this->password)) {
                $validationRules['password'] = ['required', 'confirmed', Password::defaults()];
            }

            $validated = $this->validate($validationRules, [
                'name.required' => 'Please enter your name.',
                'name.max' => 'Name must not exceed 255 characters.',
                'email.required' => 'Please enter your email address.',
                'email.email' => 'Please enter a valid email address.',
                'email.unique' => 'This email address is already taken.',
                'password.confirmed' => 'Password confirmation does not match.',
                'phone.max' => 'Phone number must not exceed 20 characters.',
                'bio.max' => 'Bio must not exceed 1000 characters.',
                'specialization.max' => 'Specialization must not exceed 255 characters.',
                'selectedSubjects.*.exists' => 'One or more selected subjects are invalid.',
            ]);

            Log::info('Validation Passed', ['validated_data' => \Illuminate\Support\Arr::except($validated, ['password'])]);

            // Prepare user data for DB
            $userData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
            ];

            // Add password if provided
            if (!empty($this->password)) {
                $userData['password'] = Hash::make($validated['password']);
            }

            // Prepare teacher profile data
            $teacherData = [
                'bio' => $validated['bio'],
                'specialization' => $validated['specialization'],
                'phone' => $validated['phone'], // Also store in teacher profile for redundancy
            ];

            // Track changes for activity log
            $changes = $this->getChanges($validated);

            Log::info('Prepared Data', [
                'user_data' => \Illuminate\Support\Arr::except($userData, ['password']),
                'teacher_data' => $teacherData,
                'changes' => $changes
            ]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Update user
            Log::debug('Updating User Record');
            $this->user->update($userData);
            Log::info('User Updated Successfully', [
                'user_id' => $this->user->id,
                'user_email' => $this->user->email
            ]);

            // Create or update teacher profile
            Log::debug('Creating/Updating Teacher Profile');
            if ($this->teacherProfile) {
                $this->teacherProfile->update($teacherData);
                Log::info('Teacher Profile Updated Successfully', [
                    'teacher_profile_id' => $this->teacherProfile->id
                ]);
            } else {
                $teacherData['user_id'] = $this->user->id;
                $this->teacherProfile = TeacherProfile::create($teacherData);
                Log::info('Teacher Profile Created Successfully', [
                    'teacher_profile_id' => $this->teacherProfile->id
                ]);
            }

            // Update subject assignments
            Log::debug('Updating Subject Assignments');
            $this->teacherProfile->subjects()->sync($validated['selectedSubjects'] ?? []);
            Log::info('Subject Assignments Updated Successfully', [
                'teacher_profile_id' => $this->teacherProfile->id,
                'subjects' => $validated['selectedSubjects'] ?? []
            ]);

            // Log activity with changes
            if (!empty($changes)) {
                $changeDescription = "Updated teacher profile. Changes: " . implode(', ', $changes);

                ActivityLog::log(
                    Auth::id(),
                    'update',
                    $changeDescription,
                    TeacherProfile::class,
                    $this->teacherProfile->id,
                    [
                        'changes' => $changes,
                        'original_data' => $this->originalData,
                        'new_data' => \Illuminate\Support\Arr::except($validated, ['password', 'password_confirmation']),
                        'password_changed' => !empty($this->password)
                    ]
                );
            }

            DB::commit();
            Log::info('Database Transaction Committed');

            // Update original data for future comparisons
            $this->storeOriginalData();

            // Clear password fields
            $this->password = '';
            $this->password_confirmation = '';

            // Show success toast
            $this->success("Your teacher profile has been successfully updated.");
            Log::info('Success Toast Displayed');

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'name' => $this->name,
                    'email' => $this->email,
                    'specialization' => $this->specialization,
                    'selectedSubjects' => $this->selectedSubjects,
                ]
            ]);

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Teacher Profile Update Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'user_id' => $this->user->id,
                'form_data' => [
                    'name' => $this->name,
                    'email' => $this->email,
                    'specialization' => $this->specialization,
                    'selectedSubjects' => $this->selectedSubjects,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get changes between original and new data
    protected function getChanges(array $newData): array
    {
        $changes = [];

        // Map form fields to human-readable names
        $fieldMap = [
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'bio' => 'Bio',
            'specialization' => 'Specialization',
            'selectedSubjects' => 'Subject Assignments',
        ];

        foreach ($newData as $field => $newValue) {
            $originalField = $field === 'selectedSubjects' ? 'subjects' : $field;
            $originalValue = $this->originalData[$originalField] ?? null;

            if ($field === 'selectedSubjects') {
                // Compare arrays for subjects
                $originalSubjects = is_array($originalValue) ? $originalValue : [];
                $newSubjects = is_array($newValue) ? $newValue : [];

                sort($originalSubjects);
                sort($newSubjects);

                if ($originalSubjects != $newSubjects) {
                    $changes[] = "Subject assignments updated";
                }
            } elseif ($originalValue != $newValue) {
                $fieldName = $fieldMap[$field] ?? $field;
                $changes[] = "{$fieldName} updated";
            }
        }

        // Check if password was changed
        if (!empty($this->password)) {
            $changes[] = "Password updated";
        }

        return $changes;
    }

    // Toggle subject selection
    public function toggleSubject(int $subjectId): void
    {
        if (in_array($subjectId, $this->selectedSubjects)) {
            $this->selectedSubjects = array_values(array_filter($this->selectedSubjects, fn($id) => $id !== $subjectId));
        } else {
            $this->selectedSubjects[] = $subjectId;
        }

        Log::debug('Subject Toggled', [
            'subject_id' => $subjectId,
            'selected_subjects' => $this->selectedSubjects
        ]);
    }

    // Check if subject is selected
    public function isSubjectSelected(int $subjectId): bool
    {
        return in_array($subjectId, $this->selectedSubjects);
    }

    public function with(): array
    {
        return [
            // Empty array - we use computed properties instead
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Edit Teacher Profile" separator>
        <x-slot:actions>
            <x-button
                label="View Profile"
                icon="o-eye"
                link="{{ route('teacher.profile.show') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Profile Information">
                <form wire:submit="save" class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Name -->
                        <div>
                            <x-input
                                label="Full Name"
                                wire:model.live="name"
                                placeholder="Enter your full name"
                                required
                            />
                            @error('name')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Email -->
                        <div>
                            <x-input
                                label="Email Address"
                                wire:model.live="email"
                                type="email"
                                placeholder="your.email@example.com"
                                required
                            />
                            @error('email')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Phone -->
                        <div>
                            <x-input
                                label="Phone Number"
                                wire:model.live="phone"
                                placeholder="Your phone number"
                            />
                            @error('phone')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Specialization -->
                        <div>
                            <x-input
                                label="Specialization"
                                wire:model.live="specialization"
                                placeholder="e.g., Mathematics, Physics, etc."
                            />
                            @error('specialization')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Bio -->
                        <div class="md:col-span-2">
                            <x-textarea
                                label="Bio"
                                wire:model.live="bio"
                                placeholder="Tell us about yourself, your teaching experience, and qualifications..."
                                rows="4"
                            />
                            @error('bio')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Password -->
                        <div>
                            <x-input
                                label="New Password"
                                wire:model.live="password"
                                type="password"
                                placeholder="Leave blank to keep current password"
                            />
                            <p class="mt-1 text-xs text-gray-500">Leave blank to keep the current password</p>
                            @error('password')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Password Confirmation -->
                        <div>
                            <x-input
                                label="Confirm New Password"
                                wire:model.live="password_confirmation"
                                type="password"
                                placeholder="Confirm new password"
                            />
                            @error('password_confirmation')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Subjects Section -->
                    <div class="pt-6 border-t">
                        <div class="mb-4">
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Teaching Subjects
                            </label>
                            <p class="mb-4 text-sm text-gray-500">
                                Select the subjects you teach. This helps in assigning sessions and exams.
                            </p>
                        </div>

                        @if(count($subjectOptions) > 0)
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                @foreach($subjectOptions as $subject)
                                    <div class="relative">
                                        <label class="flex items-start p-4 border-2 rounded-lg cursor-pointer hover:bg-gray-50 {{ $this->isSubjectSelected($subject['id']) ? 'border-blue-500 bg-blue-50' : 'border-gray-200' }}">
                                            <input
                                                type="checkbox"
                                                wire:click="toggleSubject({{ $subject['id'] }})"
                                                {{ $this->isSubjectSelected($subject['id']) ? 'checked' : '' }}
                                                class="w-4 h-4 mt-1 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                            />
                                            <div class="ml-3">
                                                <div class="font-medium text-gray-900">{{ $subject['name'] }}</div>
                                                <div class="text-sm text-gray-500">
                                                    Code: {{ $subject['code'] }}
                                                    @if($subject['level'])
                                                        • Level: {{ $subject['level'] }}
                                                    @endif
                                                </div>
                                                <div class="text-xs text-gray-400">{{ $subject['curriculum'] }}</div>
                                            </div>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="py-8 text-center">
                                <x-icon name="o-academic-cap" class="w-12 h-12 mx-auto text-gray-300" />
                                <div class="mt-2 text-sm text-gray-500">No subjects available</div>
                                <p class="mt-1 text-xs text-gray-400">Contact the administrator to add subjects</p>
                            </div>
                        @endif

                        @error('selectedSubjects')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end pt-6">
                        <x-button
                            label="Save Changes"
                            icon="o-check"
                            type="submit"
                            color="primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Preview and Info -->
        <div class="space-y-6">
            <!-- Profile Preview -->
            <x-card title="Profile Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="flex items-center mb-4">
                        <div class="avatar">
                            <div class="w-16 h-16 rounded-full">
                                <img src="https://ui-avatars.com/api/?name={{ urlencode($name ?: 'Teacher Name') }}&color=7F9CF5&background=EBF4FF" alt="Profile Avatar" />
                            </div>
                        </div>
                        <div class="ml-4">
                            <div class="text-lg font-semibold">{{ $name ?: 'Teacher Name' }}</div>
                            <div class="text-sm text-gray-500">{{ $email ?: 'teacher@example.com' }}</div>
                        </div>
                    </div>

                    <div class="space-y-3 text-sm">
                        @if($specialization)
                            <div><strong>Specialization:</strong> {{ $specialization }}</div>
                        @endif

                        @if($phone)
                            <div><strong>Phone:</strong> {{ $phone }}</div>
                        @endif

                        @if($bio)
                            <div>
                                <strong>Bio:</strong>
                                <div class="p-2 mt-1 text-xs rounded bg-gray-50">{{ $bio }}</div>
                            </div>
                        @endif

                        @if($password)
                            <div class="text-orange-600">
                                <x-icon name="o-key" class="inline w-4 h-4 mr-1" />
                                <strong>Password will be updated</strong>
                            </div>
                        @endif

                        <div>
                            <strong>Teaching Subjects:</strong>
                            @if(count($selectedSubjects) > 0)
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($selectedSubjects as $subjectId)
                                        @php
                                            $subject = collect($subjectOptions)->firstWhere('id', $subjectId);
                                        @endphp
                                        @if($subject)
                                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                                                {{ $subject['name'] }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @else
                                <span class="text-gray-500">No subjects selected</span>
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Current Profile Info -->
            @if($teacherProfile)
                <x-card title="Current Profile">
                    <div class="space-y-3 text-sm">
                        <div>
                            <div class="font-medium text-gray-500">Profile Created</div>
                            <div>{{ $teacherProfile->created_at->format('M d, Y \a\t g:i A') }}</div>
                        </div>

                        <div>
                            <div class="font-medium text-gray-500">Last Updated</div>
                            <div>{{ $teacherProfile->updated_at->format('M d, Y \a\t g:i A') }}</div>
                        </div>

                        @if($teacherProfile->subjects->count() > 0)
                            <div>
                                <div class="font-medium text-gray-500">Current Subjects</div>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($teacherProfile->subjects as $subject)
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-green-800 bg-green-100 rounded-full">
                                            {{ $subject->name }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Help Card -->
            <x-card title="Profile Tips">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Profile Completion</div>
                        <p class="text-gray-600">Complete your profile to help students and administrators know more about your expertise and teaching areas.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Subject Assignment</div>
                        <p class="text-gray-600">Select all subjects you're qualified to teach. This affects which sessions and exams you can manage.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Bio Guidelines</div>
                        <p class="text-gray-600">Include your education background, teaching experience, and any special qualifications or certifications.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Contact Information</div>
                        <p class="text-gray-600">Keep your phone number updated for important communications from the administration.</p>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
