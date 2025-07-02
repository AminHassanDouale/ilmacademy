<?php

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Roles Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Bulk actions
    public array $selectedRoles = [];
    public bool $selectAll = false;

    // Stats
    public array $stats = [];

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed roles management page',
            Role::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadStats();
    }

    protected function loadStats(): void
    {
        try {
            $totalRoles = Role::count();
            $totalPermissions = Permission::count();
            $rolesWithUsers = Role::has('users')->count();
            $rolesWithoutUsers = Role::doesntHave('users')->count();

            // Get user counts by role
            $adminCount = Role::where('name', 'admin')->first()?->users()->count() ?? 0;
            $teacherCount = Role::where('name', 'teacher')->first()?->users()->count() ?? 0;
            $parentCount = Role::where('name', 'parent')->first()?->users()->count() ?? 0;
            $studentCount = Role::where('name', 'student')->first()?->users()->count() ?? 0;

            $this->stats = [
                'total_roles' => $totalRoles,
                'total_permissions' => $totalPermissions,
                'roles_with_users' => $rolesWithUsers,
                'roles_without_users' => $rolesWithoutUsers,
                'admin_users' => $adminCount,
                'teacher_users' => $teacherCount,
                'parent_users' => $parentCount,
                'student_users' => $studentCount,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_roles' => 0,
                'total_permissions' => 0,
                'roles_with_users' => 0,
                'roles_without_users' => 0,
                'admin_users' => 0,
                'teacher_users' => 0,
                'parent_users' => 0,
                'student_users' => 0,
            ];
        }
    }

    // Sort data
    public function sortBy(string $column): void
    {
        if ($this->sortBy['column'] === $column) {
            $this->sortBy['direction'] = $this->sortBy['direction'] === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy['column'] = $column;
            $this->sortBy['direction'] = 'asc';
        }
        $this->resetPage();
    }

    // Redirect to create page
    public function redirectToCreate(): void
    {
        $this->redirect(route('admin.roles.create'));
    }

    // Redirect to edit page
    public function redirectToEdit(int $roleId): void
    {
        $this->redirect(route('admin.roles.edit', $roleId));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    // Delete role
    public function deleteRole(int $roleId): void
    {
        try {
            $role = Role::findOrFail($roleId);

            // Check if role has users
            if ($role->users()->count() > 0) {
                $this->error("Cannot delete role '{$role->name}' because it has {$role->users()->count()} user(s) assigned to it.");
                return;
            }

            // Prevent deletion of system roles
            $systemRoles = ['admin', 'teacher', 'parent', 'student'];
            if (in_array($role->name, $systemRoles)) {
                $this->error("Cannot delete system role '{$role->name}'.");
                return;
            }

            DB::beginTransaction();

            // Log activity before deletion
            ActivityLog::log(
                Auth::id(),
                'delete',
                "Deleted role: {$role->name}",
                Role::class,
                $role->id,
                [
                    'role_name' => $role->name,
                    'permissions_count' => $role->permissions()->count(),
                ]
            );

            $roleName = $role->name;
            $role->delete();

            DB::commit();

            $this->success("Role '{$roleName}' has been successfully deleted.");
            $this->loadStats();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Bulk delete roles
    public function bulkDeleteRoles(): void
    {
        if (empty($this->selectedRoles)) {
            $this->error('Please select roles to delete.');
            return;
        }

        try {
            $roles = Role::whereIn('id', $this->selectedRoles)->get();
            $systemRoles = ['admin', 'teacher', 'parent', 'student'];
            $deletedCount = 0;
            $errors = [];

            DB::beginTransaction();

            foreach ($roles as $role) {
                // Check if it's a system role
                if (in_array($role->name, $systemRoles)) {
                    $errors[] = "Cannot delete system role '{$role->name}'";
                    continue;
                }

                // Check if role has users
                if ($role->users()->count() > 0) {
                    $errors[] = "Role '{$role->name}' has {$role->users()->count()} user(s) assigned";
                    continue;
                }

                // Log activity
                ActivityLog::log(
                    Auth::id(),
                    'delete',
                    "Bulk deleted role: {$role->name}",
                    Role::class,
                    $role->id,
                    [
                        'role_name' => $role->name,
                        'bulk_action' => true,
                    ]
                );

                $role->delete();
                $deletedCount++;
            }

            DB::commit();

            if ($deletedCount > 0) {
                $this->success("Successfully deleted {$deletedCount} role(s).");
            }

            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->error($error);
                }
            }

            $this->selectedRoles = [];
            $this->selectAll = false;
            $this->loadStats();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Get filtered and paginated roles
    public function roles(): LengthAwarePaginator
    {
        return Role::query()
            ->with(['users', 'permissions'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                      ->orWhere('guard_name', 'like', "%{$this->search}%");
                });
            })
            ->withCount(['users', 'permissions'])
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    // Helper function to get role color
    private function getRoleColor(string $roleName): string
    {
        return match($roleName) {
            'admin' => 'bg-purple-100 text-purple-800',
            'teacher' => 'bg-blue-100 text-blue-800',
            'parent' => 'bg-green-100 text-green-800',
            'student' => 'bg-orange-100 text-orange-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Check if role is system role
    private function isSystemRole(string $roleName): bool
    {
        return in_array($roleName, ['admin', 'teacher', 'parent', 'student']);
    }

    public function with(): array
    {
        return [
            'roles' => $this->roles(),
        ];
    }
};?>
<div>
    <!-- Page header -->
    <x-header title="Roles Management" subtitle="Manage user roles and permissions" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search roles..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Create Role"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-4">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-shield-check" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_roles']) }}</div>
                        <div class="text-sm text-gray-500">Total Roles</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-key" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['total_permissions']) }}</div>
                        <div class="text-sm text-gray-500">Total Permissions</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-users" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['roles_with_users']) }}</div>
                        <div class="text-sm text-gray-500">Roles in Use</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-orange-100 rounded-full">
                        <x-icon name="o-exclamation-triangle" class="w-8 h-8 text-orange-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['roles_without_users']) }}</div>
                        <div class="text-sm text-gray-500">Unused Roles</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Role Distribution Cards -->
    <div class="grid grid-cols-1 gap-4 mb-8 md:grid-cols-4">
        <x-card class="border-purple-200 bg-purple-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['admin_users']) }}</div>
                <div class="text-sm text-purple-600">Admin Users</div>
            </div>
        </x-card>

        <x-card class="border-blue-200 bg-blue-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['teacher_users']) }}</div>
                <div class="text-sm text-blue-600">Teacher Users</div>
            </div>
        </x-card>

        <x-card class="border-green-200 bg-green-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ number_format($stats['parent_users']) }}</div>
                <div class="text-sm text-green-600">Parent Users</div>
            </div>
        </x-card>

        <x-card class="border-orange-200 bg-orange-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['student_users']) }}</div>
                <div class="text-sm text-orange-600">Student Users</div>
            </div>
        </x-card>
    </div>

    <!-- Bulk Actions -->
    @if(count($selectedRoles) > 0)
        <x-card class="mb-6">
            <div class="p-4 bg-red-50">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-red-800">
                        {{ count($selectedRoles) }} role(s) selected
                    </span>
                    <div class="flex gap-2">
                        <x-button
                            label="Delete Selected"
                            icon="o-trash"
                            wire:click="bulkDeleteRoles"
                            class="btn-sm btn-error"
                            wire:confirm="Are you sure you want to delete the selected roles? This action cannot be undone."
                        />
                    </div>
                </div>
            </div>
        </x-card>
    @endif

    <!-- Roles Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th>
                            <x-checkbox wire:model.live="selectAll" />
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('name')">
                            <div class="flex items-center">
                                Role Name
                                @if ($sortBy['column'] === 'name')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Guard</th>
                        <th>Users Count</th>
                        <th>Permissions Count</th>
                        <th class="cursor-pointer" wire:click="sortBy('created_at')">
                            <div class="flex items-center">
                                Created
                                @if ($sortBy['column'] === 'created_at')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($roles as $role)
                        <tr class="hover">
                            <td>
                                <x-checkbox
                                    wire:model.live="selectedRoles"
                                    value="{{ $role->id }}"
                                    {{ $this->isSystemRole($role->name) ? 'disabled' : '' }}
                                />
                            </td>
                            <td>
                                <div class="flex items-center">
                                    <div>
                                        <div class="font-semibold flex items-center">
                                            {{ ucfirst($role->name) }}
                                            @if($this->isSystemRole($role->name))
                                                <x-icon name="o-lock-closed" class="w-4 h-4 ml-2 text-gray-500" title="System Role" />
                                            @endif
                                        </div>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getRoleColor($role->name) }} mt-1">
                                            {{ ucfirst($role->name) }}
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="font-mono text-sm">{{ $role->guard_name }}</div>
                            </td>
                            <td>
                                <div class="flex items-center">
                                    <span class="font-semibold">{{ $role->users_count }}</span>
                                    @if($role->users_count > 0)
                                        <x-button
                                            label="View"
                                            icon="o-eye"
                                            link="{{ route('admin.users.index', ['role' => $role->name]) }}"
                                            class="btn-ghost btn-xs ml-2"
                                        />
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="font-semibold">{{ $role->permissions_count }}</span>
                            </td>
                            <td>
                                <div class="text-sm">{{ $role->created_at->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $role->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToEdit({{ $role->id }})"
                                        class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                        title="Edit"
                                    >
                                        ✏️
                                    </button>
                                    @if(!$this->isSystemRole($role->name))
                                        <button
                                            wire:click="deleteRole({{ $role->id }})"
                                            wire:confirm="Are you sure you want to delete this role? This action cannot be undone."
                                            class="p-2 text-red-600 bg-red-100 rounded-md hover:text-red-900 hover:bg-red-200"
                                            title="Delete"
                                            {{ $role->users_count > 0 ? 'disabled' : '' }}
                                        >
                                            🗑️
                                        </button>
                                    @else
                                        <div class="p-2 text-gray-400 bg-gray-100 rounded-md opacity-50" title="System role - cannot be deleted">
                                            🔒
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-shield-check" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No roles found</h3>
                                        <p class="text-gray-500 mt-1">
                                            @if($search)
                                                No roles match your search criteria.
                                            @else
                                                Get started by creating your first role.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search)
                                        <x-button
                                            label="Clear Search"
                                            wire:click="resetFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Create First Role"
                                            icon="o-plus"
                                            wire:click="redirectToCreate"
                                            color="primary"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $roles->links() }}
        </div>

        <!-- Results summary -->
        @if($roles->count() > 0)
        <div class="mt-4 text-sm text-gray-600 border-t pt-3">
            Showing {{ $roles->firstItem() ?? 0 }} to {{ $roles->lastItem() ?? 0 }}
            of {{ $roles->total() }} roles
            @if($search)
                (filtered from total)
            @endif
        </div>
        @endif
    </x-card>
</div>
