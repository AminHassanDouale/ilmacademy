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
    <!-- Mobile-first responsive page header -->
    <x-header title="Create New User" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('admin.users.index') }}"
                class="btn-ghost"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Responsive layout: stacked on mobile, side-by-side on desktop -->
    <div class="grid grid-cols-1 gap-4 sm:gap-6 lg:grid-cols-3">
        <!-- Main form section - full width on mobile, 2/3 on desktop -->
        <div class="order-2 lg:order-1 lg:col-span-2">
            <x-card title="User Information">
                <form wire:submit="save" class="space-y-4 sm:space-y-6">
                    <!-- Responsive form grid -->
                    <div class="grid grid-cols-1 gap-4 sm:gap-6 md:grid-cols-2">
                        <!-- Name -->
                        <div>
                            <x-input
                                label="Full Name"
                                wire:model.live="name"
                                placeholder="Enter user's full name"
                                required
                                class="w-full"
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
                                class="w-full"
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
                                class="w-full"
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
                                class="w-full"
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
                                class="w-full"
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
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md shadow-sm sm:text-base focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
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

                        <!-- Address - spans full width on all screen sizes -->
                        <div class="md:col-span-2">
                            <x-textarea
                                label="Address"
                                wire:model.live="address"
                                placeholder="Optional address"
                                rows="3"
                                class="w-full"
                            />
                            @error('address')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Responsive Roles Section -->
                    <div class="pt-4 border-t sm:pt-6">
                        <div class="mb-4">
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                User Roles *
                            </label>
                            <p class="mb-4 text-sm text-gray-500">
                                Select one or more roles for this user. Users can have multiple roles.
                            </p>
                        </div>

                        <!-- Responsive roles grid: 1 column on mobile, 2 on tablet+ -->
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                            @foreach($roleOptions as $role)
                                <div class="relative">
                                    <label class="flex items-start p-3 border-2 rounded-lg cursor-pointer transition-colors duration-200 hover:bg-gray-50 sm:p-4 {{ $this->isRoleSelected($role['id']) ? 'border-blue-500 bg-blue-50' : 'border-gray-200' }}">
                                        <input
                                            type="checkbox"
                                            wire:click="toggleRole('{{ $role['id'] }}')"
                                            {{ $this->isRoleSelected($role['id']) ? 'checked' : '' }}
                                            class="w-4 h-4 mt-1 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                        />
                                        <div class="flex-1 min-w-0 ml-3">
                                            <div class="text-sm font-medium text-gray-900 sm:text-base">{{ $role['name'] }}</div>
                                            <div class="text-xs text-gray-500 sm:text-sm">{{ $role['description'] }}</div>
                                        </div>
                                    </label>
                                </div>
                            @endforeach
                        </div>

                        @error('selectedRoles')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Responsive action buttons -->
                    <div class="flex flex-col gap-3 pt-4 sm:flex-row sm:justify-end sm:gap-2 sm:pt-6">
                        <x-button
                            label="Cancel"
                            link="{{ route('admin.users.index') }}"
                            class="order-2 w-full sm:order-1 sm:w-auto"
                        />
                        <x-button
                            label="Create User"
                            icon="o-user-plus"
                            type="submit"
                            color="primary"
                            class="order-1 w-full sm:order-2 sm:w-auto"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right sidebar - appears first on mobile, right side on desktop -->
        <div class="order-1 space-y-4 lg:order-2 sm:space-y-6">
            <!-- User Preview Card -->
            <x-card title="User Preview">
                <div class="p-3 rounded-lg sm:p-4 bg-base-200">
                    <!-- Responsive user info layout -->
                    <div class="flex items-center mb-3 sm:mb-4">
                        <div class="avatar">
                            <div class="w-12 h-12 rounded-full sm:w-16 sm:h-16">
                                <img src="https://ui-avatars.com/api/?name={{ urlencode($name ?: 'User Name') }}&color=7F9CF5&background=EBF4FF" alt="User Avatar" />
                            </div>
                        </div>
                        <div class="flex-1 min-w-0 ml-3 sm:ml-4">
                            <div class="text-base font-semibold truncate sm:text-lg">{{ $name ?: 'User Name' }}</div>
                            <div class="text-sm text-gray-500 truncate">{{ $email ?: 'user@example.com' }}</div>
                        </div>
                    </div>

                    <!-- User details with responsive layout -->
                    <div class="space-y-2 text-sm sm:space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <strong class="text-xs sm:text-sm">Status:</strong>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ match($status) {
                                'active' => 'bg-green-100 text-green-800',
                                'inactive' => 'bg-gray-100 text-gray-600',
                                'suspended' => 'bg-red-100 text-red-800',
                                default => 'bg-gray-100 text-gray-600'
                            } }}">
                                {{ ucfirst($status) }}
                            </span>
                        </div>

                        @if($phone)
                            <div class="text-xs sm:text-sm">
                                <strong>Phone:</strong>
                                <span class="break-all">{{ $phone }}</span>
                            </div>
                        @endif

                        @if($address)
                            <div class="text-xs sm:text-sm">
                                <strong>Address:</strong>
                                <span class="break-words">{{ $address }}</span>
                            </div>
                        @endif

                        <div>
                            <strong class="text-xs sm:text-sm">Roles:</strong>
                            @if(count($selectedRoles) > 0)
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($selectedRoles as $role)
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ match($role) {
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
                                <span class="text-xs text-gray-500 sm:text-sm">No roles selected</span>
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Collapsible Help Section on Mobile -->
            <div class="lg:hidden">
                <details class="group">
                    <summary class="flex items-center justify-between p-4 font-medium text-gray-900 border border-gray-200 rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100">
                        <span>Help & Information</span>
                        <svg class="w-5 h-5 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </summary>
                    <div class="p-4 mt-2 bg-white border border-gray-200 rounded-lg">
                        <div class="space-y-3 text-sm">
                            <div>
                                <div class="font-semibold">Password Requirements</div>
                                <p class="text-gray-600">Password must be at least 8 characters long and should contain a mix of letters, numbers, and special characters.</p>
                            </div>

                            <div>
                                <div class="font-semibold">User Roles</div>
                                <ul class="mt-2 space-y-1 text-gray-600">
                                    <li><strong>Admin:</strong> Full system access</li>
                                    <li><strong>Teacher:</strong> Manage classes and students</li>
                                    <li><strong>Parent:</strong> View children's progress</li>
                                    <li><strong>Student:</strong> Access learning materials</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </details>
            </div>

            <!-- Desktop Help Card (hidden on mobile) -->
            <x-card title="Help & Information" class="hidden lg:block">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Password Requirements</div>
                        <p class="text-gray-600">Password must be at least 8 characters long and should contain a mix of letters, numbers, and special characters.</p>
                    </div>

                    <div>
                        <div class="font-semibold">User Roles</div>
                        <ul class="mt-2 space-y-1 text-gray-600">
                            <li><strong>Admin:</strong> Full system access</li>
                            <li><strong>Teacher:</strong> Manage classes and students</li>
                            <li><strong>Parent:</strong> View children's progress</li>
                            <li><strong>Student:</strong> Access learning materials</li>
                        </ul>
                    </div>

                    <div>
                        <div class="font-semibold">User Status</div>
                        <ul class="mt-2 space-y-1 text-gray-600">
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

            <!-- Responsive Security Notice -->
            <x-card title="Security Notice" class="border-yellow-200 bg-yellow-50">
                <div class="space-y-2 text-sm">
                    <div class="flex items-start">
                        <x-icon name="o-exclamation-triangle" class="flex-shrink-0 w-4 h-4 mt-0.5 mr-2 text-yellow-600 sm:w-5 sm:h-5" />
                        <div class="min-w-0">
                            <div class="font-semibold text-yellow-800">Password Security</div>
                            <p class="text-yellow-700">Make sure to use a strong password. Consider using a password manager to generate secure passwords.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="flex-shrink-0 w-4 h-4 mt-0.5 mr-2 text-yellow-600 sm:w-5 sm:h-5" />
                        <div class="min-w-0">
                            <div class="font-semibold text-yellow-800">Role Assignment</div>
                            <p class="text-yellow-700">Only assign the minimum roles necessary for the user to perform their duties.</p>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Mobile Quick Actions (shown only on small screens) -->
            <div class="p-4 border border-blue-200 rounded-lg bg-blue-50 lg:hidden">
                <div class="flex items-center mb-2">
                    <x-icon name="o-light-bulb" class="w-5 h-5 mr-2 text-blue-600" />
                    <span class="font-semibold text-blue-800">Quick Tip</span>
                </div>
                <p class="text-sm text-blue-700">
                    Preview updates as you type! The user preview card shows how the user will appear in the system.
                </p>
            </div>
        </div>
    </div>

    <!-- Mobile-only floating action buttons -->
    <div class="fixed bottom-4 right-4 lg:hidden">
        <div class="flex flex-col gap-2">
            <button
                type="button"
                onclick="document.querySelector('form').dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}))"
                class="flex items-center justify-center text-white transition-colors duration-200 bg-blue-600 rounded-full shadow-lg w-14 h-14 hover:bg-blue-700"
                title="Create User"
            >
                <x-icon name="o-user-plus" class="w-6 h-6" />
            </button>
        </div>
    </div>

    <!-- Mobile form validation summary (shown when there are errors) -->
    @if($errors->any())
        <div class="fixed bottom-20 left-4 right-4 lg:hidden">
            <div class="p-3 border border-red-200 rounded-lg shadow-lg bg-red-50">
                <div class="flex items-center mb-2">
                    <x-icon name="o-exclamation-circle" class="w-5 h-5 mr-2 text-red-600" />
                    <span class="font-semibold text-red-800">Please fix the following errors:</span>
                </div>
                <ul class="text-sm text-red-700 list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
