<?php

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Role')] class extends Component {
    use Toast;

    // Model instance
    public Role $role;

    // Form data
    public string $name = '';
    public string $guardName = '';
    public array $selectedPermissions = [];

    // Options
    public array $permissionOptions = [];
    public array $permissionGroups = [];

    // Original data for change tracking
    protected array $originalData = [];

    // Mount the component
    public function mount(Role $role): void
    {
        $this->role = $role->load('permissions');

        Log::info('Role Edit Component Mounted', [
            'admin_user_id' => Auth::id(),
            'role_id' => $role->id,
            'role_name' => $role->name,
            'ip' => request()->ip()
        ]);

        // Load current role data into form
        $this->loadRoleData();

        // Store original data for change tracking
        $this->storeOriginalData();

        // Load permission options
        $this->loadPermissionOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit page for role: {$role->name}",
            Role::class,
            $role->id,
            [
                'role_name' => $role->name,
                'ip' => request()->ip()
            ]
        );
    }

    // Load role data into form
    protected function loadRoleData(): void
    {
        $this->name = $this->role->name;
        $this->guardName = $this->role->guard_name;
        $this->selectedPermissions = $this->role->permissions ? $this->role->permissions->pluck('id')->toArray() : [];

        Log::info('Role Data Loaded', [
            'role_id' => $this->role->id,
            'form_data' => [
                'name' => $this->name,
                'guardName' => $this->guardName,
                'permissions_count' => count($this->selectedPermissions),
            ]
        ]);
    }

    // Store original data for change tracking
    protected function storeOriginalData(): void
    {
        $this->originalData = [
            'name' => $this->role->name,
            'guard_name' => $this->role->guard_name,
            'permissions' => $this->role->permissions ? $this->role->permissions->pluck('id')->toArray() : [],
        ];
    }

    // Load permission options
    protected function loadPermissionOptions(): void
    {
        try {
            $permissions = Permission::orderBy('name')->get();

            // Group permissions by prefix (e.g., 'users.create', 'users.view' -> 'users')
            $grouped = [];
            foreach ($permissions as $permission) {
                $parts = explode('.', $permission->name);
                $group = count($parts) > 1 ? $parts[0] : 'general';

                if (!isset($grouped[$group])) {
                    $grouped[$group] = [];
                }

                $grouped[$group][] = [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $this->formatPermissionName($permission->name),
                ];
            }

            $this->permissionGroups = $grouped;
            $this->permissionOptions = $permissions->map(fn($permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'display_name' => $this->formatPermissionName($permission->name),
            ])->toArray();

            Log::info('Permission Options Loaded', [
                'permissions_count' => count($this->permissionOptions),
                'groups_count' => count($this->permissionGroups),
                'groups' => array_keys($this->permissionGroups)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to Load Permission Options', [
                'error' => $e->getMessage()
            ]);

            $this->permissionOptions = [];
            $this->permissionGroups = [];
        }
    }

    // Format permission name for display
    protected function formatPermissionName(string $permissionName): string
    {
        // Convert 'users.create' to 'Create Users'
        $parts = explode('.', $permissionName);
        if (count($parts) === 2) {
            $action = ucfirst($parts[1]);
            $resource = ucfirst($parts[0]);
            return "{$action} {$resource}";
        }

        return ucfirst(str_replace(['.', '_', '-'], ' ', $permissionName));
    }

    // Save the role
    public function save(): void
    {
        Log::info('Role Update Started', [
            'admin_user_id' => Auth::id(),
            'role_id' => $this->role->id,
            'form_data' => [
                'name' => $this->name,
                'guardName' => $this->guardName,
                'selectedPermissions' => $this->selectedPermissions,
            ]
        ]);

        try {
            // Check if it's a system role
            $systemRoles = ['admin', 'teacher', 'parent', 'student'];
            $isSystemRole = in_array($this->role->name, $systemRoles);

            // Validate form data
            Log::debug('Starting Validation');

            $validationRules = [
                'guardName' => 'required|string|max:255',
                'selectedPermissions' => 'array',
                'selectedPermissions.*' => 'integer|exists:permissions,id',
            ];

            // Only validate name if it's not a system role
            if (!$isSystemRole) {
                $validationRules['name'] = 'required|string|max:255|unique:roles,name,' . $this->role->id;
            }

            $validated = $this->validate($validationRules, [
                'name.required' => 'Please enter a role name.',
                'name.max' => 'Role name must not exceed 255 characters.',
                'name.unique' => 'This role name already exists.',
                'guardName.required' => 'Please enter a guard name.',
                'guardName.max' => 'Guard name must not exceed 255 characters.',
                'selectedPermissions.*.exists' => 'One or more selected permissions are invalid.',
            ]);

            Log::info('Validation Passed', ['validated_data' => $validated]);

            // Track changes for activity log
            $changes = $this->getChanges($validated, $isSystemRole);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Update role (only name if not system role)
            $updateData = ['guard_name' => $validated['guardName']];

            if (!$isSystemRole) {
                $roleName = strtolower(trim($validated['name']));
                $roleName = preg_replace('/[^a-z0-9_-]/', '_', $roleName);
                $updateData['name'] = $roleName;
            }

            Log::debug('Updating Role Record');
            $this->role->update($updateData);

            Log::info('Role Updated Successfully', [
                'role_id' => $this->role->id,
                'role_name' => $this->role->name
            ]);

            // Update permissions
            Log::debug('Updating Role Permissions');
            if (!empty($validated['selectedPermissions'])) {
                $permissions = Permission::whereIn('id', $validated['selectedPermissions'])->get();
                $this->role->syncPermissions($permissions);
            } else {
                $this->role->syncPermissions([]);
            }

            Log::info('Permissions Updated Successfully', [
                'role_id' => $this->role->id,
                'permissions_count' => count($validated['selectedPermissions']),
            ]);

            // Log activity with changes
            if (!empty($changes)) {
                $changeDescription = "Updated role: {$this->role->name}. Changes: " . implode(', ', $changes);

                ActivityLog::log(
                    Auth::id(),
                    'update',
                    $changeDescription,
                    Role::class,
                    $this->role->id,
                    [
                        'changes' => $changes,
                        'original_data' => $this->originalData,
                        'new_data' => $validated,
                        'role_name' => $this->role->name,
                        'is_system_role' => $isSystemRole,
                    ]
                );
            }

            DB::commit();
            Log::info('Database Transaction Committed');

            // Update original data for future comparisons
            $this->storeOriginalData();

            // Show success toast
            $this->success("Role '{$this->role->name}' has been successfully updated.");
            Log::info('Success Toast Displayed');

            // Redirect to roles index page
            Log::info('Redirecting to Roles Index Page', [
                'role_id' => $this->role->id,
                'route' => 'admin.roles.index'
            ]);

            $this->redirect(route('admin.roles.index'));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'name' => $this->name,
                    'guardName' => $this->guardName,
                    'selectedPermissions' => $this->selectedPermissions,
                ]
            ]);

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Role Update Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'role_id' => $this->role->id,
                'form_data' => [
                    'name' => $this->name,
                    'guardName' => $this->guardName,
                    'selectedPermissions' => $this->selectedPermissions,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get changes between original and new data
    protected function getChanges(array $newData, bool $isSystemRole): array
    {
        $changes = [];

        // Check name change (only for non-system roles)
        if (!$isSystemRole && isset($newData['name'])) {
            $newName = strtolower(trim($newData['name']));
            $newName = preg_replace('/[^a-z0-9_-]/', '_', $newName);

            if ($this->originalData['name'] !== $newName) {
                $changes[] = "Name from '{$this->originalData['name']}' to '{$newName}'";
            }
        }

        // Check guard name change
        if ($this->originalData['guard_name'] !== $newData['guardName']) {
            $changes[] = "Guard from '{$this->originalData['guard_name']}' to '{$newData['guardName']}'";
        }

        // Check permissions change
        $originalPermissions = $this->originalData['permissions'];
        $newPermissions = $newData['selectedPermissions'] ?? [];

        sort($originalPermissions);
        sort($newPermissions);

        if ($originalPermissions !== $newPermissions) {
            $changes[] = "Permissions updated (" . count($newPermissions) . " permissions assigned)";
        }

        return $changes;
    }

    // Toggle permission selection
    public function togglePermission(int $permissionId): void
    {
        if (in_array($permissionId, $this->selectedPermissions)) {
            $this->selectedPermissions = array_values(array_filter($this->selectedPermissions, fn($id) => $id !== $permissionId));
        } else {
            $this->selectedPermissions[] = $permissionId;
        }

        Log::debug('Permission Toggled', [
            'permission_id' => $permissionId,
            'selected_permissions' => $this->selectedPermissions
        ]);
    }

    // Toggle all permissions in a group
    public function toggleGroupPermissions(string $group): void
    {
        if (!isset($this->permissionGroups[$group])) {
            return;
        }

        $groupPermissionIds = array_column($this->permissionGroups[$group], 'id');
        $allSelected = !array_diff($groupPermissionIds, $this->selectedPermissions);

        if ($allSelected) {
            // Deselect all in group
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, $groupPermissionIds));
        } else {
            // Select all in group
            $this->selectedPermissions = array_values(array_unique(array_merge($this->selectedPermissions, $groupPermissionIds)));
        }

        Log::debug('Group Permissions Toggled', [
            'group' => $group,
            'action' => $allSelected ? 'deselected' : 'selected',
            'selected_permissions' => $this->selectedPermissions
        ]);
    }

    // Check if permission is selected
    public function isPermissionSelected(int $permissionId): bool
    {
        return in_array($permissionId, $this->selectedPermissions);
    }

    // Check if all permissions in group are selected
    public function isGroupSelected(string $group): bool
    {
        if (!isset($this->permissionGroups[$group])) {
            return false;
        }

        $groupPermissionIds = array_column($this->permissionGroups[$group], 'id');
        return !array_diff($groupPermissionIds, $this->selectedPermissions);
    }

    // Check if some permissions in group are selected
    public function isGroupPartiallySelected(string $group): bool
    {
        if (!isset($this->permissionGroups[$group])) {
            return false;
        }

        $groupPermissionIds = array_column($this->permissionGroups[$group], 'id');
        $intersection = array_intersect($groupPermissionIds, $this->selectedPermissions);

        return count($intersection) > 0 && count($intersection) < count($groupPermissionIds);
    }

    // Check if role is system role
    public function isSystemRole(): bool
    {
        return in_array($this->role->name, ['admin', 'teacher', 'parent', 'student']);
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
    <x-header title="Edit Role: {{ ucfirst($role->name) }}" separator>
        <x-slot:actions>
            <x-button
                label="Back to List"
                icon="o-arrow-left"
                link="{{ route('admin.roles.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Role Information">
                <form wire:submit="save" class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Role Name -->
                        <div>
                            <x-input
                                label="Role Name"
                                wire:model.live="name"
                                placeholder="Enter role name"
                                required
                                :readonly="$this->isSystemRole()"
                                help-text="{{ $this->isSystemRole() ? 'System role name cannot be changed' : 'Will be converted to lowercase with underscores' }}"
                            />
                            @if($this->isSystemRole())
                                <p class="mt-1 text-xs text-orange-600">
                                    <x-icon name="o-lock-closed" class="inline w-3 h-3 mr-1" />
                                    This is a system role and its name cannot be modified.
                                </p>
                            @endif
                            @error('name')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Guard Name -->
                        <div>
                            <x-input
                                label="Guard Name"
                                wire:model.live="guardName"
                                placeholder="web"
                                required
                                help-text="Usually 'web' for web applications"
                            />
                            @error('guardName')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Current Role Info -->
                    <div class="p-4 rounded-lg bg-gray-50">
                        <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                            <div>
                                <div class="font-medium text-gray-500">Role ID</div>
                                <div class="font-mono">{{ $role->id }}</div>
                            </div>
                            <div>
                                <div class="font-medium text-gray-500">Users Count</div>
                                <div class="font-semibold">{{ $role->users()->count() }}</div>
                            </div>
                            <div>
                                <div class="font-medium text-gray-500">Created</div>
                                <div>{{ $role->created_at->format('M d, Y') }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Permissions Section -->
                    <div class="pt-6 border-t">
                        <div class="mb-6">
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Permissions
                            </label>
                            <p class="mb-4 text-sm text-gray-500">
                                Select the permissions that users with this role should have. You can select individual permissions or entire groups.
                            </p>
                        </div>

                        @if(count($permissionGroups) > 0)
                            <div class="space-y-6">
                                @foreach($permissionGroups as $groupName => $groupPermissions)
                                    <div class="p-4 border rounded-lg">
                                        <div class="flex items-center justify-between mb-3">
                                            <h3 class="text-lg font-semibold text-gray-900 capitalize">
                                                {{ str_replace('_', ' ', $groupName) }} Permissions
                                            </h3>
                                            <div class="flex items-center space-x-2">
                                                <span class="text-sm text-gray-500">
                                                    {{ count(array_intersect(array_column($groupPermissions, 'id'), $selectedPermissions)) }}/{{ count($groupPermissions) }} selected
                                                </span>
                                                <button
                                                    type="button"
                                                    wire:click="toggleGroupPermissions('{{ $groupName }}')"
                                                    class="px-3 py-1 text-xs font-medium rounded-md {{ $this->isGroupSelected($groupName) ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-blue-100 text-blue-700 hover:bg-blue-200' }}"
                                                >
                                                    {{ $this->isGroupSelected($groupName) ? 'Deselect All' : 'Select All' }}
                                                </button>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                                            @foreach($groupPermissions as $permission)
                                                <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50 {{ $this->isPermissionSelected($permission['id']) ? 'border-blue-500 bg-blue-50' : 'border-gray-200' }}">
                                                    <input
                                                        type="checkbox"
                                                        wire:click="togglePermission({{ $permission['id'] }})"
                                                        {{ $this->isPermissionSelected($permission['id']) ? 'checked' : '' }}
                                                        class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                                    />
                                                    <div class="ml-3">
                                                        <div class="text-sm font-medium text-gray-900">{{ $permission['display_name'] }}</div>
                                                        <div class="text-xs text-gray-500">{{ $permission['name'] }}</div>
                                                    </div>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="py-8 text-center">
                                <x-icon name="o-key" class="w-12 h-12 mx-auto mb-4 text-gray-300" />
                                <div class="text-sm text-gray-500">No permissions available</div>
                                <p class="mt-1 text-xs text-gray-400">Create some permissions first to assign them to roles</p>
                            </div>
                        @endif

                        @error('selectedPermissions')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end pt-6">
                        <x-button
                            label="Cancel"
                            link="{{ route('admin.roles.index') }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Update Role"
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
            <!-- Current Role Info -->
            <x-card title="Current Role">
                <div class="space-y-4">
                    <div class="flex items-center space-x-3">
                        <div class="p-2 rounded-full {{ match($role->name) {
                            'admin' => 'bg-purple-100',
                            'teacher' => 'bg-blue-100',
                            'parent' => 'bg-green-100',
                            'student' => 'bg-orange-100',
                            default => 'bg-gray-100'
                        } }}">
                            <x-icon name="o-shield-check" class="w-6 h-6 {{ match($role->name) {
                                'admin' => 'text-purple-600',
                                'teacher' => 'text-blue-600',
                                'parent' => 'text-green-600',
                                'student' => 'text-orange-600',
                                default => 'text-gray-600'
                            } }}" />
                        </div>
                        <div>
                            <div class="text-lg font-semibold">{{ ucfirst($role->name) }}</div>
                            <div class="text-sm text-gray-500">{{ $role->guard_name }} guard</div>
                        </div>
                    </div>

                    @if($this->isSystemRole())
                        <div class="p-3 border border-orange-200 rounded-md bg-orange-50">
                            <div class="flex items-center">
                                <x-icon name="o-lock-closed" class="w-5 h-5 mr-2 text-orange-600" />
                                <div class="text-sm">
                                    <div class="font-semibold text-orange-800">System Role</div>
                                    <div class="text-orange-700">This role is protected and its name cannot be changed.</div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="space-y-3 text-sm">
                        <div>
                            <div class="font-medium text-gray-500">Users with this role</div>
                            <div class="flex items-center justify-between">
                                <span class="font-semibold">{{ $role->users()->count() }}</span>
                                @if($role->users()->count() > 0)
                                    <x-button
                                        label="View Users"
                                        icon="o-users"
                                        link="{{ route('admin.users.index', ['role' => $role->name]) }}"
                                        class="btn-ghost btn-xs"
                                    />
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="font-medium text-gray-500">Current permissions</div>
                            <div>{{ $role->permissions()->count() }} permissions</div>
                        </div>

                        <div>
                            <div class="font-medium text-gray-500">Created</div>
                            <div>{{ $role->created_at->format('M d, Y \a\t g:i A') }}</div>
                        </div>

                        <div>
                            <div class="font-medium text-gray-500">Last updated</div>
                            <div>{{ $role->updated_at->format('M d, Y \a\t g:i A') }}</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Permission Summary -->
            @if(count($permissionGroups) > 0)
                <x-card title="Permission Summary">
                    <div class="space-y-3">
                        @foreach($permissionGroups as $groupName => $groupPermissions)
                            <div class="flex items-center justify-between p-3 border rounded-lg {{ $this->isGroupSelected($groupName) ? 'border-green-500 bg-green-50' : ($this->isGroupPartiallySelected($groupName) ? 'border-yellow-500 bg-yellow-50' : 'border-gray-200') }}">
                                <div>
                                    <div class="text-sm font-medium capitalize">{{ str_replace('_', ' ', $groupName) }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ count(array_intersect(array_column($groupPermissions, 'id'), $selectedPermissions)) }}/{{ count($groupPermissions) }} permissions
                                    </div>
                                </div>
                                <div class="text-xs">
                                    @if($this->isGroupSelected($groupName))
                                        <span class="px-2 py-1 text-green-800 bg-green-100 rounded-full">Complete</span>
                                    @elseif($this->isGroupPartiallySelected($groupName))
                                        <span class="px-2 py-1 text-yellow-800 bg-yellow-100 rounded-full">Partial</span>
                                    @else
                                        <span class="px-2 py-1 text-gray-600 bg-gray-100 rounded-full">None</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
                    @if($this->isSystemRole())
                        <div>
                            <div class="font-semibold">System Role</div>
                            <p class="text-gray-600">This is a system role. You can modify its permissions but not its name.</p>
                        </div>
                    @endif

                    <div>
                        <div class="font-semibold">Permission Changes</div>
                        <p class="text-gray-600">Changes to permissions will immediately affect all users with this role.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Users Impact</div>
                        <p class="text-gray-600">This role is currently assigned to {{ $role->users()->count() }} user(s). Any changes will affect their access immediately.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Activity Logging</div>
                        <p class="text-gray-600">All changes to roles are logged for audit purposes.</p>
                    </div>
                </div>
            </x-card>

            <!-- Security Notice -->
            <x-card title="Security Notice" class="border-yellow-200 bg-yellow-50">
                <div class="space-y-2 text-sm">
                    <div class="flex items-start">
                        <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Live Changes</div>
                            <p class="text-yellow-700">Permission changes take effect immediately for all users with this role.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Careful Assignment</div>
                            <p class="text-yellow-700">Only assign permissions that are necessary for users with this role.</p>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
