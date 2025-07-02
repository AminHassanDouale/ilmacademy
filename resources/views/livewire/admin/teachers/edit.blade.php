<?php

use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Teacher')] class extends Component {
    use Toast;

    // Model instance
    public TeacherProfile $teacherProfile;

    // Form data - User fields
    public string $name = '';
    public string $email = '';
    public ?string $password = '';
    public ?string $password_confirmation = '';
    public string $phone = '';
    public string $address = '';
    public string $status = '';

    // Form data - Teacher profile fields
    public string $bio = '';
    public string $specialization = '';
    public string $teacher_phone = '';
    public array $selectedSubjects = [];

    // Options
    protected array $validStatuses = ['active', 'inactive', 'suspended'];
    public array $subjectOptions = [];
    public array $specializationOptions = [];

    // Original data for change tracking
    protected array $originalData = [];

    // Mount the component
    public function mount(TeacherProfile $teacherProfile): void
    {
        $this->teacherProfile = $teacherProfile->load(['user', 'subjects']);

        Log::info('Teacher Edit Component Mounted', [
            'admin_user_id' => Auth::id(),
            'target_teacher_id' => $teacherProfile->id,
            'target_user_email' => $teacherProfile->user->email,
            'ip' => request()->ip()
        ]);

        // Load current teacher data into form
        $this->loadTeacherData();

        // Store original data for change tracking
        $this->storeOriginalData();

        // Load options
        $this->loadOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit page for teacher: {$teacherProfile->user->name} ({$teacherProfile->user->email})",
            TeacherProfile::class,
            $teacherProfile->id,
            [
                'target_teacher_name' => $teacherProfile->user->name,
                'target_teacher_email' => $teacherProfile->user->email,
                'ip' => request()->ip()
            ]
        );
    }

    // Load teacher data into form
    protected function loadTeacherData(): void
    {
        // User data
        $this->name = $this->teacherProfile->user->name;
        $this->email = $this->teacherProfile->user->email;
        $this->phone = $this->teacherProfile->user->phone ?? '';
        $this->address = $this->teacherProfile->user->address ?? '';
        $this->status = $this->teacherProfile->user->status;

        // Teacher profile data
        $this->bio = $this->teacherProfile->bio ?? '';
        $this->specialization = $this->teacherProfile->specialization ?? '';
        $this->teacher_phone = $this->teacherProfile->phone ?? '';
        $this->selectedSubjects = $this->teacherProfile->subjects ? $this->teacherProfile->subjects->pluck('id')->toArray() : [];

        Log::info('Teacher Data Loaded', [
            'teacher_id' => $this->teacherProfile->id,
            'form_data' => [
                'name' => $this->name,
                'email' => $this->email,
                'status' => $this->status,
                'specialization' => $this->specialization,
                'subjects' => $this->selectedSubjects,
            ]
        ]);
    }

    // Store original data for change tracking
    protected function storeOriginalData(): void
    {
        $this->originalData = [
            'name' => $this->teacherProfile->user->name,
            'email' => $this->teacherProfile->user->email,
            'phone' => $this->teacherProfile->user->phone ?? '',
            'address' => $this->teacherProfile->user->address ?? '',
            'status' => $this->teacherProfile->user->status,
            'bio' => $this->teacherProfile->bio ?? '',
            'specialization' => $this->teacherProfile->specialization ?? '',
            'teacher_phone' => $this->teacherProfile->phone ?? '',
            'subjects' => $this->teacherProfile->subjects ? $this->teacherProfile->subjects->pluck('id')->toArray() : [],
        ];
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
        Log::info('Teacher Update Started', [
            'admin_user_id' => Auth::id(),
            'target_teacher_id' => $this->teacherProfile->id,
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

            $validationRules = [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,' . $this->teacherProfile->user_id,
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:500',
                'status' => 'required|string|in:' . implode(',', $this->validStatuses),
                'bio' => 'nullable|string|max:1000',
                'specialization' => 'nullable|string|max:255',
                'teacher_phone' => 'nullable|string|max:20',
                'selectedSubjects' => 'nullable|array',
                'selectedSubjects.*' => 'integer|exists:subjects,id',
            ];

            // Add password validation only if password is provided
            if (!empty($this->password)) {
                $validationRules['password'] = ['required', 'confirmed', Password::defaults()];
            }

            $validated = $this->validate($validationRules, [
                'name.required' => 'Please enter a name.',
                'name.max' => 'Name must not exceed 255 characters.',
                'email.required' => 'Please enter an email address.',
                'email.email' => 'Please enter a valid email address.',
                'email.unique' => 'This email address is already taken.',
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

            Log::info('Validation Passed', ['validated_data' => Arr::except($validated, ['password'])]);

            // Check if admin is trying to change their own status
            if ($this->teacherProfile->user_id === Auth::id()) {
                if ($validated['status'] !== 'active') {
                    $this->addError('status', 'You cannot change your own status.');
                    return;
                }
            }

            // Prepare data for DB
            $userData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'status' => $validated['status'],
            ];

            // Add password if provided
            if (!empty($this->password)) {
                $userData['password'] = Hash::make($validated['password']);
            }

            $teacherData = [
                'bio' => $validated['bio'],
                'specialization' => $validated['specialization'],
                'phone' => $validated['teacher_phone'],
            ];

            // Track changes for activity log
            $changes = $this->getChanges($validated);

            Log::info('Prepared Data', [
                'user_data' => Arr::except($userData, ['password']),
                'teacher_data' => $teacherData,
                'changes' => $changes
            ]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Update user
            Log::debug('Updating User Record');
            $this->teacherProfile->user->update($userData);
            Log::info('User Updated Successfully', [
                'user_id' => $this->teacherProfile->user_id,
                'user_email' => $this->teacherProfile->user->email
            ]);

            // Update teacher profile
            Log::debug('Updating Teacher Profile');
            $this->teacherProfile->update($teacherData);
            Log::info('Teacher Profile Updated Successfully', [
                'teacher_profile_id' => $this->teacherProfile->id
            ]);

            // Update subjects
            Log::debug('Updating Teacher Subjects');
            $this->teacherProfile->subjects()->sync($validated['selectedSubjects'] ?? []);
            Log::info('Subjects Updated Successfully', [
                'teacher_profile_id' => $this->teacherProfile->id,
                'new_subjects' => $validated['selectedSubjects'] ?? []
            ]);

            // Log activity with changes
            if (!empty($changes)) {
                $changeDescription = "Updated teacher: {$this->teacherProfile->user->name} ({$this->teacherProfile->user->email}). Changes: " . implode(', ', $changes);

                ActivityLog::log(
                    Auth::id(),
                    'update',
                    $changeDescription,
                    TeacherProfile::class,
                    $this->teacherProfile->id,
                    [
                        'changes' => $changes,
                        'original_data' => $this->originalData,
                        'new_data' => Arr::except($validated, ['password', 'password_confirmation']),
                        'target_teacher_name' => $this->teacherProfile->user->name,
                        'target_teacher_email' => $this->teacherProfile->user->email,
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
            $this->success("Teacher '{$this->teacherProfile->user->name}' has been successfully updated.");
            Log::info('Success Toast Displayed');

            // Redirect to teacher show page
            Log::info('Redirecting to Teacher Show Page', [
                'teacher_profile_id' => $this->teacherProfile->id,
                'route' => 'admin.teachers.show'
            ]);

            $this->redirect(route('admin.teachers.show', $this->teacherProfile->id));

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
            Log::error('Teacher Update Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'teacher_id' => $this->teacherProfile->id,
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

    // Get changes between original and new data
    protected function getChanges(array $newData): array
    {
        $changes = [];

        // Map form fields to human-readable names
        $fieldMap = [
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'address' => 'Address',
            'status' => 'Status',
            'bio' => 'Biography',
            'specialization' => 'Specialization',
            'teacher_phone' => 'Teacher Phone',
            'selectedSubjects' => 'Subjects',
        ];

        foreach ($newData as $field => $newValue) {
            $originalField = $field === 'selectedSubjects' ? 'subjects' : ($field === 'teacher_phone' ? 'teacher_phone' : $field);
            $originalValue = $this->originalData[$originalField] ?? null;

            if ($field === 'selectedSubjects') {
                // Compare arrays for subjects
                $originalSubjects = is_array($originalValue) ? $originalValue : [];
                $newSubjects = is_array($newValue) ? $newValue : [];

                sort($originalSubjects);
                sort($newSubjects);

                if ($originalSubjects != $newSubjects) {
                    $changes[] = "Subjects assignment changed";
                }
            } elseif ($originalValue != $newValue) {
                $fieldName = $fieldMap[$field] ?? $field;

                if ($field === 'status') {
                    $changes[] = "{$fieldName} from " . ucfirst($originalValue) . " to " . ucfirst($newValue);
                } else {
                    $changes[] = "{$fieldName} changed";
                }
            }
        }

        // Check if password was changed
        if (!empty($this->password)) {
            $changes[] = "Password updated";
        }

        return $changes;
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
    <x-header title="Edit Teacher: {{ $teacherProfile->user->name }}" separator>
        <x-slot:actions>
            <x-button
                label="View Teacher"
                icon="o-eye"
                link="{{ route('admin.teachers.show', $teacherProfile->id) }}"
                class="btn-ghost"
            />
            <x-button
                label="Back to List"
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
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 {{ $teacherProfile->user_id === Auth::id() && $status !== 'active' ? 'border-red-300' : '' }}"
                                required
                                {{ $teacherProfile->user_id === Auth::id() ? 'disabled' : '' }}
                            >
                                @foreach($this->statusOptions as $value => $label)
                                    <option value="{{ $value }}" {{ $value == $status ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @if($teacherProfile->user_id === Auth::id())
                                <p class="mt-1 text-xs text-gray-500">You cannot change your own status</p>
                            @endif
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
                            Select the subjects this teacher will be teaching.
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
                            <p class="text-xs text-gray-400">Contact administrator to add subjects</p>
                        </div>
                    @endif

                    @error('selectedSubjects')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </x-card>

                <div class="flex justify-end pt-6">
                    <x-button
                        label="Cancel"
                        link="{{ route('admin.teachers.show', $teacherProfile->id) }}"
                        class="mr-2"
                    />
                    <x-button
                        label="Update Teacher"
                        icon="o-check"
                        type="submit"
                        color="primary"
                    />
                </div>
            </form>
        </div>

        <!-- Right column (1/3) - Info and Preview -->
        <div class="space-y-6">
            <!-- Current Teacher Info -->
            <x-card title="Current Teacher">
                <div class="flex items-center mb-4 space-x-4">
                    <div class="avatar">
                        <div class="w-16 h-16 rounded-full">
                            <img src="{{ $teacherProfile->user->profile_photo_url }}" alt="{{ $teacherProfile->user->name }}" />
                        </div>
                    </div>
                    <div>
                        <div class="text-lg font-semibold">{{ $teacherProfile->user->name }}</div>
                        <div class="text-sm text-gray-500">{{ $teacherProfile->user->email }}</div>
                        <div class="text-xs text-gray-400">ID: {{ $teacherProfile->id }}</div>
                    </div>
                </div>

                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Current Status</div>
                        <div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($teacherProfile->user->status) {
                                'active' => 'bg-green-100 text-green-800',
                                'inactive' => 'bg-gray-100 text-gray-600',
                                'suspended' => 'bg-red-100 text-red-800',
                                default => 'bg-gray-100 text-gray-600'
                            } }}">
                                {{ ucfirst($teacherProfile->user->status) }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Current Specialization</div>
                        <div>
                            @if($teacherProfile->specialization)
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-purple-800 bg-purple-100 rounded-full">
                                    {{ ucfirst($teacherProfile->specialization) }}
                                </span>
                            @else
                                <span class="text-gray-500">Not specified</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Current Subjects</div>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($teacherProfile->subjects as $subject)
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                                    {{ $subject->name }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Profile Created</div>
                        <div>{{ $teacherProfile->created_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $teacherProfile->updated_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Updated Preview Card -->
            <x-card title="Updated Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="flex items-center mb-4">
                        <div class="avatar">
                            <div class="w-16 h-16 rounded-full">
                                <img src="https://ui-avatars.com/api/?name={{ urlencode($name ?: $teacherProfile->user->name) }}&color=7F9CF5&background=EBF4FF" alt="Teacher Avatar" />
                            </div>
                        </div>
                        <div class="ml-4">
                            <div class="text-lg font-semibold">{{ $name ?: $teacherProfile->user->name }}</div>
                            <div class="text-sm text-gray-500">{{ $email ?: $teacherProfile->user->email }}</div>
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

                        @if($password)
                            <div class="text-orange-600">
                                <x-icon name="o-key" class="inline w-4 h-4 mr-1" />
                                <strong>Password will be updated</strong>
                            </div>
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
                        <div class="font-semibold">Password Changes</div>
                        <p class="text-gray-600">Leave the password field blank to keep the current password. If you enter a new password, it must meet the security requirements.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Subject Changes</div>
                        <p class="text-gray-600">Changing subject assignments will immediately affect what classes and materials the teacher can access.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Status Changes</div>
                        <ul class="mt-2 space-y-1 text-gray-600">
                            <li><strong>Active:</strong> Teacher can log in and access teaching features</li>
                            <li><strong>Inactive:</strong> Teacher account is disabled</li>
                            <li><strong>Suspended:</strong> Teacher is temporarily blocked</li>
                        </ul>
                    </div>

                    <div>
                        <div class="font-semibold">Self-Edit Restrictions</div>
                        <p class="text-gray-600">You cannot change your own status for security reasons.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Profile Information</div>
                        <p class="text-gray-600">Encourage teachers to maintain updated biography and contact information for better communication with students and parents.</p>
                    </div>
                </div>
            </x-card>

            <!-- Security Notice -->
            <x-card title="Security Notice" class="border-yellow-200 bg-yellow-50">
                <div class="space-y-2 text-sm">
                    <div class="flex items-start">
                        <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Teaching Impact</div>
                            <p class="text-yellow-700">Changes to subject assignments and status will immediately affect the teacher's access to teaching materials and student data.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Password Security</div>
                            <p class="text-yellow-700">If changing the password, ensure it meets security requirements. The teacher will need to use the new password on their next login.</p>
                        </div>
                    </div>

                    @if($teacherProfile->user_id === Auth::id())
                        <div class="flex items-start">
                            <x-icon name="o-lock-closed" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                            <div>
                                <div class="font-semibold text-yellow-800">Self-Edit Safety</div>
                                <p class="text-yellow-700">Some fields are restricted when editing your own account to prevent accidental lockout.</p>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>
