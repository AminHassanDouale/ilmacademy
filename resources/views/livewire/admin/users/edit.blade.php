<?php

use App\Models\User;
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

new #[Title('Edit User')] class extends Component {
    use Toast;

    // Model instance
    public User $user;

    // Form data
    public string $name = '';
    public string $email = '';
    public ?string $password = '';
    public ?string $password_confirmation = '';
    public string $phone = '';
    public string $address = '';
    public string $status = '';
    public array $selectedRoles = [];

    // Options
    protected array $validStatuses = ['active', 'inactive', 'suspended'];
    public array $roleOptions = [];

    // Original data for change tracking
    protected array $originalData = [];

    // Mount the component
    public function mount(User $user): void
    {
        $this->user = $user->load('roles');

        Log::info('User Edit Component Mounted', [
            'admin_user_id' => Auth::id(),
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'ip' => request()->ip()
        ]);

        // Load current user data into form
        $this->loadUserData();

        // Store original data for change tracking
        $this->storeOriginalData();

        // Load role options
        $this->loadRoleOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit page for user: {$user->name} ({$user->email})",
            User::class,
            $user->id,
            [
                'target_user_name' => $user->name,
                'target_user_email' => $user->email,
                'ip' => request()->ip()
            ]
        );
    }

    // Load user data into form
    protected function loadUserData(): void
    {
        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->phone = $this->user->phone ?? '';
        $this->address = $this->user->address ?? '';
        $this->status = $this->user->status;
        $this->selectedRoles = $this->user->roles ? $this->user->roles->pluck('name')->toArray() : [];

        Log::info('User Data Loaded', [
            'user_id' => $this->user->id,
            'form_data' => [
                'name' => $this->name,
                'email' => $this->email,
                'status' => $this->status,
                'roles' => $this->selectedRoles,
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
            'address' => $this->user->address ?? '',
            'status' => $this->user->status,
            'roles' => $this->user->roles ? $this->user->roles->pluck('name')->toArray() : [],
        ];
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
        Log::info('User Update Started', [
            'admin_user_id' => Auth::id(),
            'target_user_id' => $this->user->id,
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

            $validationRules = [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,' . $this->user->id,
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:500',
                'status' => 'required|string|in:' . implode(',', $this->validStatuses),
                'selectedRoles' => 'required|array|min:1',
                'selectedRoles.*' => 'string|exists:roles,name',
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
                'selectedRoles.required' => 'Please select at least one role.',
                'selectedRoles.min' => 'Please select at least one role.',
                'selectedRoles.*.exists' => 'One or more selected roles are invalid.',
            ]);

            Log::info('Validation Passed', ['validated_data' => Arr::except($validated, ['password'])]);

            // Check if admin is trying to change their own status or remove admin role
            if ($this->user->id === Auth::id()) {
                if ($validated['status'] !== 'active') {
                    $this->addError('status', 'You cannot change your own status.');
                    return;
                }
                if (!in_array('admin', $validated['selectedRoles'])) {
                    $this->addError('selectedRoles', 'You cannot remove the admin role from your own account.');
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

            // Track changes for activity log
            $changes = $this->getChanges($validated);

            Log::info('Prepared User Data', [
                'user_data' => Arr::except($userData, ['password']),
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

            // Update roles
            Log::debug('Updating User Roles');
            $this->user->syncRoles($validated['selectedRoles']);
            Log::info('Roles Updated Successfully', [
                'user_id' => $this->user->id,
                'new_roles' => $validated['selectedRoles']
            ]);

            // Log activity with changes
            if (!empty($changes)) {
                $changeDescription = "Updated user: {$this->user->name} ({$this->user->email}). Changes: " . implode(', ', $changes);

                ActivityLog::log(
                    Auth::id(),
                    'update',
                    $changeDescription,
                    User::class,
                    $this->user->id,
                    [
                        'changes' => $changes,
                        'original_data' => $this->originalData,
                        'new_data' => Arr::except($validated, ['password', 'password_confirmation']),
                        'target_user_name' => $this->user->name,
                        'target_user_email' => $this->user->email,
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
            $this->success("User '{$this->user->name}' has been successfully updated.");
            Log::info('Success Toast Displayed');

            // Redirect to user show page
            Log::info('Redirecting to User Show Page', [
                'user_id' => $this->user->id,
                'route' => 'admin.users.show'
            ]);

            $this->redirect(route('admin.users.show', $this->user->id));

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
            Log::error('User Update Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'user_id' => $this->user->id,
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
            'selectedRoles' => 'Roles',
        ];

        foreach ($newData as $field => $newValue) {
            $originalField = $field === 'selectedRoles' ? 'roles' : $field;
            $originalValue = $this->originalData[$originalField] ?? null;

            if ($field === 'selectedRoles') {
                // Compare arrays for roles
                $originalRoles = is_array($originalValue) ? $originalValue : [];
                $newRoles = is_array($newValue) ? $newValue : [];

                sort($originalRoles);
                sort($newRoles);

                if ($originalRoles != $newRoles) {
                    $changes[] = "Roles from [" . implode(', ', $originalRoles) . "] to [" . implode(', ', $newRoles) . "]";
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
    <x-header title="Edit User: {{ $user->name }}" separator>
        <x-slot:actions>
            <x-button
                label="View User"
                icon="o-eye"
                link="{{ route('admin.users.show', $user->id) }}"
                class="btn-ghost"
            />
            <x-button
                label="Back to List"
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
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 {{ $user->id === Auth::id() && $status !== 'active' ? 'border-red-300' : '' }}"
                                required
                                {{ $user->id === Auth::id() ? 'disabled' : '' }}
                            >
                                @foreach($this->statusOptions as $value => $label)
                                    <option value="{{ $value }}" {{ $value == $status ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @if($user->id === Auth::id())
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

                    <!-- Roles Section -->
                    <div class="pt-6 border-t">
                        <div class="mb-4">
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                User Roles *
                            </label>
                            <p class="mb-4 text-sm text-gray-500">
                                Select one or more roles for this user. Users can have multiple roles.
                            </p>
                            @if($user->id === Auth::id())
                                <p class="mb-4 text-sm text-orange-600">
                                    <x-icon name="o-exclamation-triangle" class="inline w-4 h-4 mr-1" />
                                    You cannot remove the admin role from your own account.
                                </p>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            @foreach($roleOptions as $role)
                                <div class="relative">
                                    <label class="flex items-start p-4 border-2 rounded-lg cursor-pointer hover:bg-gray-50 {{ $this->isRoleSelected($role['id']) ? 'border-blue-500 bg-blue-50' : 'border-gray-200' }} {{ $user->id === Auth::id() && $role['id'] === 'admin' ? 'opacity-75' : '' }}">
                                        <input
                                            type="checkbox"
                                            wire:click="toggleRole('{{ $role['id'] }}')"
                                            {{ $this->isRoleSelected($role['id']) ? 'checked' : '' }}
                                            {{ $user->id === Auth::id() && $role['id'] === 'admin' ? 'disabled' : '' }}
                                            class="w-4 h-4 mt-1 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                        />
                                        <div class="ml-3">
                                            <div class="font-medium text-gray-900">
                                                {{ $role['name'] }}
                                                @if($user->id === Auth::id() && $role['id'] === 'admin')
                                                    <span class="text-xs text-orange-600">(Cannot be removed)</span>
                                                @endif
                                            </div>
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
                            link="{{ route('admin.users.show', $user->id) }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Update User"
                            icon="o-check"
                            type="submit"
                            color="primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Info and Preview -->
        <div class="space-y-6">
            <!-- Current User Info -->
            <x-card title="Current User">
                <div class="flex items-center mb-4 space-x-4">
                    <div class="avatar">
                        <div class="w-16 h-16 rounded-full">
                            <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}" />
                        </div>
                    </div>
                    <div>
                        <div class="text-lg font-semibold">{{ $user->name }}</div>
                        <div class="text-sm text-gray-500">{{ $user->email }}</div>
                        <div class="text-xs text-gray-400">ID: {{ $user->id }}</div>
                    </div>
                </div>

                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Current Status</div>
                        <div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($user->status) {
                                'active' => 'bg-green-100 text-green-800',
                                'inactive' => 'bg-gray-100 text-gray-600',
                                'suspended' => 'bg-red-100 text-red-800',
                                default => 'bg-gray-100 text-gray-600'
                            } }}">
                                {{ ucfirst($user->status) }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Current Roles</div>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($user->roles as $role)
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ match($role->name) {
                                    'admin' => 'bg-purple-100 text-purple-800',
                                    'teacher' => 'bg-blue-100 text-blue-800',
                                    'parent' => 'bg-green-100 text-green-800',
                                    'student' => 'bg-orange-100 text-orange-800',
                                    default => 'bg-gray-100 text-gray-600'
                                } }}">
                                    {{ ucfirst($role->name) }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $user->created_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $user->updated_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>

                    @if($user->last_login_at)
                        <div>
                            <div class="font-medium text-gray-500">Last Login</div>
                            <div>{{ $user->last_login_at->format('M d, Y \a\t g:i A') }}</div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- User Preview Card -->
            <x-card title="Updated Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="flex items-center mb-4">
                        <div class="avatar">
                            <div class="w-16 h-16 rounded-full">
                                <img src="https://ui-avatars.com/api/?name={{ urlencode($name ?: $user->name) }}&color=7F9CF5&background=EBF4FF" alt="User Avatar" />
                            </div>
                        </div>
                        <div class="ml-4">
                            <div class="text-lg font-semibold">{{ $name ?: $user->name }}</div>
                            <div class="text-sm text-gray-500">{{ $email ?: $user->email }}</div>
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

                        @if($password)
                            <div class="text-orange-600">
                                <x-icon name="o-key" class="inline w-4 h-4 mr-1" />
                                <strong>Password will be updated</strong>
                            </div>
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
                                <span class="text-red-500">No roles selected</span>
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
                        <div class="font-semibold">Role Changes</div>
                        <p class="text-gray-600">Users must have at least one role. Changing roles will immediately affect the user's permissions throughout the system.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Status Changes</div>
                        <ul class="mt-2 space-y-1 text-gray-600">
                            <li><strong>Active:</strong> User can log in and use the system</li>
                            <li><strong>Inactive:</strong> User account is disabled</li>
                            <li><strong>Suspended:</strong> User is temporarily blocked</li>
                        </ul>
                    </div>

                    <div>
                        <div class="font-semibold">Self-Edit Restrictions</div>
                        <p class="text-gray-600">You cannot change your own status or remove the admin role from your own account for security reasons.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Changes Tracking</div>
                        <p class="text-gray-600">All changes to user accounts are logged for audit purposes. You can view the activity log on the user details page.</p>
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
                            <p class="text-yellow-700">If changing the password, ensure it meets security requirements. The user will need to use the new password on their next login.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Role Assignment</div>
                            <p class="text-yellow-700">Be careful when changing user roles. This immediately affects what the user can access in the system.</p>
                        </div>
                    </div>

                    @if($user->id === Auth::id())
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
