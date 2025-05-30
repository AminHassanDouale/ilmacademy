<?php

use App\Models\TeacherProfile;
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

new #[Title('Teacher Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $specialization = '';

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
    public ?int $teacherToDelete = null;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed teacher management page',
            TeacherProfile::class,
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
    public function confirmDelete(int $teacherId): void
    {
        $this->teacherToDelete = $teacherId;
        $this->showDeleteModal = true;
    }

    // Cancel deletion
    public function cancelDelete(): void
    {
        $this->teacherToDelete = null;
        $this->showDeleteModal = false;
    }

    // Delete a teacher
    public function deleteTeacher(): void
    {
        if ($this->teacherToDelete) {
            $teacher = TeacherProfile::find($this->teacherToDelete);

            if ($teacher) {
                $teacherName = $teacher->user->name;

                try {
                    DB::beginTransaction();

                    // Delete related records
                    $teacher->sessions()->delete();
                    $teacher->exams()->delete();
                    $teacher->timetableSlots()->delete();

                    // Delete teacher profile
                    $teacher->delete();

                    // Log the action
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted teacher profile: $teacherName",
                        TeacherProfile::class,
                        $this->teacherToDelete,
                        ['teacher_name' => $teacherName]
                    );

                    DB::commit();

                    // Show toast notification
                    $this->success("Teacher $teacherName has been successfully deleted.");
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("An error occurred during deletion: {$e->getMessage()}");
                }
            } else {
                $this->error("Teacher not found!");
            }
        }

        $this->showDeleteModal = false;
        $this->teacherToDelete = null;
    }

    // Toggle teacher status
    public function toggleStatus(int $teacherId): void
    {
        $teacher = TeacherProfile::find($teacherId);

        if ($teacher) {
            $user = $teacher->user;

            if ($user) {
                $newStatus = $user->status === 'active' ? 'inactive' : 'active';
                $statusText = $newStatus === 'active' ? 'activated' : 'deactivated';

                $user->status = $newStatus;
                $user->save();

                // Log the action
                ActivityLog::log(
                    Auth::id(),
                    'update',
                    "Modified status of teacher {$user->name}: $statusText",
                    TeacherProfile::class,
                    $teacher->id,
                    ['old_status' => $newStatus === 'active' ? 'inactive' : 'active', 'new_status' => $newStatus]
                );

                $this->dispatch('teacherStatusUpdated');
                $this->success("The status of teacher {$user->name} has been changed to $statusText.");
            }
        }
    }

    // Handle status update
    #[On('teacherStatusUpdated')]
    public function handleTeacherStatusUpdated(): void
    {
        // This method can be used for actions after status update
    }

    // Get filtered and paginated teachers
    public function teachers(): LengthAwarePaginator
    {
        return TeacherProfile::query()
            ->with('user') // Eager load user relationship
            ->when($this->search, function (Builder $query) {
                $query->whereHas('user', function (Builder $q) {
                    $q->where(function (Builder $subQuery) {
                        $subQuery->where('name', 'like', '%' . $this->search . '%')
                            ->orWhere('email', 'like', '%' . $this->search . '%');
                    });
                })->orWhere('phone', 'like', '%' . $this->search . '%');
            })
            ->when($this->specialization, function (Builder $query) {
                $query->where('specialization', $this->specialization);
            })
            ->when($this->status, function (Builder $query) {
                $query->whereHas('user', function (Builder $q) {
                    $q->where('status', $this->status);
                });
            })
            ->when($this->sortBy['column'] === 'name', function (Builder $query) {
                $query->join('users', 'teacher_profiles.user_id', '=', 'users.id')
                    ->orderBy('users.name', $this->sortBy['direction'])
                    ->select('teacher_profiles.*');
            }, function (Builder $query) {
                $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
            })
            ->paginate($this->perPage);
    }

    // Get available specializations
    public function specializations(): Collection
    {
        return TeacherProfile::select('specialization')
            ->distinct()
            ->whereNotNull('specialization')
            ->get();
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->specialization = '';
        $this->status = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'teachers' => $this->teachers(),
            'specializations' => $this->specializations(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Teacher Management" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$specialization, $status]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button label="New Teacher" icon="o-plus" link="{{ route('admin.teachers.create') }}" class="btn-primary" responsive />
        </x-slot:actions>
    </x-header>

    <!-- Teacher table -->
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
                        <th>Specialization</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($teachers as $teacher)
                        <tr class="hover">
                            <td>{{ $teacher->id }}</td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar">
                                        <div class="w-12 h-12 mask mask-squircle">
                                            <img src="{{ $teacher->user->profile_photo_url }}" alt="{{ $teacher->user->name }}">
                                        </div>
                                    </div>
                                    <div>
                                        <div class="font-bold">{{ $teacher->user->name }}</div>
                                        <div class="text-sm opacity-70">{{ $teacher->sessions_count ?? 0 }} sessions</div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $teacher->user->email }}</td>
                            <td>{{ $teacher->phone ?? 'No phone' }}</td>
                            <td>
                                <x-badge
                                    label="{{ $teacher->specialization ?? 'Not specified' }}"
                                    color="info"
                                />
                            </td>
                            <td>
                                <x-badge
                                    label="{{ match($teacher->user->status ?? 'inactive') {
                                        'active' => 'Active',
                                        'inactive' => 'Inactive',
                                        'suspended' => 'Suspended',
                                        default => 'Unknown'
                                    } }}"
                                    color="{{ match($teacher->user->status ?? 'inactive') {
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
                                        icon="o-pencil"
                                        link="{{ route('admin.teachers.edit', $teacher) }}"
                                        color="info"
                                        size="sm"
                                        tooltip="Edit"
                                    />

                                    <x-button
                                        icon="{{ $teacher->user->status === 'active' ? 'o-x-circle' : 'o-check-circle' }}"
                                        wire:click="toggleStatus({{ $teacher->id }})"
                                        color="{{ $teacher->user->status === 'active' ? 'warning' : 'success' }}"
                                        size="sm"
                                        tooltip="{{ $teacher->user->status === 'active' ? 'Deactivate' : 'Activate' }}"
                                    />

                                    <x-button
                                        icon="o-trash"
                                        wire:click="confirmDelete({{ $teacher->id }})"
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
                                    <h3 class="text-lg font-semibold text-gray-600">No teachers found</h3>
                                    <p class="text-gray-500">Try modifying your filters or create a new teacher</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $teachers->links() }}
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
                    <h3 class="text-lg font-semibold">Are you sure you want to delete this teacher?</h3>
                    <p class="text-gray-600">This action is irreversible and will remove all associated data.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancelDelete" />
            <x-button label="Delete" icon="o-trash" wire:click="deleteTeacher" color="error" />
        </x-slot:actions>
    </x-modal>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by name, email or phone"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by specialization"
                    placeholder="All specializations"
                    :options="$specializations->pluck('specialization')->toArray()"
                    wire:model.live="specialization"
                    empty-message="No specializations found"
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
