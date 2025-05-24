<?php

use App\Models\ParentProfile;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Parent Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'name', 'direction' => 'asc'];

    // Modal state
    public bool $showDeleteModal = false;
    public ?int $parentToDelete = null;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed parent management page',
            ParentProfile::class,
            null,
            ['ip' => request()->ip()]
        );
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
    }

    // Confirm deletion
    public function confirmDelete(int $parentId): void
    {
        $this->parentToDelete = $parentId;
        $this->showDeleteModal = true;
    }

    // Cancel deletion
    public function cancelDelete(): void
    {
        $this->parentToDelete = null;
        $this->showDeleteModal = false;
    }

    // Delete a parent
    public function deleteParent(): void
    {
        if ($this->parentToDelete) {
            $parent = ParentProfile::find($this->parentToDelete);

            if ($parent) {
                $parentName = $parent->user->name;

                try {
                    DB::beginTransaction();

                    // Delete child profiles
                    $parent->childProfiles()->delete();

                    // Delete parent profile
                    $parent->delete();

                    // Log the action
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted parent profile: $parentName",
                        ParentProfile::class,
                        $this->parentToDelete,
                        ['parent_name' => $parentName]
                    );

                    DB::commit();

                    // Show toast notification
                    $this->success("Parent $parentName has been successfully deleted.");
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("An error occurred during deletion: {$e->getMessage()}");
                }
            } else {
                $this->error("Parent not found!");
            }
        }

        $this->showDeleteModal = false;
        $this->parentToDelete = null;
    }

    // Toggle parent status
    public function toggleStatus(int $parentId): void
    {
        $parent = ParentProfile::find($parentId);

        if ($parent) {
            $user = $parent->user;

            if ($user) {
                $newStatus = $user->status === 'active' ? 'inactive' : 'active';
                $statusText = $newStatus === 'active' ? 'activated' : 'deactivated';

                $user->status = $newStatus;
                $user->save();

                // Log the action
                ActivityLog::log(
                    Auth::id(),
                    'update',
                    "Modified status of parent {$user->name}: $statusText",
                    ParentProfile::class,
                    $parent->id,
                    ['old_status' => $newStatus === 'active' ? 'inactive' : 'active', 'new_status' => $newStatus]
                );

                $this->dispatch('parentStatusUpdated');
                $this->success("The status of parent {$user->name} has been changed to $statusText.");
            }
        }
    }

    // Handle status update
    #[On('parentStatusUpdated')]
    public function handleParentStatusUpdated(): void
    {
        // This method can be used for actions after status update
    }

    // Get filtered and paginated parents
    public function parents(): LengthAwarePaginator
    {
        return ParentProfile::query()
            ->with(['user', 'childProfiles']) // Eager load relationships
            ->withCount('childProfiles')
            ->when($this->search, function (Builder $query) {
                $query->whereHas('user', function (Builder $q) {
                    $q->where(function (Builder $subQuery) {
                        $subQuery->where('name', 'like', '%' . $this->search . '%')
                            ->orWhere('email', 'like', '%' . $this->search . '%');
                    });
                })->orWhere('phone', 'like', '%' . $this->search . '%')
                 ->orWhere('address', 'like', '%' . $this->search . '%');
            })
            ->when($this->status, function (Builder $query) {
                $query->whereHas('user', function (Builder $q) {
                    $q->where('status', $this->status);
                });
            })
            ->when($this->sortBy['column'] === 'name', function (Builder $query) {
                $query->join('users', 'parent_profiles.user_id', '=', 'users.id')
                    ->orderBy('users.name', $this->sortBy['direction'])
                    ->select('parent_profiles.*');
            }, function (Builder $query) {
                $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
            })
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'parents' => $this->parents(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Parent Management" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$status]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />


        </x-slot:actions>
    </x-header>

    <!-- Parent table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('id')">
                            <div class="flex items-center">
                                ID
                                @if ($sortBy['column'] === 'id')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('name')">
                            <div class="flex items-center">
                                Name
                                @if ($sortBy['column'] === 'name')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>Children</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($parents as $parent)
                        <tr class="hover">
                            <td>{{ $parent->id }}</td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar">
                                        <div class="w-12 h-12 mask mask-squircle">
                                            <img src="{{ $parent->user->profile_photo_url }}" alt="{{ $parent->user->name }}">
                                        </div>
                                    </div>
                                    <div>
                                        <div class="font-bold">{{ $parent->user->name }}</div>
                                        <div class="text-sm opacity-70">{{ $parent->child_profiles_count ?? 0 }} children</div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $parent->user->email }}</td>
                            <td>{{ $parent->phone ?? 'No phone' }}</td>
                            <td>{{ Str::limit($parent->address ?? 'No address', 30) }}</td>
                            <td>
                                <x-badge
                                    label="{{ $parent->child_profiles_count }}"
                                    color="info"
                                />
                            </td>
                            <td>
                                <x-badge
                                    label="{{ match($parent->user->status ?? 'inactive') {
                                        'active' => 'Active',
                                        'inactive' => 'Inactive',
                                        'suspended' => 'Suspended',
                                        default => 'Unknown'
                                    } }}"
                                    color="{{ match($parent->user->status ?? 'inactive') {
                                        'active' => 'success',
                                        'inactive' => 'warning',
                                        'suspended' => 'error',
                                        default => 'secondary'
                                    } }}"
                                />
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        link="{{ route('admin.parents.show', $parent) }}"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View"
                                    />



                                    <x-button
                                        icon="{{ $parent->user->status === 'active' ? 'o-x-circle' : 'o-check-circle' }}"
                                        wire:click="toggleStatus({{ $parent->id }})"
                                        color="{{ $parent->user->status === 'active' ? 'warning' : 'success' }}"
                                        size="sm"
                                        tooltip="{{ $parent->user->status === 'active' ? 'Deactivate' : 'Activate' }}"
                                    />

                                    <x-button
                                        icon="o-trash"
                                        wire:click="confirmDelete({{ $parent->id }})"
                                        color="error"
                                        size="sm"
                                        tooltip="Delete"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-face-frown" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No parents found</h3>
                                    <p class="text-gray-500">Try modifying your filters or create a new parent</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $parents->links() }}
        </div>
    </x-card>

    <!-- Delete confirmation modal -->
    <x-modal wire:model="showDeleteModal" title="Delete Confirmation">
        <div class="p-4">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-full bg-error/20">
                    <x-icon name="o-exclamation-triangle" class="w-8 h-8 text-error" />
                </div>
                <div>
                    <h3 class="text-lg font-semibold">Are you sure you want to delete this parent?</h3>
                    <p class="text-gray-600">This action is irreversible and will remove all associated children profiles.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancelDelete" />
            <x-button label="Delete" icon="o-trash" wire:click="deleteParent" color="error" />
        </x-slot:actions>
    </x-modal>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by name, email, phone or address"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by status"
                    placeholder="All statuses"
                    :options="[
                        ['label' => 'Active', 'value' => 'active'],
                        ['label' => 'Inactive', 'value' => 'inactive'],
                        ['label' => 'Suspended', 'value' => 'suspended']
                    ]"
                    wire:model.live="status"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[10, 25, 50, 100]"
                    wire:model.live="perPage"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Reset" icon="o-x-mark" wire:click="resetFilters" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" />
        </x-slot:actions>
    </x-drawer>
</div>
