<?php

use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;
use Spatie\Permission\Models\Role;

new #[Title('Create Teacher')] class extends Component {
    use WithFileUploads;
    use Toast;

    // Form attributes
    public string $name = '';
    public string $email = '';
    public ?string $phone = '';
    public ?string $specialization = '';
    public ?string $bio = '';
    public string $status = 'active';

    // Password management
    public string $password = '';
    public string $password_confirmation = '';
    public bool $send_credentials = true;

    // Photo upload
    public $photo;

    // Component initialization
    public function mount(): void
    {
        // Log access to create page
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'access',
            'description' => 'Accessed teacher creation page',
            'loggable_type' => TeacherProfile::class,
            'loggable_id' => null,
            'ip_address' => request()->ip(),
        ]);
    }

    // Validation rules
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'specialization' => ['required', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:active,inactive'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
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
            'password.required' => 'Password is required',
            'password.confirmed' => 'Password confirmation does not match',
            'status.required' => 'Status is required',
            'status.in' => 'The selected status is not valid',
        ];
    }

    /**
     * Generate a random password
     */
    public function generatePassword(): void
    {
        $this->password = Str::password(12);
        $this->password_confirmation = $this->password;

        $this->dispatch('password-generated', password: $this->password);
    }

    /**
     * Create a new teacher
     */
    public function save(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            // Create new user
            $user = new User();
            $user->name = $this->name;
            $user->email = $this->email;
            $user->password = Hash::make($this->password);
            $user->status = $this->status;
            $user->save();

            // Process photo upload if present
            if ($this->photo) {
                $user->updateProfilePhoto($this->photo);
            }

            // Assign teacher role
            $user->assignRole('teacher');

            // Create teacher profile
            $teacher = new TeacherProfile();
            $teacher->user_id = $user->id;
            $teacher->phone = $this->phone;
            $teacher->specialization = $this->specialization;
            $teacher->bio = $this->bio;
            $teacher->save();

            // Log creation
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'description' => "Created new teacher: {$user->name}",
                'loggable_type' => TeacherProfile::class,
                'loggable_id' => $teacher->id,
                'ip_address' => request()->ip(),
                'additional_data' => [
                    'teacher_email' => $user->email,
                    'teacher_specialization' => $teacher->specialization,
                ]
            ]);

            // Send credentials if requested
            if ($this->send_credentials) {
                // Implementation would go here - could use Mail facade
                // Mail::to($user->email)->send(new TeacherWelcome($user, $this->password));

                ActivityLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'email',
                    'description' => "Sent welcome email with credentials to: {$user->email}",
                    'loggable_type' => User::class,
                    'loggable_id' => $user->id,
                    'ip_address' => request()->ip(),
                ]);
            }

            DB::commit();

            // Success notification using toast
            $this->success("Teacher {$user->name} has been successfully created.");

            // Redirect to teachers list
            $this->redirect(route('admin.teachers.index'));

        } catch (\Exception $e) {
            DB::rollBack();

            // Error notification using toast
            $this->error("An error occurred while creating the teacher: {$e->getMessage()}");
        }
    }

    /**
     * Cancel creation and return to list
     */
    public function cancel(): void
    {
        $this->redirect(route('admin.teachers.index'));
    }
};
?>

