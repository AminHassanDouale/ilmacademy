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

new #[Title('Create Role')] class extends Component {
    use Toast;

    // Form data
    public string $name = '';
    public string $guardName = 'web';
    public array $selectedPermissions = [];

    // Options
    public array $permissionOptions = [];
    public array $permissionGroups = [];

    // Mount the component
    public function mount(): void
    {
        Log::info('Role Create Component Mounted', [
            'user_id' => Auth::id(),
            'ip' => request()->ip()
        ]);

        $this->loadPermissionOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed create role page',
            Role::class,
            null,
            ['ip' => request()->ip()]
        );
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
        Log::info('Role Create Started', [
            'user_id' => Auth::id(),
            'form_data' => [
                'name' => $this->name,
                'guardName' => $this->guardName,
                'selectedPermissions' => $this->selectedPermissions,
            ]
        ]);

        try {
            // Validate form data
            Log::debug('Starting Validation');

            $validated = $this->validate([
                'name' => 'required|string|max:255|unique:roles,name',
                'guardName' => 'required|string|max:255',
                'selectedPermissions' => 'array',
                'selectedPermissions.*' => 'integer|exists:permissions,id',
            ], [
                'name.required' => 'Please enter a role name.',
                'name.max' => 'Role name must not exceed 255 characters.',
                'name.unique' => 'This role name already exists.',
                'guardName.required' => 'Please enter a guard name.',
                'guardName.max' => 'Guard name must not exceed 255 characters.',
                'selectedPermissions.*.exists' => 'One or more selected permissions are invalid.',
            ]);

            Log::info('Validation Passed', ['validated_data' => $validated]);

            // Prepare role name (lowercase, no spaces)
            $roleName = strtolower(trim($validated['name']));
            $roleName = preg_replace('/[^a-z0-9_-]/', '_', $roleName);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Create role
            Log::debug('Creating Role Record');
            $role = Role::create([
                'name' => $roleName,
                'guard_name' => $validated['guardName'],
            ]);

            Log::info('Role Created Successfully', [
                'role_id' => $role->id,
                'role_name' => $role->name
            ]);

            // Assign permissions if any selected
            if (!empty($validated['selectedPermissions'])) {
                Log::debug('Assigning Permissions');
                $permissions = Permission::whereIn('id', $validated['selectedPermissions'])->get();
                $role->syncPermissions($permissions);

                Log::info('Permissions Assigned Successfully', [
                    'role_id' => $role->id,
                    'permissions_count' => count($validated['selectedPermissions']),
                    'permission_names' => $permissions->pluck('name')->toArray()
                ]);
            }

            // Log activity
            Log::debug('Logging Activity');
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created role: {$role->name} with " . count($validated['selectedPermissions']) . " permissions",
                Role::class,
                $role->id,
                [
                    'role_name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'permissions_count' => count($validated['selectedPermissions']),
                    'assigned_permissions' => $permissions->pluck('name')->toArray() ?? [],
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Show success toast
            $this->success("Role '{$role->name}' has been successfully created.");
            Log::info('Success Toast Displayed');

            // Redirect to roles index page
            Log::info('Redirecting to Roles Index Page', [
                'role_id' => $role->id,
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
            Log::error('Role Creation Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'form_data' => [
                    'name' => $this->name,
                    'guardName' => $this->guardName,
                    'selectedPermissions' => $this->selectedPermissions,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
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

    public function with(): array
    {
        return [
            // Empty array - we use computed properties instead
        ];
    }
};?>
<div>
    <!-- Page header -->
    <x-header title="Create New Role" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
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
                                help-text="Will be converted to lowercase with underscores"
                            />
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
                            label="Create Role"
                            icon="o-shield-check"
                            type="submit"
                            color="primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Preview and Help -->
        <div class="space-y-6">
            <!-- Role Preview Card -->
            <x-card title="Role Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="mb-4">
                        <div class="text-lg font-semibold">{{ $name ?: 'Role Name' }}</div>
                        <div class="text-sm text-gray-500">
                            System name: {{ $name ? strtolower(preg_replace('/[^a-z0-9_-]/', '_', trim($name))) : 'role_name' }}
                        </div>
                        <div class="mt-1 text-xs text-gray-400">Guard: {{ $guardName }}</div>
                    </div>

                    <div class="space-y-3 text-sm">
                        <div>
                            <strong>Permissions Selected:</strong>
                            <span class="px-2 py-1 ml-2 text-xs text-blue-800 bg-blue-100 rounded-full">
                                {{ count($selectedPermissions) }}
                            </span>
                        </div>

                        @if(count($selectedPermissions) > 0)
                            <div>
                                <strong>Selected Permissions:</strong>
                                <div class="mt-2 space-y-1 overflow-y-auto max-h-32">
                                    @foreach($permissionOptions as $permission)
                                        @if($this->isPermissionSelected($permission['id']))
                                            <div class="px-2 py-1 text-xs text-green-800 bg-green-100 rounded">
                                                {{ $permission['display_name'] }}
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="text-gray-500">No permissions selected</div>
                        @endif
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
                    <div>
                        <div class="font-semibold">Role Naming</div>
                        <p class="text-gray-600">Role names will be automatically converted to lowercase with underscores replacing spaces and special characters.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Guard Name</div>
                        <p class="text-gray-600">The guard name should typically be 'web' for web applications. This determines which authentication guard the role applies to.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Permissions</div>
                        <p class="text-gray-600">Select the specific permissions that users with this role should have. You can use the group toggles to quickly select all permissions in a category.</p>
                    </div>

                    <div>
                        <div class="font-semibold">System Roles</div>
                        <p class="text-gray-600">Be careful not to create roles with the same names as existing system roles (admin, teacher, parent, student).</p>
                    </div>
                </div>
            </x-card>

            <!-- Security Notice -->
            <x-card title="Security Notice" class="border-yellow-200 bg-yellow-50">
                <div class="space-y-2 text-sm">
                    <div class="flex items-start">
                        <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Permission Security</div>
                            <p class="text-yellow-700">Only assign the minimum permissions necessary for users with this role to perform their duties.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Role Management</div>
                            <p class="text-yellow-700">Roles with administrative permissions should be assigned carefully and monitored regularly.</p>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
