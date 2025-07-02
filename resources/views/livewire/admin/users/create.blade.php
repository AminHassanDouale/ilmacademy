<?php

use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create User')] class extends Component {
    use Toast;

    // Form data
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $phone = '';
    public string $address = '';
    public string $status = 'active';
    public array $selectedRoles = [];

    // Options
    protected array $validStatuses = ['active', 'inactive', 'suspended'];
    public array $roleOptions = [];

    // Mount the component
    public function mount(): void
    {
        Log::info('User Create Component Mounted', [
            'user_id' => Auth::id(),
            'ip' => request()->ip()
        ]);

        $this->loadRoleOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed create user page',
            User::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Load role options
    protected function loadRoleOptions(): void
    {
        try {
            $roles = \Spatie\Permission\Models\Role::orderBy('name')->get();
            $this->roleOptions = $roles->map(fn($role) => [
                'id' => $role->name,
                'name' => ucfirst($role->name),
                'description' => $this->getRoleDescription($role->name)
            ])->toArray();

            Log::info('Role Options Loaded', [
                'roles_count' => count($this->roleOptions),
                'roles' => array_column($this->roleOptions, 'name')
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to Load Role Options', [
                'error' => $e->getMessage()
            ]);

            // Fallback role options
            $this->roleOptions = [
                ['id' => 'admin', 'name' => 'Admin', 'description' => 'Full system access'],
                ['id' => 'teacher', 'name' => 'Teacher', 'description' => 'Manage classes and students'],
                ['id' => 'parent', 'name' => 'Parent', 'description' => 'View children\'s progress'],
                ['id' => 'student', 'name' => 'Student', 'description' => 'Access learning materials'],
            ];
        }
    }

    // Get role description
    protected function getRoleDescription(string $role): string
    {
        return match($role) {
            'admin' => 'Full system access and user management',
            'teacher' => 'Manage classes, students, and curriculum',
            'parent' => 'View children\'s progress and communicate with teachers',
            'student' => 'Access learning materials and submit assignments',
            default => 'User role'
        };
    }

    // Save the user
    public function save(): void
    {
        Log::info('User Create Started', [
            'user_id' => Auth::id(),
            'form_data' => [
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'status' => $this->status,
                'selectedRoles' => $this->selectedRoles,
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
                'selectedRoles' => 'required|array|min:1',
                'selectedRoles.*' => 'string|exists:roles,name',
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
                'selectedRoles.required' => 'Please select at least one role.',
                'selectedRoles.min' => 'Please select at least one role.',
                'selectedRoles.*.exists' => 'One or more selected roles are invalid.',
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

            Log::info('Prepared User Data', ['user_data' => \Illuminate\Support\Arr::except($userData, ['password'])]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Create user
            Log::debug('Creating User Record');
            $user = User::create($userData);
            Log::info('User Created Successfully', [
                'user_id' => $user->id,
                'user_email' => $user->email
            ]);

            // Assign roles
            Log::debug('Assigning Roles');
            $user->assignRole($validated['selectedRoles']);
            Log::info('Roles Assigned Successfully', [
                'user_id' => $user->id,
                'roles' => $validated['selectedRoles']
            ]);

            // Log activity
            Log::debug('Logging Activity');
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created user: {$validated['name']} ({$validated['email']}) with roles: " . implode(', ', $validated['selectedRoles']),
                User::class,
                $user->id,
                [
                    'user_name' => $validated['name'],
                    'user_email' => $validated['email'],
                    'assigned_roles' => $validated['selectedRoles'],
                    'status' => $validated['status']
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Show success toast
            $this->success("User '{$validated['name']}' has been successfully created.");
            Log::info('Success Toast Displayed');

            // Redirect to user show page
            Log::info('Redirecting to User Show Page', [
                'user_id' => $user->id,
                'route' => 'admin.users.show'
            ]);

            $this->redirect(route('admin.users.show', $user->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'name' => $this->name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'status' => $this->status,
                    'selectedRoles' => $this->selectedRoles,
                ]
            ]);

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User Creation Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'form_data' => [
                    'name' => $this->name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'status' => $this->status,
                    'selectedRoles' => $this->selectedRoles,
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

    // Toggle role selection
    public function toggleRole(string $role): void
    {
        if (in_array($role, $this->selectedRoles)) {
            $this->selectedRoles = array_values(array_filter($this->selectedRoles, fn($r) => $r !== $role));
        } else {
            $this->selectedRoles[] = $role;
        }

        Log::debug('Role Toggled', [
            'role' => $role,
            'selected_roles' => $this->selectedRoles
        ]);
    }

    // Check if role is selected
    public function isRoleSelected(string $role): bool
    {
        return in_array($role, $this->selectedRoles);
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
    <x-header title="Create New User" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('admin.users.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="User Information">
                <form wire:submit="save" class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Name -->
                        <div>
                            <x-input
                                label="Full Name"
                                wire:model.live="name"
                                placeholder="Enter user's full name"
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
                                placeholder="user@example.com"
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

                    <!-- Roles Section -->
                    <div class="pt-6 border-t">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                User Roles *
                            </label>
                            <p class="text-sm text-gray-500 mb-4">
                                Select one or more roles for this user. Users can have multiple roles.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            @foreach($roleOptions as $role)
                                <div class="relative">
                                    <label class="flex items-start p-4 border-2 rounded-lg cursor-pointer hover:bg-gray-50 {{ $this->isRoleSelected($role['id']) ? 'border-blue-500 bg-blue-50' : 'border-gray-200' }}">
                                        <input
                                            type="checkbox"
                                            wire:click="toggleRole('{{ $role['id'] }}')"
                                            {{ $this->isRoleSelected($role['id']) ? 'checked' : '' }}
                                            class="w-4 h-4 mt-1 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                        />
                                        <div class="ml-3">
                                            <div class="font-medium text-gray-900">{{ $role['name'] }}</div>
                                            <div class="text-sm text-gray-500">{{ $role['description'] }}</div>
                                        </div>
                                    </label>
                                </div>
                            @endforeach
                        </div>

                        @error('selectedRoles')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end pt-6">
                        <x-button
                            label="Cancel"
                            link="{{ route('admin.users.index') }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Create User"
                            icon="o-user-plus"
                            type="submit"
                            color="primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Preview and Help -->
        <div class="space-y-6">
            <!-- User Preview Card -->
            <x-card title="User Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="flex items-center mb-4">
                        <div class="avatar">
                            <div class="w-16 h-16 rounded-full">
                                <img src="https://ui-avatars.com/api/?name={{ urlencode($name ?: 'User Name') }}&color=7F9CF5&background=EBF4FF" alt="User Avatar" />
                            </div>
                        </div>
                        <div class="ml-4">
                            <div class="font-semibold text-lg">{{ $name ?: 'User Name' }}</div>
                            <div class="text-sm text-gray-500">{{ $email ?: 'user@example.com' }}</div>
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

                        @if($phone)
                            <div><strong>Phone:</strong> {{ $phone }}</div>
                        @endif

                        @if($address)
                            <div><strong>Address:</strong> {{ $address }}</div>
                        @endif

                        <div>
                            <strong>Roles:</strong>
                            @if(count($selectedRoles) > 0)
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($selectedRoles as $role)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ match($role) {
                                            'admin' => 'bg-purple-100 text-purple-800',
                                            'teacher' => 'bg-blue-100 text-blue-800',
                                            'parent' => 'bg-green-100 text-green-800',
                                            'student' => 'bg-orange-100 text-orange-800',
                                            default => 'bg-gray-100 text-gray-600'
                                        } }}">
                                            {{ ucfirst($role) }}
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-gray-500">No roles selected</span>
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Password Requirements</div>
                        <p class="text-gray-600">Password must be at least 8 characters long and should contain a mix of letters, numbers, and special characters.</p>
                    </div>

                    <div>
                        <div class="font-semibold">User Roles</div>
                        <ul class="text-gray-600 space-y-1 mt-2">
                            <li><strong>Admin:</strong> Full system access</li>
                            <li><strong>Teacher:</strong> Manage classes and students</li>
                            <li><strong>Parent:</strong> View children's progress</li>
                            <li><strong>Student:</strong> Access learning materials</li>
                        </ul>
                    </div>

                    <div>
                        <div class="font-semibold">User Status</div>
                        <ul class="text-gray-600 space-y-1 mt-2">
                            <li><strong>Active:</strong> User can log in and use the system</li>
                            <li><strong>Inactive:</strong> User account is disabled</li>
                            <li><strong>Suspended:</strong> User is temporarily blocked</li>
                        </ul>
                    </div>

                    <div>
                        <div class="font-semibold">Email Verification</div>
                        <p class="text-gray-600">Users created by administrators are automatically verified. They will receive a welcome email with their login credentials.</p>
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
                            <p class="text-yellow-700">Make sure to use a strong password. Consider using a password manager to generate secure passwords.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Role Assignment</div>
                            <p class="text-yellow-700">Only assign the minimum roles necessary for the user to perform their duties.</p>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
