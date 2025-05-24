<?php

use App\Models\ChildProfile;
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

new #[Title('Children Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $gender = '';

    #[Url]
    public string $parent = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'id', 'direction' => 'desc'];

    // Modal state
    public bool $showDeleteModal = false;
    public ?int $childToDelete = null;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed children management page',
            ChildProfile::class,
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
    public function confirmDelete(int $childId): void
    {
        $this->childToDelete = $childId;
        $this->showDeleteModal = true;
    }

    // Cancel deletion
    public function cancelDelete(): void
    {
        $this->childToDelete = null;
        $this->showDeleteModal = false;
    }

    // Delete a child
    public function deleteChild(): void
    {
        if ($this->childToDelete) {
            $child = ChildProfile::find($this->childToDelete);

            if ($child) {
                // Get data for logging before deletion
                $childDetails = [
                    'id' => $child->id,
                    'name' => $child->user?->name ?? 'Unknown',
                    'parent_name' => $child->parentProfile?->user?->name ?? 'Unknown',
                    'parent_id' => $child->parent_profile_id
                ];

                try {
                    DB::beginTransaction();

                    // Delete enrollments and related records first
                    $child->programEnrollments()->delete();
                    $child->attendances()->delete();
                    $child->examResults()->delete();

                    // Delete child profile
                    $child->delete();

                    // Log the action
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted child profile: {$childDetails['name']}",
                        ChildProfile::class,
                        $this->childToDelete,
                        [
                            'child_name' => $childDetails['name'],
                            'parent_name' => $childDetails['parent_name'],
                            'parent_id' => $childDetails['parent_id']
                        ]
                    );

                    DB::commit();

                    // Show toast notification
                    $this->success("Child {$childDetails['name']} has been successfully deleted.");
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("An error occurred during deletion: {$e->getMessage()}");
                }
            } else {
                $this->error("Child not found!");
            }
        }

        $this->showDeleteModal = false;
        $this->childToDelete = null;
    }

    // Get filtered and paginated children
    public function children(): LengthAwarePaginator
    {
        return ChildProfile::query()
            ->with(['user', 'parentProfile.user', 'programEnrollments']) // Eager load relationships
            ->withCount('programEnrollments')
            ->when($this->search, function (Builder $query) {
                $query->whereHas('user', function (Builder $q) {
                    $q->where('name', 'like', '%' . $this->search . '%');
                })->orWhereHas('parentProfile.user', function (Builder $q) {
                    $q->where('name', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->gender, function (Builder $query) {
                $query->where('gender', $this->gender);
            })
            ->when($this->parent, function (Builder $query) {
                $query->where('parent_profile_id', $this->parent);
            })
            ->when($this->sortBy['column'] === 'name', function (Builder $query) {
                $query->join('users', 'child_profiles.user_id', '=', 'users.id')
                    ->orderBy('users.name', $this->sortBy['direction'])
                    ->select('child_profiles.*');
            }, function (Builder $query) {
                if ($this->sortBy['column'] === 'parent') {
                    $query->join('parent_profiles', 'child_profiles.parent_profile_id', '=', 'parent_profiles.id')
                        ->join('users', 'parent_profiles.user_id', '=', 'users.id')
                        ->orderBy('users.name', $this->sortBy['direction'])
                        ->select('child_profiles.*');
                } else {
                    $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
                }
            })
            ->paginate($this->perPage);
    }

    // Get parents for filter
    public function parents(): Collection
    {
        return ParentProfile::query()
            ->join('users', 'parent_profiles.user_id', '=', 'users.id')
            ->select('parent_profiles.id', 'users.name')
            ->orderBy('users.name')
            ->get();
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->gender = '';
        $this->parent = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'children' => $this->children(),
            'parents' => $this->parents(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Children Management" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search by name..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$gender, $parent]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="New Child"
                icon="o-plus"
                wire:click="$dispatch('openModal', { component: 'admin.children.create-modal' })"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Children table -->
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
                        <th class="cursor-pointer" wire:click="sortBy('parent')">
                            <div class="flex items-center">
                                Parent
                                @if ($sortBy['column'] === 'parent')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('date_of_birth')">
                            <div class="flex items-center">
                                Date of Birth
                                @if ($sortBy['column'] === 'date_of_birth')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('gender')">
                            <div class="flex items-center">
                                Gender
                                @if ($sortBy['column'] === 'gender')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Programs</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($children as $child)
                        <tr class="hover">
                            <td>{{ $child->id }}</td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar">
                                        <div class="w-12 h-12 mask mask-squircle">
                                            @if ($child->photo)
                                                <img src="{{ asset('storage/' . $child->photo) }}" alt="{{ $child->user?->name ?? 'Child' }}">
                                            @else
                                                <img src="{{ $child->user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $child->user?->name ?? 'Child' }}">
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <div class="font-bold">{{ $child->user?->name ?? 'No name' }}</div>
                                        <div class="text-sm opacity-70">{{ $child->program_enrollments_count ?? 0 }} enrollments</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if ($child->parentProfile)
                                    <a href="/admin/parents/{{ $child->parent_profile_id }}" class="link link-hover">
                                        {{ $child->parentProfile->user?->name ?? 'Unknown parent' }}
                                    </a>
                                @else
                                    <span class="text-gray-500">No parent</span>
                                @endif
                            </td>
                            <td>{{ $child->date_of_birth?->format('M d, Y') ?? 'Not set' }}</td>
                            <td>
                                <x-badge
                                    label="{{ ucfirst($child->gender ?? 'Not specified') }}"
                                    color="{{ match($child->gender ?? '') {
                                        'male' => 'info',
                                        'female' => 'secondary',
                                        'other' => 'warning',
                                        default => 'ghost'
                                    } }}"
                                />
                            </td>
                            <td>{{ $child->program_enrollments_count ?? 0 }}</td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        wire:click="$dispatch('openModal', { component: 'admin.children.show-modal', arguments: { childId: {{ $child->id }} }})"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View"
                                    />

                                    <x-button
                                        icon="o-pencil"
                                        wire:click="$dispatch('openModal', { component: 'admin.children.edit-modal', arguments: { childId: {{ $child->id }} }})"
                                        color="info"
                                        size="sm"
                                        tooltip="Edit"
                                    />

                                    <x-button
                                        icon="o-trash"
                                        wire:click="confirmDelete({{ $child->id }})"
                                        color="error"
                                        size="sm"
                                        tooltip="Delete"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-face-frown" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No children found</h3>
                                    <p class="text-gray-500">Try modifying your filters or create a new child profile</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $children->links() }}
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
                    <h3 class="text-lg font-semibold">Are you sure you want to delete this child profile?</h3>
                    <p class="text-gray-600">This action is irreversible and will remove all associated enrollments, attendances, and exam results.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancelDelete" />
            <x-button label="Delete" icon="o-trash" wire:click="deleteChild" color="error" />
        </x-slot:actions>
    </x-modal>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by name"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by gender"
                    placeholder="All genders"
                    :options="[
                        ['label' => 'Male', 'value' => 'male'],
                        ['label' => 'Female', 'value' => 'female'],
                        ['label' => 'Other', 'value' => 'other']
                    ]"
                    wire:model.live="gender"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Filter by parent"
                    placeholder="All parents"
                    :options="$parents"
                    wire:model.live="parent"
                    option-label="name"
                    option-value="id"
                    empty-message="No parents found"
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
