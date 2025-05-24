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
                link="{{ route('admin.academic-years.create') }}"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Academic Years table -->
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
                            <td>{{ $academicYear->id }}</td>
                            <td>
                                <div class="font-bold">{{ $academicYear->name }}</div>
                            </td>
                            <td>
                                {{ $academicYear->start_date->format('M d, Y') }}
                            </td>
                            <td>
                                {{ $academicYear->end_date->format('M d, Y') }}
                            </td>
                            <td>
                                @if ($academicYear->is_current)
                                    <x-badge label="Current" icon="o-check-circle" color="success" />
                                @else
                                    <x-badge label="Not Current" icon="o-clock" color="ghost" />
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <div class="tooltip" data-tip="Program Enrollments">
                                        <x-badge label="{{ $academicYear->program_enrollments_count }}" icon="o-user-group" />
                                    </div>
                                    <div class="tooltip" data-tip="Exams">
                                        <x-badge label="{{ $academicYear->exams_count }}" icon="o-clipboard-document-check" />
                                    </div>
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    @if (!$academicYear->is_current)
                                        <x-button
                                            icon="o-star"
                                            wire:click="setAsCurrent({{ $academicYear->id }})"
                                            color="success"
                                            size="sm"
                                            tooltip="Set as Current"
                                        />
                                    @endif

                                    <x-button
                                        icon="o-eye"
                                        link="{{ route('admin.academic-years.show', $academicYear->id) }}"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View"
                                    />

                                    <x-button
                                        icon="o-pencil"
                                        link="{{ route('admin.academic-years.edit', $academicYear->id) }}"
                                        color="info"
                                        size="sm"
                                        tooltip="Edit"
                                    />

                                    <x-button
                                        icon="o-trash"
                                        wire:click="confirmDelete({{ $academicYear->id }})"
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
                                    <h3 class="text-lg font-semibold text-gray-600">No academic years found</h3>
                                    <p class="text-gray-500">Try modifying your filters or create a new academic year</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $academicYears->links() }}
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
                        '1' => 'Current',
                        '0' => 'Not Current'
                    ]"
                    wire:model.live="status"
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