<div>
    <x-header title="Create New Teacher" separator back="{{ route('admin.teachers.index') }}">
        <x-slot:actions>
            <div class="flex gap-2">
                <x-button label="Cancel" icon="o-x-mark" wire:click="cancel" />
                <x-button label="Save" icon="o-check" wire:click="save" class="btn-primary" />
            </div>
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 mb-6 lg:grid-cols-3">
        <!-- Main section - Create form -->
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
                        <x-checkbox
                            label="Send login credentials by email"
                            wire:model="send_credentials"
                            hint="The teacher will receive an email with login information"
                            class="flex items-center h-full pt-8"
                        />
                    </div>

                    <!-- Password Section -->
                    <div class="col-span-2 pt-5 mt-2 border-t">
                        <h3 class="mb-4 text-lg font-semibold">Password</h3>
                    </div>

                    <div>
                        <x-input
                            label="Password *"
                            wire:model="password"
                            type="password"
                            icon="o-key"
                            hint="Minimum 8 characters"
                            error="{{ $errors->first('password') }}"
                        />
                    </div>

                    <div>
                        <x-input
                            label="Confirm Password *"
                            wire:model="password_confirmation"
                            type="password"
                            icon="o-key"
                            error="{{ $errors->first('password_confirmation') }}"
                        />
                    </div>

                    <div class="col-span-2">
                        <x-button
                            label="Generate Password"
                            icon="o-sparkles"
                            wire:click="generatePassword"
                            class="bg-base-200"
                        />
                    </div>

                    <div class="col-span-2" x-data="{ showPassword: false, password: '' }" x-on:password-generated.window="showPassword = true; password = $event.detail.password">
                        <div x-show="showPassword" x-transition class="p-4 rounded-lg bg-info/10">
                            <div class="flex items-start gap-3">
                                <x-icon name="o-information-circle" class="flex-shrink-0 w-6 h-6 mt-1 text-info" />
                                <div>
                                    <p class="font-semibold">Generated password:</p>
                                    <div class="flex items-center justify-between p-2 mt-1 bg-white rounded">
                                        <code x-text="password" class="font-mono"></code>
                                        <x-button
                                            icon="o-clipboard-document"
                                            x-on:click="navigator.clipboard.writeText(password); $dispatch('notify', {text: 'Password copied!', variant: 'success'})"
                                            size="xs"
                                            class="ml-2"
                                            tooltip="Copy"
                                        />
                                    </div>
                                </div>
                            </div>
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
                                    @else
                                        <div class="flex items-center justify-center w-24 h-24 rounded-full bg-base-200">
                                            <x-icon name="o-user" class="w-12 h-12 text-base-content/30" />
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="flex-grow">

                            </div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Sidebar - Help and information -->
        <div class="lg:col-span-1">
            <!-- Getting Started Card -->
            <x-card class="mb-6">
                <h3 class="mb-4 text-lg font-semibold">Getting Started</h3>

                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="flex items-center justify-center w-8 h-8 rounded-full shrink-0 bg-primary/10 text-primary">
                            <x-icon name="o-information-circle" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="font-medium">Creating a new teacher</p>
                            <p class="text-sm text-gray-600">Fill in the required information to create a new teacher account. The teacher will be assigned the 'teacher' role automatically.</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <div class="flex items-center justify-center w-8 h-8 rounded-full shrink-0 bg-info/10 text-info">
                            <x-icon name="o-envelope" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="font-medium">Welcome Email</p>
                            <p class="text-sm text-gray-600">If you check "Send login credentials by email", the teacher will receive an email with their username and password.</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <div class="flex items-center justify-center w-8 h-8 rounded-full shrink-0 bg-success/10 text-success">
                            <x-icon name="o-key" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="font-medium">Password Security</p>
                            <p class="text-sm text-gray-600">You can generate a secure password or create your own. Make sure it's at least 8 characters long.</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <div class="flex items-center justify-center w-8 h-8 rounded-full shrink-0 bg-warning/10 text-warning">
                            <x-icon name="o-shield-check" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="font-medium">Account Status</p>
                            <p class="text-sm text-gray-600">Setting status to "Active" allows immediate login. "Inactive" prevents login until manually activated.</p>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Teacher Permissions -->
            <x-card>
                <h3 class="mb-4 text-lg font-semibold">Teacher Permissions</h3>

                <div class="space-y-2">
                    <p class="mb-4 text-sm text-gray-600">Teachers will have access to the following features:</p>

                    <div class="flex items-center gap-2">
                        <x-icon name="o-check" class="w-5 h-5 text-success" />
                        <span>Manage their class sessions</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-icon name="o-check" class="w-5 h-5 text-success" />
                        <span>Create and grade exams</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-icon name="o-check" class="w-5 h-5 text-success" />
                        <span>View their timetable</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-icon name="o-check" class="w-5 h-5 text-success" />
                        <span>Communicate with students</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-icon name="o-check" class="w-5 h-5 text-success" />
                        <span>Submit attendance records</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-icon name="o-x-mark" class="w-5 h-5 text-error" />
                        <span>Access administrative functions</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-icon name="o-x-mark" class="w-5 h-5 text-error" />
                        <span>Modify system settings</span>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
