<?php

use App\Models\Subject;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Subjects Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $curriculum = '';

    #[Url]
    public string $level = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'id', 'direction' => 'desc'];

    // Modal state
    public bool $showDeleteModal = false;
    public ?int $subjectToDelete = null;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed subjects management page',
            Subject::class,
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
    public function confirmDelete(int $subjectId): void
    {
        $this->subjectToDelete = $subjectId;
        $this->showDeleteModal = true;
    }

    // Cancel deletion
    public function cancelDelete(): void
    {
        $this->subjectToDelete = null;
        $this->showDeleteModal = false;
    }

    // Delete a subject
    public function deleteSubject(): void
    {
        if ($this->subjectToDelete) {
            $subject = Subject::find($this->subjectToDelete);

            if ($subject) {
                // Get curriculum name safely
                $curriculumName = 'Unknown';
                if ($subject->curriculum) {
                    $curriculumName = $subject->curriculum->name;
                }

                // Get data for logging before deletion
                $subjectDetails = [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code,
                    'curriculum_name' => $curriculumName,
                    'curriculum_id' => $subject->curriculum_id
                ];

                try {
                    DB::beginTransaction();

                    // Check if subject has related records
                    $hasSessions = $subject->sessions()->exists();
                    $hasExams = $subject->exams()->exists();
                    $hasTimetableSlots = $subject->timetableSlots()->exists();
                    $hasEnrollments = $subject->subjectEnrollments()->exists();

                    if ($hasSessions || $hasExams || $hasTimetableSlots || $hasEnrollments) {
                        $this->error("Cannot delete subject. It has associated sessions, exams, timetable slots, or enrollments.");
                        DB::rollBack();
                        $this->showDeleteModal = false;
                        $this->subjectToDelete = null;
                        return;
                    }

                    // Delete subject
                    $subject->delete();

                    // Log the action
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted subject: {$subjectDetails['name']} ({$subjectDetails['code']})",
                        Subject::class,
                        $this->subjectToDelete,
                        [
                            'subject_name' => $subjectDetails['name'],
                            'subject_code' => $subjectDetails['code'],
                            'curriculum_name' => $subjectDetails['curriculum_name'],
                            'curriculum_id' => $subjectDetails['curriculum_id']
                        ]
                    );

                    DB::commit();

                    // Show toast notification
                    $this->success("Subject {$subjectDetails['name']} has been successfully deleted.");
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("An error occurred during deletion: {$e->getMessage()}");
                }
            } else {
                $this->error("Subject not found!");
            }
        }

        $this->showDeleteModal = false;
        $this->subjectToDelete = null;
    }

    // Get filtered and paginated subjects
    public function subjects(): LengthAwarePaginator
    {
        return Subject::query()
            ->with(['curriculum', 'sessions', 'exams']) // Eager load relationships
            ->withCount(['sessions', 'exams', 'subjectEnrollments'])
            ->when($this->search, function (Builder $query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('code', 'like', '%' . $this->search . '%')
                    ->orWhereHas('curriculum', function (Builder $q) {
                        $q->where('name', 'like', '%' . $this->search . '%')
                            ->orWhere('code', 'like', '%' . $this->search . '%');
                    });
            })
            ->when($this->curriculum, function (Builder $query) {
                $query->where('curriculum_id', $this->curriculum);
            })
            ->when($this->level, function (Builder $query) {
                $query->where('level', $this->level);
            })
            ->when($this->sortBy['column'] === 'curriculum', function (Builder $query) {
                $query->join('curricula', 'subjects.curriculum_id', '=', 'curricula.id')
                    ->orderBy('curricula.name', $this->sortBy['direction'])
                    ->select('subjects.*');
            }, function (Builder $query) {
                $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
            })
            ->paginate($this->perPage);
    }

    // Get curricula for filter
    public function curricula(): Collection
    {
        return Curriculum::query()
            ->orderBy('name')
            ->get();
    }

    // Get unique levels for filter
    public function levels(): array
    {
        return Subject::query()
            ->select('level')
            ->distinct()
            ->orderBy('level')
            ->pluck('level')
            ->filter()
            ->toArray();
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->curriculum = '';
        $this->level = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'subjects' => $this->subjects(),
            'curricula' => $this->curricula(),
            'levels' => $this->levels(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Subjects Management" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search by name or code..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$curriculum, $level]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="New Subject"
                icon="o-plus"
                link="{{ route('admin.subjects.create') }}"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Subjects table -->
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
                        <th class="cursor-pointer" wire:click="sortBy('code')">
                            <div class="flex items-center">
                                Code
                                @if ($sortBy['column'] === 'code')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('curriculum')">
                            <div class="flex items-center">
                                Curriculum
                                @if ($sortBy['column'] === 'curriculum')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('level')">
                            <div class="flex items-center">
                                Level
                                @if ($sortBy['column'] === 'level')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Stats</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subjects as $subject)
                        <tr class="hover">
                            <td>{{ $subject->id }}</td>
                            <td>
                                <div class="font-bold">{{ $subject->name }}</div>
                            </td>
                            <td>
                                <x-badge label="{{ $subject->code }}" color="info" />
                            </td>
                            <td>
                                <a href="{{ route('admin.curricula.show', $subject->curriculum_id) }}" class="link link-hover">
                                    {{ $subject->curriculum->name ?: 'Unknown curriculum' }}
                                </a>
                            </td>
                            <td>
                                <x-badge
                                    label="{{ $subject->level }}"
                                    color="{{ match(strtolower($subject->level ?: '')) {
                                        'beginner' => 'success',
                                        'intermediate' => 'warning',
                                        'advanced' => 'error',
                                        default => 'ghost'
                                    } }}"
                                />
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <div class="tooltip" data-tip="Sessions">
                                        <x-badge label="{{ $subject->sessions_count }}" icon="o-calendar" />
                                    </div>
                                    <div class="tooltip" data-tip="Exams">
                                        <x-badge label="{{ $subject->exams_count }}" icon="o-clipboard-document-check" />
                                    </div>
                                    <div class="tooltip" data-tip="Enrollments">
                                        <x-badge label="{{ $subject->subject_enrollments_count }}" icon="o-user-group" />
                                    </div>
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        link="{{ route('admin.subjects.show', $subject->id) }}"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View"
                                    />

                                    <x-button
                                        icon="o-pencil"
                                        link="{{ route('admin.subjects.edit', $subject->id) }}"
                                        color="info"
                                        size="sm"
                                        tooltip="Edit"
                                    />

                                    <x-button
                                        icon="o-trash"
                                        wire:click="confirmDelete({{ $subject->id }})"
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
                                    <h3 class="text-lg font-semibold text-gray-600">No subjects found</h3>
                                    <p class="text-gray-500">Try modifying your filters or create a new subject</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $subjects->links() }}
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
                    <h3 class="text-lg font-semibold">Are you sure you want to delete this subject?</h3>
                    <p class="text-gray-600">This action is irreversible. Subjects with associated sessions, exams, timetable slots, or enrollments cannot be deleted.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancelDelete" />
            <x-button label="Delete" icon="o-trash" wire:click="deleteSubject" color="error" />
        </x-slot:actions>
    </x-modal>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by name or code"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by curriculum"
                    placeholder="All curricula"
                    :options="$curricula"
                    wire:model.live="curriculum"
                    option-label="name"
                    option-value="id"
                    empty-message="No curricula found"
                />
            </div>

            <div>
                <x-select
                    label="Filter by level"
                    placeholder="All levels"
                    :options="array_combine($levels, $levels)"
                    wire:model.live="level"
                    empty-message="No levels found"
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
