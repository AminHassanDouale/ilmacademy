<?php

use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

new #[Title('Edit Teacher')] class extends Component {
    use WithFileUploads;
    use Toast;

    // Teacher to edit
    public TeacherProfile $teacher;

    // Form attributes
    public string $name = '';
    public string $email = '';
    public ?string $phone = '';
    public ?string $specialization = '';
    public ?string $bio = '';
    public string $status = '';

    // Photo management
    public $photo;

    // Component initialization - match the route parameter name
    public function mount(TeacherProfile $teacher): void
    {
        $this->teacher = $teacher;

        // Get the associated user
        $user = $teacher->user;

        if (!$user) {
            $this->error("Teacher profile has no associated user account.");
            // Use redirect() without return for void methods
            redirect()->route('admin.teachers.index');
            return; // Just return without a value
        }

        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $teacher->phone ?? '';
        $this->specialization = $teacher->specialization ?? '';
        $this->bio = $teacher->bio ?? '';
        $this->status = $user->status ?? 'active';

        // Log access to edit page
        $this->logActivity(
            'access',
            "Accessed edit page for teacher: {$user->name}",
            $teacher
        );
    }

    // Validation rules
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->teacher->user_id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'specialization' => ['required', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:active,inactive,suspended'],
            'photo' => ['nullable', 'image', 'max:1024'],
        ];
    }

    // Custom validation messages
    public function messages(): array
    {
        return [
            'name.required' => 'Name is required',
            'email.required' => 'Email is required',
            'email.email' => 'Please enter a valid email address',
            'email.unique' => 'This email address is already in use',
            'specialization.required' => 'Specialization is required',
            'status.required' => 'Status is required',
            'status.in' => 'The selected status is not valid',
        ];
    }

    /**
     * Log user activity
     */
    private function logActivity(string $type, string $description, $subject, array $additionalData = []): void
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => $type,
            'description' => $description,
            'loggable_type' => get_class($subject),
            'loggable_id' => $subject->id,
            'ip_address' => request()->ip(),
            'additional_data' => $additionalData,
        ]);
    }

    /**
     * Update the teacher
     */
    public function save(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            // Get the user associated with this teacher
            $user = $this->teacher->user;

            if (!$user) {
                throw new \Exception("Teacher profile has no associated user account.");
            }

            // Get original values for logging
            $originalValues = [
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'phone' => $this->teacher->phone,
                'specialization' => $this->teacher->specialization,
            ];

            // Update user data
            $user->name = $this->name;
            $user->email = $this->email;
            $user->status = $this->status;

            // Process photo upload if present
            if ($this->photo) {
                // Handle photo upload here - you might need to implement this based on your storage setup
                // Example: $user->updateProfilePhoto($this->photo);
            }

            // Save user changes
            $user->save();

            // Update teacher profile
            $this->teacher->phone = $this->phone;
            $this->teacher->specialization = $this->specialization;
            $this->teacher->bio = $this->bio;
            $this->teacher->save();

            // Log changes
            $this->logActivity(
                'update',
                "Updated teacher: {$user->name}",
                $this->teacher,
                [
                    'changed_fields' => array_filter([
                        'name' => $originalValues['name'] !== $this->name ? [
                            'old' => $originalValues['name'],
                            'new' => $this->name,
                        ] : null,
                        'email' => $originalValues['email'] !== $this->email ? [
                            'old' => $originalValues['email'],
                            'new' => $this->email,
                        ] : null,
                        'status' => $originalValues['status'] !== $this->status ? [
                            'old' => $originalValues['status'],
                            'new' => $this->status,
                        ] : null,
                        'phone' => $originalValues['phone'] !== $this->phone ? [
                            'old' => $originalValues['phone'],
                            'new' => $this->phone,
                        ] : null,
                        'specialization' => $originalValues['specialization'] !== $this->specialization ? [
                            'old' => $originalValues['specialization'],
                            'new' => $this->specialization,
                        ] : null,
                    ])
                ]
            );

            DB::commit();

            // Success notification using toast
            $this->success("Teacher {$user->name} has been successfully updated.");

            // Redirect to teachers list
            redirect()->route('admin.teachers.index');

        } catch (\Exception $e) {
            DB::rollBack();

            // Error notification using toast
            $this->error("An error occurred while updating the teacher: {$e->getMessage()}");
        }
    }

    /**
     * Delete the teacher
     */
    public function delete(): void
    {
        try {
            DB::beginTransaction();

            $user = $this->teacher->user;

            if (!$user) {
                throw new \Exception("Teacher profile has no associated user account.");
            }

            $teacherName = $user->name;

            // Check if user is deleting themselves
            if ($user->id === Auth::id()) {
                $this->error("You cannot delete your own account!");
                return;
            }

            // Delete related records if methods exist
            if (method_exists($this->teacher, 'sessions')) {
                $this->teacher->sessions()->delete();
            }
            if (method_exists($this->teacher, 'exams')) {
                $this->teacher->exams()->delete();
            }
            if (method_exists($this->teacher, 'timetableSlots')) {
                $this->teacher->timetableSlots()->delete();
            }

            // Log before deletion
            $this->logActivity(
                'delete',
                "Deleted teacher: $teacherName",
                $this->teacher,
                ['teacher_name' => $teacherName]
            );

            // Delete the teacher profile
            $this->teacher->delete();

            // Remove the teacher role from the user
            $user->removeRole('teacher');

            DB::commit();

            // Success notification using toast
            $this->success("Teacher $teacherName has been successfully deleted.");

            // Redirect to teachers list
            redirect()->route('admin.teachers.index');

        } catch (\Exception $e) {
            DB::rollBack();

            // Error notification using toast
            $this->error("An error occurred while deleting the teacher: {$e->getMessage()}");
        }
    }

    /**
     * Cancel editing and return to list
     */
    public function cancel(): void
    {
        redirect()->route('admin.teachers.index');
    }

    /**
     * Get teacher's activity logs
     */
    public function activityLogs()
    {
        return ActivityLog::where(function ($query) {
                $query->where('loggable_type', TeacherProfile::class)
                      ->where('loggable_id', $this->teacher->id);
            })
            ->orWhere(function ($query) {
                $query->where('loggable_type', User::class)
                      ->where('loggable_id', $this->teacher->user_id);
            })
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();
    }

    public function with(): array
    {
        return [
            'activity_logs' => $this->activityLogs(),
        ];
    }
};?>
<div>
    <x-header title="Edit Teacher: {{ $teacher->user->name ?? 'Unknown' }}" separator back="{{ route('admin.teachers.index') }}">
        <x-slot:actions>
            <div class="flex gap-2">
                <x-button
                    label="Delete"
                    icon="o-trash"
                    color="error"
                    x-data
                    x-on:click="
                        if (confirm('Are you sure you want to delete this teacher? This action is irreversible.')) {
                            $wire.delete()
                        }
                    "
                />
                <x-button label="Cancel" icon="o-x-mark" wire:click="cancel" />
                <x-button label="Save" icon="o-check" wire:click="save" class="btn-primary" />
            </div>
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 mb-6 lg:grid-cols-3">
        <!-- Main section - Edit form -->
        <div class="lg:col-span-2">
            <x-card>
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Basic Information -->
                    <div class="col-span-2">
                        <h3 class="mb-4 text-lg font-semibold">Basic Information</h3>
                    </div>

                    <div>
                        <x-input
                            label="Full Name *"
                            wire:model="name"
                            placeholder="Enter full name"
                            icon="o-user"
                            hint="Teacher's full name"
                            required
                            error="{{ $errors->first('name') }}"
                        />
                    </div>

                    <div>
                        <x-input
                            label="Email *"
                            wire:model="email"
                            placeholder="example@email.com"
                            icon="o-envelope"
                            type="email"
                            hint="This address will be used for login"
                            required
                            error="{{ $errors->first('email') }}"
                        />
                    </div>

                    <div>
                        <x-input
                            label="Phone"
                            wire:model="phone"
                            placeholder="+1 234 567 8901"
                            icon="o-phone"
                            error="{{ $errors->first('phone') }}"
                        />
                    </div>

                    <div>
                        <x-input
                            label="Specialization *"
                            wire:model="specialization"
                            placeholder="e.g. Mathematics, Computer Science"
                            icon="o-academic-cap"
                            required
                            error="{{ $errors->first('specialization') }}"
                        />
                    </div>

                    <div class="col-span-2">
                        <x-textarea
                            label="Biography"
                            wire:model="bio"
                            placeholder="Teacher's professional biography"
                            icon="o-document-text"
                            rows="4"
                            error="{{ $errors->first('bio') }}"
                        />
                    </div>

                    <!-- Account Settings -->
                    <div class="col-span-2 pt-5 mt-2 border-t">
                        <h3 class="mb-4 text-lg font-semibold">Account Settings</h3>
                    </div>

                    <div>
                        <x-select
                            label="Status *"
                            wire:model="status"
                            :options="[
                                ['label' => 'Active', 'value' => 'active'],
                                ['label' => 'Inactive', 'value' => 'inactive'],
                                ['label' => 'Suspended', 'value' => 'suspended']
                            ]"
                            option-label="label"
                            option-value="value"
                            icon="o-shield-check"
                            hint="Determines if the teacher can log in"
                            required
                            error="{{ $errors->first('status') }}"
                        />
                    </div>

                    <div>
                        <div class="flex items-center h-full pt-4">
                            <x-button
                                label="Last login: {{ $teacher->user?->last_login_at ? $teacher->user->last_login_at->format('m/d/Y H:i') : 'Never' }}"
                                icon="o-clock"
                                class="border-none bg-base-200"
                                disabled
                            />
                        </div>
                    </div>

                    <!-- Photo Upload -->
                    <div class="col-span-2 pt-5 mt-2 border-t">
                        <h3 class="mb-4 text-lg font-semibold">Profile Photo</h3>

                        <div class="flex flex-col items-center gap-4 md:flex-row">
                            <div class="avatar">
                                <div class="w-24 h-24 rounded-full">
                                    @if ($photo)
                                        <img src="{{ $photo->temporaryUrl() }}" alt="{{ $name }}">
                                    @elseif ($teacher->user)
                                        <img src="{{ $teacher->user->profile_photo_url }}" alt="{{ $name }}">
                                    @else
                                        <div class="flex items-center justify-center w-24 h-24 rounded-full bg-base-200">
                                            <x-icon name="o-user" class="w-12 h-12 text-base-content/30" />
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="flex-grow">
                                <x-file-upload
                                    wire:model="photo"
                                    label="Upload Profile Photo"
                                    hint="Max 1MB. JPG or PNG only."
                                    error="{{ $errors->first('photo') }}"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Sidebar - Additional information and history -->
        <div class="lg:col-span-1">
            <!-- Teacher Summary -->
            <x-card class="mb-6">
                <div class="flex flex-col items-center p-4 text-center">
                    <div class="mb-4 avatar">
                        <div class="w-24 h-24 rounded-full">
                            @if ($teacher->user)
                                <img src="{{ $teacher->user->profile_photo_url }}" alt="{{ $teacher->user->name }}">
                            @else
                                <div class="flex items-center justify-center w-24 h-24 rounded-full bg-base-200">
                                    <x-icon name="o-user" class="w-12 h-12 text-base-content/30" />
                                </div>
                            @endif
                        </div>
                    </div>
                    <h3 class="text-xl font-bold">{{ $teacher->user->name ?? 'Unknown' }}</h3>
                    <p class="mb-2 text-gray-500">{{ $teacher->user->email ?? 'No email' }}</p>

                    <div class="my-2 divider"></div>

                    <div class="w-full">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium">ID:</span>
                            <span class="text-sm">{{ $teacher->id }}</span>
                        </div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium">Created on:</span>
                            <span class="text-sm">{{ $teacher->created_at->format('m/d/Y') }}</span>
                        </div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium">Sessions:</span>
                            <span class="text-sm">{{ $teacher->sessions->count() }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium">Account:</span>
                            <x-badge
                                label="{{ match($teacher->user?->status ?? 'unknown') {
                                    'active' => 'Active',
                                    'inactive' => 'Inactive',
                                    'suspended' => 'Suspended',
                                    default => 'Unknown'
                                } }}"
                                color="{{ match($teacher->user?->status ?? 'unknown') {
                                    'active' => 'success',
                                    'inactive' => 'warning',
                                    'suspended' => 'error',
                                    default => 'secondary'
                                } }}"
                            />
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Activity History -->
            <x-card>
                <h3 class="mb-4 text-lg font-semibold">Recent Activity History</h3>

                <div class="overflow-auto max-h-96">
                    @forelse($activity_logs as $log)
                        <div class="flex items-start gap-2 pb-3 mb-4 border-b border-base-300 last:border-0">
                            <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0
                                {{ match($log->action) {
                                    'access' => 'bg-info/10 text-info',
                                    'create' => 'bg-success/10 text-success',
                                    'update' => 'bg-warning/10 text-warning',
                                    'delete' => 'bg-error/10 text-error',
                                    'email' => 'bg-primary/10 text-primary',
                                    default => 'bg-secondary/10 text-secondary'
                                } }}">
                                <x-icon name="{{ match($log->action) {
                                    'access' => 'o-eye',
                                    'create' => 'o-plus',
                                    'update' => 'o-pencil',
                                    'delete' => 'o-trash',
                                    'email' => 'o-envelope',
                                    default => 'o-document-text'
                                } }}" class="w-4 h-4" />
                            </div>

                            <div class="flex-grow">
                                <div class="text-sm font-medium">{{ $log->description }}</div>
                                <div class="flex justify-between text-xs text-gray-500">
                                    <span>{{ $log->created_at->diffForHumans() }}</span>
                                    <span>{{ $log->user ? 'By ' . $log->user->name : '' }}</span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="py-4 text-center text-gray-500">
                            <x-icon name="o-document-text" class="w-10 h-10 mx-auto mb-2" />
                            <p>No recent activity recorded</p>
                        </div>
                    @endforelse
                </div>
            </x-card>
        </div>
    </div>
</div>
