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
