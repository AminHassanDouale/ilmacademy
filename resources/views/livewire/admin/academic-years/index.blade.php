<?php

use App\Models\AcademicYear;
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

new #[Title('Academic Years Management')] class extends Component {
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
    public array $sortBy = ['column' => 'id', 'direction' => 'desc'];

    // Modal state
    public bool $showDeleteModal = false;
    public ?int $academicYearToDelete = null;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed academic years management page',
            AcademicYear::class,
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

    // Redirect to create page
    public function redirectToCreate(): void
    {
        $this->redirect(route('admin.academic-years.create'));
    }

    // Redirect to show page
    public function redirectToShow(int $academicYearId): void
    {
        $this->redirect(route('admin.academic-years.show', $academicYearId));
    }

    // Redirect to edit page
    public function redirectToEdit(int $academicYearId): void
    {
        $this->redirect(route('admin.academic-years.edit', $academicYearId));
    }

    // Confirm deletion
    public function confirmDelete(int $academicYearId): void
    {
        $this->academicYearToDelete = $academicYearId;
        $this->showDeleteModal = true;
    }

    // Cancel deletion
    public function cancelDelete(): void
    {
        $this->academicYearToDelete = null;
        $this->showDeleteModal = false;
    }

    // Delete an academic year
    public function deleteAcademicYear(): void
    {
        if ($this->academicYearToDelete) {
            $academicYear = AcademicYear::find($this->academicYearToDelete);

            if ($academicYear) {
                // Get data for logging before deletion
                $academicYearDetails = [
                    'id' => $academicYear->id,
                    'name' => $academicYear->name,
                    'start_date' => $academicYear->start_date->format('Y-m-d'),
                    'end_date' => $academicYear->end_date->format('Y-m-d'),
                    'is_current' => $academicYear->is_current
                ];

                try {
                    DB::beginTransaction();

                    // Check if academic year has related records
                    $hasProgramEnrollments = $academicYear->programEnrollments()->exists();
                    $hasExams = $academicYear->exams()->exists();

                    if ($hasProgramEnrollments || $hasExams) {
                        $this->error("Cannot delete academic year. It has associated program enrollments or exams.");
                        DB::rollBack();
                        $this->showDeleteModal = false;
                        $this->academicYearToDelete = null;
                        return;
                    }

                    // Delete academic year
                    $academicYear->delete();

                    // Log the action
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted academic year: {$academicYearDetails['name']}",
                        AcademicYear::class,
                        $this->academicYearToDelete,
                        [
                            'academic_year_name' => $academicYearDetails['name'],
                            'start_date' => $academicYearDetails['start_date'],
                            'end_date' => $academicYearDetails['end_date'],
                            'is_current' => $academicYearDetails['is_current']
                        ]
                    );

                    DB::commit();

                    // Show toast notification
                    $this->success("Academic year {$academicYearDetails['name']} has been successfully deleted.");

                    // Refresh the page data after successful deletion
                    $this->resetPage();
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("An error occurred during deletion: {$e->getMessage()}");
                }
            } else {
                $this->error("Academic year not found!");
            }
        }

        $this->showDeleteModal = false;
        $this->academicYearToDelete = null;
    }

    // Set an academic year as current
    public function setAsCurrent(int $academicYearId): void
    {
        try {
            DB::beginTransaction();

            // First, unset current for all academic years
            AcademicYear::where('is_current', true)
                ->update(['is_current' => false]);

            // Set the selected one as current
            $academicYear = AcademicYear::findOrFail($academicYearId);
            $academicYear->is_current = true;
            $academicYear->save();

            // Log the action
            ActivityLog::log(
                Auth::id(),
                'update',
                "Set {$academicYear->name} as the current academic year",
                AcademicYear::class,
                $academicYearId,
                [
                    'academic_year_name' => $academicYear->name,
                    'start_date' => $academicYear->start_date->format('Y-m-d'),
                    'end_date' => $academicYear->end_date->format('Y-m-d')
                ]
            );

            DB::commit();

            // Show toast notification
            $this->success("{$academicYear->name} has been set as the current academic year.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get filtered and paginated academic years
    public function academicYears(): LengthAwarePaginator
    {
        return AcademicYear::query()
            ->withCount(['programEnrollments', 'exams']) // Eager load relationships
            ->when($this->search, function (Builder $query) {
                $query->where('name', 'like', '%' . $this->search . '%');
            })
            ->when($this->status !== '', function (Builder $query) {
                if ($this->status === '1') {
                    $query->where('is_current', true);
                } else {
                    $query->where('is_current', false);
                }
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
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
            'academicYears' => $this->academicYears(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Academic Years Management" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search by name..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
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

            <x-button
                label="New Academic Year"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Academic Years table -->
    <x-card>
        <!-- Mobile/Tablet Card View (hidden on desktop) -->
        <div class="block lg:hidden">
            <div class="space-y-4">
                @forelse ($academicYears as $academicYear)
                    <div class="p-4 transition-colors border rounded-lg bg-base-50 hover:bg-base-100">
                        <!-- Academic Year Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-3 mb-2">
                                    <h3 class="text-lg font-semibold truncate">{{ $academicYear->name }}</h3>
                                    @if ($academicYear->is_current)
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-green-800 bg-green-100 rounded-full">
                                            ✅ Current
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-600 bg-gray-100 rounded-full">
                                            ⏳ Not Current
                                        </span>
                                    @endif
                                </div>
                                <div class="font-mono text-sm text-gray-500">#{{ $academicYear->id }}</div>
                            </div>
                            <!-- Actions -->
                            <div class="flex gap-1 ml-2">
                                @if (!$academicYear->is_current)
                                    <button
                                        wire:click="setAsCurrent({{ $academicYear->id }})"
                                        class="p-2 text-green-600 bg-green-100 rounded-md hover:text-green-900 hover:bg-green-200"
                                        title="Set as Current"
                                    >
                                        ⭐
                                    </button>
                                @endif
                                <button
                                    wire:click="redirectToShow({{ $academicYear->id }})"
                                    class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                    title="View"
                                >
                                    👁️
                                </button>
                                <button
                                    wire:click="redirectToEdit({{ $academicYear->id }})"
                                    class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                    title="Edit"
                                >
                                    ✏️
                                </button>
                                <button
                                    wire:click="confirmDelete({{ $academicYear->id }})"
                                    class="p-2 text-red-600 bg-red-100 rounded-md hover:text-red-900 hover:bg-red-200"
                                    title="Delete"
                                >
                                    🗑️
                                </button>
                            </div>
                        </div>

                        <!-- Description -->
                        @if($academicYear->description)
                            <p class="mb-3 text-sm text-gray-600 line-clamp-2">
                                {{ Str::limit($academicYear->description, 100) }}
                            </p>
                        @endif

                        <!-- Date Range -->
                        <div class="grid grid-cols-1 gap-3 mb-3 sm:grid-cols-2">
                            <div>
                                <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Start Date</label>
                                <div class="text-sm font-medium">{{ $academicYear->start_date->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $academicYear->start_date->format('l') }}</div>
                            </div>
                            <div>
                                <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">End Date</label>
                                <div class="text-sm font-medium">{{ $academicYear->end_date->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $academicYear->end_date->format('l') }}</div>
                            </div>
                        </div>

                        <!-- Duration Badge -->
                        <div class="mb-3">
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-purple-800 bg-purple-100 rounded-full">
                                📅 {{ $academicYear->start_date->diffInDays($academicYear->end_date) }} days
                            </span>
                        </div>

                        <!-- Stats -->
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-800 bg-blue-100 rounded-full" title="Program Enrollments">
                                👥 {{ $academicYear->program_enrollments_count ?? 0 }} Enrollments
                            </span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-orange-800 bg-orange-100 rounded-full" title="Exams">
                                📝 {{ $academicYear->exams_count ?? 0 }} Exams
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="py-12 text-center">
                        <div class="flex flex-col items-center justify-center gap-4">
                            <x-icon name="o-academic-cap" class="w-16 h-16 text-gray-300" />
                            <div>
                                <h3 class="text-lg font-semibold text-gray-600">No academic years found</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    @if($search || $status)
                                        No academic years match your current filters.
                                    @else
                                        Get started by creating your first academic year.
                                    @endif
                                </p>
                            </div>
                            @if($search || $status)
                                <x-button
                                    label="Clear Filters"
                                    wire:click="resetFilters"
                                    color="secondary"
                                    size="sm"
                                />
                            @else
                                <x-button
                                    label="Create First Academic Year"
                                    icon="o-plus"
                                    wire:click="redirectToCreate"
                                    color="primary"
                                />
                            @endif
                        </div>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Desktop Table View (hidden on mobile/tablet) -->
        <div class="hidden lg:block">
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
                            <th class="cursor-pointer" wire:click="sortBy('start_date')">
                                <div class="flex items-center">
                                    Start Date
                                    @if ($sortBy['column'] === 'start_date')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer" wire:click="sortBy('end_date')">
                                <div class="flex items-center">
                                    End Date
                                    @if ($sortBy['column'] === 'end_date')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer" wire:click="sortBy('is_current')">
                                <div class="flex items-center">
                                    Status
                                    @if ($sortBy['column'] === 'is_current')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th>Stats</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($academicYears as $academicYear)
                            <tr class="hover">
                                <td class="font-mono text-sm">#{{ $academicYear->id }}</td>
                                <td>
                                    <div class="font-bold">{{ $academicYear->name }}</div>
                                    @if($academicYear->description)
                                        <div class="max-w-xs text-sm truncate opacity-70">
                                            {{ Str::limit($academicYear->description, 60) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="font-medium">{{ $academicYear->start_date->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $academicYear->start_date->format('D') }}</div>
                                </td>
                                <td>
                                    <div class="font-medium">{{ $academicYear->end_date->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $academicYear->end_date->format('D') }}</div>
                                </td>
                                <td class="py-2">
                                    @if ($academicYear->is_current)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            ✅ Current
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                            ⏳ Not Current
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex gap-1">
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-800 bg-blue-100 rounded-full" title="Program Enrollments">
                                            👥 {{ $academicYear->program_enrollments_count ?? 0 }}
                                        </span>
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-orange-800 bg-orange-100 rounded-full" title="Exams">
                                            📝 {{ $academicYear->exams_count ?? 0 }}
                                        </span>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        @if (!$academicYear->is_current)
                                            <button
                                                wire:click="setAsCurrent({{ $academicYear->id }})"
                                                class="p-2 text-green-600 bg-green-100 rounded-md hover:text-green-900 hover:bg-green-200"
                                                title="Set as Current"
                                            >
                                                ⭐
                                            </button>
                                        @endif

                                        <button
                                            wire:click="redirectToShow({{ $academicYear->id }})"
                                            class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                            title="View"
                                        >
                                            👁️
                                        </button>
                                        <button
                                            wire:click="redirectToEdit({{ $academicYear->id }})"
                                            class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                            title="Edit"
                                        >
                                            ✏️
                                        </button>
                                        <button
                                            wire:click="confirmDelete({{ $academicYear->id }})"
                                            class="p-2 text-red-600 bg-red-100 rounded-md hover:text-red-900 hover:bg-red-200"
                                            title="Delete"
                                        >
                                            🗑️
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center">
                                    <div class="flex flex-col items-center justify-center gap-4">
                                        <x-icon name="o-academic-cap" class="w-20 h-20 text-gray-300" />
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-600">No academic years found</h3>
                                            <p class="mt-1 text-gray-500">
                                                @if($search || $status)
                                                    No academic years match your current filters.
                                                @else
                                                    Get started by creating your first academic year.
                                                @endif
                                            </p>
                                        </div>
                                        @if($search || $status)
                                            <x-button
                                                label="Clear Filters"
                                                wire:click="resetFilters"
                                                color="secondary"
                                                size="sm"
                                            />
                                        @else
                                            <x-button
                                                label="Create First Academic Year"
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
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $academicYears->links() }}
        </div>

        <!-- Results summary -->
        @if($academicYears->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between sm:items-center">
                <span>
                    Showing {{ $academicYears->firstItem() ?? 0 }} to {{ $academicYears->lastItem() ?? 0 }}
                    of {{ $academicYears->total() }} academic years
                    @if($search || $status)
                        (filtered from total)
                    @endif
                </span>
                <!-- Mobile view indicator -->
                <div class="flex items-center gap-2 lg:hidden">
                    <span class="text-xs text-gray-500">Card view active</span>
                    <x-icon name="o-calendar-days" class="w-4 h-4 text-gray-400" />
                </div>
            </div>
        </div>
        @endif
    </x-card>

    <!-- Delete confirmation modal -->
    <x-modal wire:model="showDeleteModal" title="Delete Confirmation">
        <div class="p-4">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-full bg-error/20">
                    <x-icon name="o-exclamation-triangle" class="w-8 h-8 text-error" />
                </div>
                <div>
                    <h3 class="text-lg font-semibold">Are you sure you want to delete this academic year?</h3>
                    <p class="text-gray-600">This action is irreversible. Academic years with associated program enrollments or exams cannot be deleted.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancelDelete" />
            <x-button label="Delete" icon="o-trash" wire:click="deleteAcademicYear" color="error" />
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
                    label="Filter by status"
                    placeholder="All statuses"
                    :options="[
                        ['id' => '1', 'name' => 'Current'],
                        ['id' => '0', 'name' => 'Not Current']
                    ]"
                    option-value="id"
                    option-label="name"
                    wire:model.live="status"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[
                        ['id' => 10, 'name' => '10 per page'],
                        ['id' => 25, 'name' => '25 per page'],
                        ['id' => 50, 'name' => '50 per page'],
                        ['id' => 100, 'name' => '100 per page']
                    ]"
                    option-value="id"
                    option-label="name"
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
