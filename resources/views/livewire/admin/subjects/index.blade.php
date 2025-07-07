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

    // Redirect to create page
    public function redirectToCreate(): void
    {
        $this->redirect(route('admin.subjects.create'));
    }

    // Redirect to show page
    public function redirectToShow(int $subjectId): void
    {
        $this->redirect(route('admin.subjects.show', $subjectId));
    }

    // Redirect to edit page
    public function redirectToEdit(int $subjectId): void
    {
        $this->redirect(route('admin.subjects.edit', $subjectId));
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
                // Get data for logging before deletion
                $subjectDetails = [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code,
                    'curriculum_name' => $subject->curriculum->name ?? 'Unknown',
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

                    // Refresh the page data after successful deletion
                    $this->resetPage();
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
            ->with(['curriculum']) // Eager load relationships
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

    // Helper function to get level color
    private function getLevelColor(string $level): string
    {
        return match(strtolower($level)) {
            'beginner' => 'bg-green-100 text-green-800',
            'intermediate' => 'bg-yellow-100 text-yellow-800',
            'advanced' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-600'
        };
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
                wire:click="redirectToCreate"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Subjects table -->
    <x-card>
        <!-- Mobile/Tablet Card View (hidden on desktop) -->
        <div class="block lg:hidden">
            <div class="space-y-4">
                @forelse ($subjects as $subject)
                    <div class="p-4 transition-colors border rounded-lg bg-base-50 hover:bg-base-100">
                        <!-- Subject Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold truncate">{{ $subject->name }}</h3>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="font-mono text-sm text-gray-500">#{{ $subject->id }}</span>
                                    @if(!empty($subject->code))
                                        <span class="inline-flex items-center px-2 py-1 font-mono text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                                            {{ $subject->code }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <!-- Actions -->
                            <div class="flex gap-1 ml-2">
                                <button
                                    wire:click="redirectToShow({{ $subject->id }})"
                                    class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                    title="View"
                                >
                                    👁️
                                </button>
                                <button
                                    wire:click="redirectToEdit({{ $subject->id }})"
                                    class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                    title="Edit"
                                >
                                    ✏️
                                </button>
                                <button
                                    wire:click="confirmDelete({{ $subject->id }})"
                                    class="p-2 text-red-600 bg-red-100 rounded-md hover:text-red-900 hover:bg-red-200"
                                    title="Delete"
                                >
                                    🗑️
                                </button>
                            </div>
                        </div>

                        <!-- Description -->
                        @if($subject->description)
                            <p class="mb-3 text-sm text-gray-600 line-clamp-2">
                                {{ Str::limit($subject->description, 100) }}
                            </p>
                        @endif

                        <!-- Curriculum and Level -->
                        <div class="grid grid-cols-1 gap-3 mb-3 sm:grid-cols-2">
                            <div>
                                <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Curriculum</label>
                                @if($subject->curriculum)
                                    <a href="{{ route('admin.curricula.show', $subject->curriculum_id) }}" class="text-sm text-blue-600 underline hover:text-blue-800">
                                        {{ $subject->curriculum->name }}
                                    </a>
                                    @if($subject->curriculum->code)
                                        <div class="text-xs text-gray-500">{{ $subject->curriculum->code }}</div>
                                    @endif
                                @else
                                    <span class="text-sm italic text-gray-400">Unknown curriculum</span>
                                @endif
                            </div>
                            <div>
                                <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Level</label>
                                @if(!empty($subject->level))
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getLevelColor($subject->level) }}">
                                        {{ $subject->level }}
                                    </span>
                                @else
                                    <span class="text-xs italic text-gray-400">No level</span>
                                @endif
                            </div>
                        </div>

                        <!-- Stats -->
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-indigo-800 bg-indigo-100 rounded-full" title="Sessions">
                                📅 {{ $subject->sessions_count ?? 0 }}
                            </span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-orange-800 bg-orange-100 rounded-full" title="Exams">
                                📝 {{ $subject->exams_count ?? 0 }}
                            </span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-purple-800 bg-purple-100 rounded-full" title="Enrollments">
                                👥 {{ $subject->subject_enrollments_count ?? 0 }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="py-12 text-center">
                        <div class="flex flex-col items-center justify-center gap-4">
                            <x-icon name="o-academic-cap" class="w-16 h-16 text-gray-300" />
                            <div>
                                <h3 class="text-lg font-semibold text-gray-600">No subjects found</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    @if($search || $curriculum || $level)
                                        No subjects match your current filters.
                                    @else
                                        Get started by creating your first subject.
                                    @endif
                                </p>
                            </div>
                            @if($search || $curriculum || $level)
                                <x-button
                                    label="Clear Filters"
                                    wire:click="resetFilters"
                                    color="secondary"
                                    size="sm"
                                />
                            @else
                                <x-button
                                    label="Create First Subject"
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
                                <td class="font-mono text-sm">#{{ $subject->id }}</td>
                                <td>
                                    <div class="font-bold">{{ $subject->name }}</div>
                                    @if($subject->description)
                                        <div class="max-w-xs text-sm truncate opacity-70">
                                            {{ Str::limit($subject->description, 60) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2">
                                    @if(!empty($subject->code))
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 font-mono">
                                            {{ $subject->code }}
                                        </span>
                                    @else
                                        <span class="text-xs italic text-gray-400">No code</span>
                                    @endif
                                </td>
                                <td>
                                    @if($subject->curriculum)
                                        <a href="{{ route('admin.curricula.show', $subject->curriculum_id) }}" class="text-blue-600 underline hover:text-blue-800">
                                            {{ $subject->curriculum->name }}
                                        </a>
                                        @if($subject->curriculum->code)
                                            <div class="text-xs text-gray-500">{{ $subject->curriculum->code }}</div>
                                        @endif
                                    @else
                                        <span class="italic text-gray-400">Unknown curriculum</span>
                                    @endif
                                </td>
                                <td class="py-2">
                                    @if(!empty($subject->level))
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getLevelColor($subject->level) }}">
                                            {{ $subject->level }}
                                        </span>
                                    @else
                                        <span class="text-xs italic text-gray-400">No level</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex gap-1">
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-indigo-800 bg-indigo-100 rounded-full" title="Sessions">
                                            📅 {{ $subject->sessions_count ?? 0 }}
                                        </span>
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-orange-800 bg-orange-100 rounded-full" title="Exams">
                                            📝 {{ $subject->exams_count ?? 0 }}
                                        </span>
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-purple-800 bg-purple-100 rounded-full" title="Enrollments">
                                            👥 {{ $subject->subject_enrollments_count ?? 0 }}
                                        </span>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            wire:click="redirectToShow({{ $subject->id }})"
                                            class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                            title="View"
                                        >
                                            👁️
                                        </button>
                                        <button
                                            wire:click="redirectToEdit({{ $subject->id }})"
                                            class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                            title="Edit"
                                        >
                                            ✏️
                                        </button>
                                        <button
                                            wire:click="confirmDelete({{ $subject->id }})"
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
                                            <h3 class="text-lg font-semibold text-gray-600">No subjects found</h3>
                                            <p class="mt-1 text-gray-500">
                                                @if($search || $curriculum || $level)
                                                    No subjects match your current filters.
                                                @else
                                                    Get started by creating your first subject.
                                                @endif
                                            </p>
                                        </div>
                                        @if($search || $curriculum || $level)
                                            <x-button
                                                label="Clear Filters"
                                                wire:click="resetFilters"
                                                color="secondary"
                                                size="sm"
                                            />
                                        @else
                                            <x-button
                                                label="Create First Subject"
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
            {{ $subjects->links() }}
        </div>

        <!-- Results summary -->
        @if($subjects->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between sm:items-center">
                <span>
                    Showing {{ $subjects->firstItem() ?? 0 }} to {{ $subjects->lastItem() ?? 0 }}
                    of {{ $subjects->total() }} subjects
                    @if($search || $curriculum || $level)
                        (filtered from total)
                    @endif
                </span>
                <!-- Mobile view toggle (optional) -->
                <div class="flex items-center gap-2 lg:hidden">
                    <span class="text-xs text-gray-500">Card view active</span>
                    <x-icon name="o-squares-2x2" class="w-4 h-4 text-gray-400" />
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
