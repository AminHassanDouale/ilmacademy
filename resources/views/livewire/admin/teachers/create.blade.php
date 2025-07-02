<?php

use App\Models\User;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Teacher')] class extends Component {
    use Toast;

    // Form data - User fields
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $phone = '';
    public string $address = '';
    public string $status = 'active';

    // Form data - Teacher profile fields
    public string $bio = '';
    public string $specialization = '';
    public string $teacher_phone = '';
    public array $selectedSubjects = [];

    // Options
    protected array $validStatuses = ['active', 'inactive', 'suspended'];
    public array $subjectOptions = [];
    public array $specializationOptions = [];

    // Mount the component
    public function mount(): void
    {
        Log::info('Teacher Create Component Mounted', [
            'user_id' => Auth::id(),
            'ip' => request()->ip()
        ]);

        $this->loadOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed create teacher page',
            TeacherProfile::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Load options for dropdowns
    protected function loadOptions(): void
    {
        try {
            // Load subjects
            $subjects = \App\Models\Subject::orderBy('name')->get();
            $this->subjectOptions = $subjects->map(fn($subject) => [
                'id' => $subject->id,
                'name' => $subject->name,
                'description' => $subject->description ?? ''
            ])->toArray();

            // Load common specializations
            $commonSpecs = TeacherProfile::whereNotNull('specialization')
                ->distinct()
                ->pluck('specialization')
                ->filter()
                ->sort()
                ->values();

            $this->specializationOptions = $commonSpecs->map(fn($spec) => [
                'id' => $spec,
                'name' => ucfirst($spec)
            ])->toArray();

            Log::info('Options Loaded', [
                'subjects_count' => count($this->subjectOptions),
                'specializations_count' => count($this->specializationOptions)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to Load Options', [
                'error' => $e->getMessage()
            ]);

            // Fallback options
            $this->subjectOptions = [];
            $this->specializationOptions = [];
        }
    }

    // Save the teacher
    public function save(): void
    {
        Log::info('Teacher Create Started', [
            'user_id' => Auth::id(),
            'form_data' => [
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'status' => $this->status,
                'specialization' => $this->specialization,
                'selectedSubjects' => $this->selectedSubjects,
            ]
        ]);

        try {
            // Validate form data
            Log::debug('Starting Validation');

            $validated = $this->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => ['required', 'confirmed', Password::defaults()],
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:500',
                'status' => 'required|string|in:' . implode(',', $this->validStatuses),
                'bio' => 'nullable|string|max:1000',
                'specialization' => 'nullable|string|max:255',
                'teacher_phone' => 'nullable|string|max:20',
                'selectedSubjects' => 'nullable|array',
                'selectedSubjects.*' => 'integer|exists:subjects,id',
            ], [
                'name.required' => 'Please enter a name.',
                'name.max' => 'Name must not exceed 255 characters.',
                'email.required' => 'Please enter an email address.',
                'email.email' => 'Please enter a valid email address.',
                'email.unique' => 'This email address is already taken.',
                'password.required' => 'Please enter a password.',
                'password.confirmed' => 'Password confirmation does not match.',
                'phone.max' => 'Phone number must not exceed 20 characters.',
                'address.max' => 'Address must not exceed 500 characters.',
                'status.required' => 'Please select a status.',
                'status.in' => 'The selected status is invalid.',
                'bio.max' => 'Biography must not exceed 1000 characters.',
                'specialization.max' => 'Specialization must not exceed 255 characters.',
                'teacher_phone.max' => 'Teacher phone must not exceed 20 characters.',
                'selectedSubjects.*.exists' => 'One or more selected subjects are invalid.',
            ]);

            Log::info('Validation Passed', ['validated_data' => \Illuminate\Support\Arr::except($validated, ['password'])]);

            // Prepare data for DB
            $userData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'status' => $validated['status'],
                'email_verified_at' => now(), // Auto-verify for admin-created users
            ];

            $teacherData = [
                'bio' => $validated['bio'],
                'specialization' => $validated['specialization'],
                'phone' => $validated['teacher_phone'],
            ];

            Log::info('Prepared Data', [
                'user_data' => \Illuminate\Support\Arr::except($userData, ['password']),
                'teacher_data' => $teacherData
            ]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Create user
            Log::debug('Creating User Record');
            $user = User::create($userData);
            Log::info('User Created Successfully', [
                'user_id' => $user->id,
                'user_email' => $user->email
            ]);

            // Assign teacher role
            Log::debug('Assigning Teacher Role');
            $user->assignRole('teacher');
            Log::info('Teacher Role Assigned Successfully', [
                'user_id' => $user->id
            ]);

            // Create teacher profile
            Log::debug('Creating Teacher Profile');
            $teacherProfile = TeacherProfile::create([
                'user_id' => $user->id,
                ...$teacherData
            ]);
            Log::info('Teacher Profile Created Successfully', [
                'teacher_profile_id' => $teacherProfile->id,
                'user_id' => $user->id
            ]);

            // Assign subjects if selected
            if (!empty($validated['selectedSubjects'])) {
                Log::debug('Assigning Subjects');
                $teacherProfile->subjects()->sync($validated['selectedSubjects']);
                Log::info('Subjects Assigned Successfully', [
                    'teacher_profile_id' => $teacherProfile->id,
                    'subjects' => $validated['selectedSubjects']
                ]);
            }

            // Log activity
            Log::debug('Logging Activity');
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created teacher profile for: {$validated['name']} ({$validated['email']}) with specialization: " . ($validated['specialization'] ?: 'None'),
                TeacherProfile::class,
                $teacherProfile->id,
                [
                    'user_name' => $validated['name'],
                    'user_email' => $validated['email'],
                    'specialization' => $validated['specialization'],
                    'status' => $validated['status'],
                    'subjects_assigned' => $validated['selectedSubjects'] ?? [],
                    'user_id' => $user->id
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Show success toast
            $this->success("Teacher profile for '{$validated['name']}' has been successfully created.");
            Log::info('Success Toast Displayed');

            // Redirect to teacher show page
            Log::info('Redirecting to Teacher Show Page', [
                'teacher_profile_id' => $teacherProfile->id,
                'route' => 'admin.teachers.show'
            ]);

            $this->redirect(route('admin.teachers.show', $teacherProfile->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'name' => $this->name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'status' => $this->status,
                    'specialization' => $this->specialization,
                    'selectedSubjects' => $this->selectedSubjects,
                ]
            ]);

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Teacher Creation Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'form_data' => [
                    'name' => $this->name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'status' => $this->status,
                    'specialization' => $this->specialization,
                    'selectedSubjects' => $this->selectedSubjects,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get status options for dropdown
    public function getStatusOptionsProperty(): array
    {
        return [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'suspended' => 'Suspended',
        ];
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
    <x-header title="Create New Teacher" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('admin.teachers.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <form wire:submit="save" class="space-y-6">
                <!-- User Information -->
                <x-card title="User Information">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Name -->
                        <div>
                            <x-input
                                label="Full Name"
                                wire:model.live="name"
                                placeholder="Enter teacher's full name"
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
                                placeholder="teacher@example.com"
                                required
                            />
                            @error('email')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Password -->
                        <div>
                            <x-input
                                label="Password"
                                wire:model.live="password"
                                type="password"
                                placeholder="Enter secure password"
                                required
                            />
                            @error('password')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Password Confirmation -->
                        <div>
                            <x-input
                                label="Confirm Password"
                                wire:model.live="password_confirmation"
                                type="password"
                                placeholder="Confirm password"
                                required
                            />
                            @error('password_confirmation')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Phone -->
                        <div>
                            <x-input
                                label="Phone Number"
                                wire:model.live="phone"
                                placeholder="Optional phone number"
                            />
                            @error('phone')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Status *</label>
                            <select
                                wire:model.live="status"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                @foreach($this->statusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Address -->
                        <div class="md:col-span-2">
                            <x-textarea
                                label="Address"
                                wire:model.live="address"
                                placeholder="Optional address"
                                rows="3"
                            />
                            @error('address')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </x-card>

                <!-- Teacher Profile Information -->
                <x-card title="Teacher Profile Information">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Specialization -->
                        <div>
                            <x-input
                                label="Specialization"
                                wire:model.live="specialization"
                                placeholder="e.g., Mathematics, Science, English"
                                list="specializations"
                            />
                            <datalist id="specializations">
                                @foreach($specializationOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                @endforeach
                            </datalist>
                            @error('specialization')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Teacher Phone (separate from user phone) -->
                        <div>
                            <x-input
                                label="Teacher Phone"
                                wire:model.live="teacher_phone"
                                placeholder="Optional separate phone for teaching"
                            />
                            <p class="mt-1 text-xs text-gray-500">This can be different from the main phone number</p>
                            @error('teacher_phone')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Biography -->
                        <div class="md:col-span-2">
                            <x-textarea
                                label="Biography"
                                wire:model.live="bio"
                                placeholder="Brief description about the teacher's background and experience"
                                rows="4"
                            />
                            @error('bio')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </x-card>

                <!-- Subjects Section -->
                <x-card title="Subject Assignment">
                    <div class="mb-4">
                        <label class="block mb-2 text-sm font-medium text-gray-700">
                            Subjects to Teach
                        </label>
                        <p class="mb-4 text-sm text-gray-500">
                            Select the subjects this teacher will be teaching. You can modify this later.
                        </p>
                    </div>

                    @if(count($subjectOptions) > 0)
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
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
                                            @if($subject['description'])
                                                <div class="text-sm text-gray-500">{{ $subject['description'] }}</div>
                                            @endif
                                        </div>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="py-8 text-center">
                            <x-icon name="o-book-open" class="w-12 h-12 mx-auto text-gray-300" />
                            <div class="mt-2 text-sm text-gray-500">No subjects available</div>
                            <p class="text-xs text-gray-400">Subjects can be assigned later</p>
                        </div>
                    @endif

                    @error('selectedSubjects')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </x-card>

                <div class="flex justify-end pt-6">
                    <x-button
                        label="Cancel"
                        link="{{ route('admin.teachers.index') }}"
                        class="mr-2"
                    />
                    <x-button
                        label="Create Teacher"
                        icon="o-academic-cap"
                        type="submit"
                        color="primary"
                    />
                </div>
            </form>
        </div>

        <!-- Right column (1/3) - Preview and Help -->
        <div class="space-y-6">
            <!-- Teacher Preview Card -->
            <x-card title="Teacher Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="flex items-center mb-4">
                        <div class="avatar">
                            <div class="w-16 h-16 rounded-full">
                                <img src="https://ui-avatars.com/api/?name={{ urlencode($name ?: 'Teacher Name') }}&color=7F9CF5&background=EBF4FF" alt="Teacher Avatar" />
                            </div>
                        </div>
                        <div class="ml-4">
                            <div class="text-lg font-semibold">{{ $name ?: 'Teacher Name' }}</div>
                            <div class="text-sm text-gray-500">{{ $email ?: 'teacher@example.com' }}</div>
                        </div>
                    </div>

                    <div class="space-y-3 text-sm">
                        <div>
                            <strong>Status:</strong>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ml-2 {{ match($status) {
                                'active' => 'bg-green-100 text-green-800',
                                'inactive' => 'bg-gray-100 text-gray-600',
                                'suspended' => 'bg-red-100 text-red-800',
                                default => 'bg-gray-100 text-gray-600'
                            } }}">
                                {{ ucfirst($status) }}
                            </span>
                        </div>

                        @if($specialization)
                            <div>
                                <strong>Specialization:</strong>
                                <span class="inline-flex items-center px-2 py-1 ml-2 text-xs font-medium text-purple-800 bg-purple-100 rounded-full">
                                    {{ ucfirst($specialization) }}
                                </span>
                            </div>
                        @endif

                        @if($phone)
                            <div><strong>Phone:</strong> {{ $phone }}</div>
                        @endif

                        @if($teacher_phone && $teacher_phone !== $phone)
                            <div><strong>Teacher Phone:</strong> {{ $teacher_phone }}</div>
                        @endif

                        @if($address)
                            <div><strong>Address:</strong> {{ $address }}</div>
                        @endif

                        @if($bio)
                            <div><strong>Bio:</strong> {{ Str::limit($bio, 100) }}</div>
                        @endif

                        <div>
                            <strong>Subjects:</strong>
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

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Teacher Role</div>
                        <p class="text-gray-600">Teachers are automatically assigned the "teacher" role which gives them access to teaching-related features like creating sessions, managing exams, and viewing student progress.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Password Requirements</div>
                        <p class="text-gray-600">Password must be at least 8 characters long and should contain a mix of letters, numbers, and special characters.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Specialization</div>
                        <p class="text-gray-600">Enter the teacher's main area of expertise. This helps in organizing and filtering teachers by their specializations.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Subject Assignment</div>
                        <p class="text-gray-600">You can assign multiple subjects to a teacher. These assignments can be modified later from the teacher's profile page.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Contact Information</div>
                        <p class="text-gray-600">Teachers can have separate phone numbers for general contact and teaching-related contact.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Email Verification</div>
                        <p class="text-gray-600">Teachers created by administrators are automatically verified. They will receive a welcome email with their login credentials.</p>
                    </div>
                </div>
            </x-card>

            <!-- Security Notice -->
            <x-card title="Security Notice" class="border-yellow-200 bg-yellow-50">
                <div class="space-y-2 text-sm">
                    <div class="flex items-start">
                        <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Password Security</div>
                            <p class="text-yellow-700">Make sure to use a strong password. The teacher will be able to change their password after first login.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Access Control</div>
                            <p class="text-yellow-700">Teachers have specific permissions for teaching-related activities. Ensure subject assignments are accurate.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-user-group" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Profile Completion</div>
                            <p class="text-yellow-700">Encourage teachers to complete their profiles with biography and contact information for better student-teacher interaction.</p>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
