<?php

use App\Models\Curriculum;
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

new #[Title('Curricula Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'id', 'direction' => 'desc'];

    // Modal state
    public bool $showDeleteModal = false;
    public ?int $curriculumToDelete = null;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed curricula management page',
            Curriculum::class,
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
        $this->redirect(route('admin.curricula.create'));
    }

    // Redirect to show page
    public function redirectToShow(int $curriculumId): void
    {
        $this->redirect(route('admin.curricula.show', $curriculumId));
    }

    // Redirect to edit page
    public function redirectToEdit(int $curriculumId): void
    {
        $this->redirect(route('admin.curricula.edit', $curriculumId));
    }

    // Confirm deletion
    public function confirmDelete(int $curriculumId): void
    {
        $this->curriculumToDelete = $curriculumId;
        $this->showDeleteModal = true;
    }

    // Cancel deletion
    public function cancelDelete(): void
    {
        $this->curriculumToDelete = null;
        $this->showDeleteModal = false;
    }

    // Delete a curriculum
    public function deleteCurriculum(): void
    {
        if ($this->curriculumToDelete) {
            $curriculum = Curriculum::find($this->curriculumToDelete);

            if ($curriculum) {
                // Get data for logging before deletion
                $curriculumDetails = [
                    'id' => $curriculum->id,
                    'name' => $curriculum->name,
                    'code' => $curriculum->code
                ];

                try {
                    DB::beginTransaction();

                    // Check if curriculum has related records
                    $hasSubjects = $curriculum->subjects()->exists();
                    $hasEnrollments = $curriculum->programEnrollments()->exists();
                    $hasPaymentPlans = $curriculum->paymentPlans()->exists();

                    if ($hasSubjects || $hasEnrollments || $hasPaymentPlans) {
                        $this->error("Cannot delete curriculum. It has associated subjects, enrollments, or payment plans.");
                        DB::rollBack();
                        $this->showDeleteModal = false;
                        $this->curriculumToDelete = null;
                        return;
                    }

                    // Delete curriculum
                    $curriculum->delete();

                    // Log the action
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted curriculum: {$curriculumDetails['name']} ({$curriculumDetails['code']})",
                        Curriculum::class,
                        $this->curriculumToDelete,
                        [
                            'curriculum_name' => $curriculumDetails['name'],
                            'curriculum_code' => $curriculumDetails['code']
                        ]
                    );

                    DB::commit();

                    // Show toast notification
                    $this->success("Curriculum {$curriculumDetails['name']} has been successfully deleted.");

                    // Refresh the page data after successful deletion
                    $this->resetPage();
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("An error occurred during deletion: {$e->getMessage()}");
                }
            } else {
                $this->error("Curriculum not found!");
            }
        }

        $this->showDeleteModal = false;
        $this->curriculumToDelete = null;
    }

    // Get filtered and paginated curricula
    public function curricula(): LengthAwarePaginator
    {
        return Curriculum::query()
            ->select(['id', 'name', 'code', 'description', 'created_at', 'updated_at'])
            ->withCount(['subjects', 'programEnrollments']) // Fixed relationship name
            ->when($this->search, function (Builder $query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('code', 'like', '%' . $this->search . '%')
                    ->orWhere('description', 'like', '%' . $this->search . '%');
            })
            ->when($this->sortBy['column'], function (Builder $query) {
                $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
            })
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'curricula' => $this->curricula(),
        ];
    }
};?>

<div class="min-h-screen bg-gradient-to-br from-emerald-50 via-teal-50 to-green-50">
    <!-- Ultra-responsive page header with educational theme -->
    <x-header title="Curricula Management" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input
                placeholder="Search by name or code..."
                wire:model.live.debounce="search"
                icon="o-magnifying-glass"
                clearable
                class="w-full min-w-0 sm:w-48 md:w-64 lg:w-80 xl:w-96"
            />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                @click="$wire.showFilters = true"
                class="text-xs bg-base-300 sm:text-sm"
                responsive
            />

            <x-button
                label="New Curriculum"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="text-xs btn-primary sm:text-sm"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Adaptive container with responsive padding -->
    <div class="container px-2 mx-auto space-y-4 sm:px-4 md:px-6 lg:px-8 sm:space-y-6 lg:space-y-8">

        <!-- Ultra-responsive Mobile Card View with educational design -->
        <div class="block space-y-3 lg:hidden sm:space-y-4">
            @forelse($curricula as $curriculum)
                <x-card class="overflow-hidden transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1">
                    <div class="p-3 sm:p-4">
                        <!-- Enhanced Curriculum Info Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-start flex-1 min-w-0">
                                <div class="mr-3 transition-transform duration-300 avatar group-hover:scale-110">
                                    <div class="w-10 h-10 transition-all duration-300 rounded-lg bg-gradient-to-br from-emerald-100 to-teal-100 ring-2 ring-transparent group-hover:ring-emerald-200 sm:w-12 sm:h-12">
                                        <div class="flex items-center justify-center w-full h-full text-sm font-bold text-emerald-600">
                                            📚
                                        </div>
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="px-2 py-1 font-mono text-xs text-gray-500 bg-gray-100 rounded">#{{ $curriculum->id }}</span>
                                        @if(!empty($curriculum->code))
                                            <span class="inline-flex items-center px-2 py-1 font-mono text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                                                {{ $curriculum->code }}
                                            </span>
                                        @endif
                                    </div>
                                    <h3 class="text-sm font-bold transition-colors text-emerald-800 sm:text-base group-hover:text-emerald-900">
                                        {{ $curriculum->name }}
                                    </h3>
                                    @if($curriculum->description)
                                        <p class="mt-1 text-xs text-gray-600 line-clamp-2">
                                            {{ Str::limit($curriculum->description, 80) }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex gap-1 ml-2">
                                <button
                                    wire:click="redirectToShow({{ $curriculum->id }})"
                                    class="p-1.5 sm:p-2 text-gray-600 bg-gray-100 rounded-lg hover:text-gray-900 hover:bg-gray-200 hover:scale-110 transition-all duration-200"
                                    title="View"
                                >
                                    <span class="text-sm">👁️</span>
                                </button>
                                <button
                                    wire:click="redirectToEdit({{ $curriculum->id }})"
                                    class="p-1.5 sm:p-2 text-emerald-600 bg-emerald-100 rounded-lg hover:text-emerald-900 hover:bg-emerald-200 hover:scale-110 transition-all duration-200"
                                    title="Edit"
                                >
                                    <span class="text-sm">✏️</span>
                                </button>
                                <button
                                    wire:click="confirmDelete({{ $curriculum->id }})"
                                    class="p-1.5 sm:p-2 text-red-600 bg-red-100 rounded-lg hover:text-red-900 hover:bg-red-200 hover:scale-110 transition-all duration-200"
                                    title="Delete"
                                >
                                    <span class="text-sm">🗑️</span>
                                </button>
                            </div>
                        </div>

                        <!-- Enhanced Curriculum Details with educational metrics -->
                        <div class="space-y-3">
                            <!-- Subjects and Enrollments Row -->
                            <div class="grid grid-cols-2 gap-3">
                                <!-- Subjects Count -->
                                <div class="p-3 rounded-lg bg-gradient-to-br from-green-50 to-emerald-50">
                                    <div class="flex items-center gap-2 mb-1">
                                        <x-icon name="o-book-open" class="w-4 h-4 text-green-600" />
                                        <span class="text-xs font-medium text-green-600">Subjects</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-lg font-bold text-green-800">{{ $curriculum->subjects_count ?? 0 }}</span>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ ($curriculum->subjects_count ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                            {{ ($curriculum->subjects_count ?? 0) > 0 ? 'Active' : 'None' }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Enrollments Count -->
                                <div class="p-3 rounded-lg bg-gradient-to-br from-purple-50 to-violet-50">
                                    <div class="flex items-center gap-2 mb-1">
                                        <x-icon name="o-user-group" class="w-4 h-4 text-purple-600" />
                                        <span class="text-xs font-medium text-purple-600">Students</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-lg font-bold text-purple-800">{{ $curriculum->program_enrollments_count ?? 0 }}</span>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ ($curriculum->program_enrollments_count ?? 0) > 0 ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-600' }}">
                                            {{ ($curriculum->program_enrollments_count ?? 0) > 0 ? 'Enrolled' : 'None' }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Curriculum Status Indicator -->
                            @if(($curriculum->subjects_count ?? 0) > 0 && ($curriculum->program_enrollments_count ?? 0) > 0)
                                <div class="p-2 rounded-lg bg-gradient-to-r from-emerald-50 to-teal-50">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                                        <span class="text-xs font-medium text-emerald-800">Active Curriculum</span>
                                        <span class="text-xs text-emerald-600">• Ready for Teaching</span>
                                    </div>
                                </div>
                            @elseif(($curriculum->subjects_count ?? 0) > 0)
                                <div class="p-2 rounded-lg bg-gradient-to-r from-yellow-50 to-amber-50">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
                                        <span class="text-xs font-medium text-amber-800">Ready for Enrollment</span>
                                        <span class="text-xs text-amber-600">• Has Subjects</span>
                                    </div>
                                </div>
                            @else
                                <div class="p-2 rounded-lg bg-gradient-to-r from-gray-50 to-slate-50">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
                                        <span class="text-xs font-medium text-gray-600">Setup Required</span>
                                        <span class="text-xs text-gray-500">• Add Subjects</span>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-card>
            @empty
                <x-card class="border-2 border-dashed border-emerald-200">
                    <div class="py-8 text-center sm:py-12">
                        <div class="flex flex-col items-center justify-center gap-3 sm:gap-4">
                            <div class="p-4 rounded-full bg-gradient-to-br from-emerald-100 to-teal-100">
                                <x-icon name="o-academic-cap" class="w-12 h-12 text-emerald-400 sm:w-16 sm:h-16" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-600 sm:text-lg">No curricula found</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    @if($search)
                                        No curricula match your search criteria.
                                    @else
                                        Get started by creating your first curriculum.
                                    @endif
                                </p>
                            </div>
                            @if($search)
                                <x-button
                                    label="Clear Search"
                                    wire:click="resetFilters"
                                    color="secondary"
                                    size="sm"
                                    class="transition-transform hover:scale-105"
                                />
                            @else
                                <x-button
                                    label="Create First Curriculum"
                                    icon="o-plus"
                                    wire:click="redirectToCreate"
                                    color="primary"
                                    class="transition-transform hover:scale-105"
                                />
                            @endif
                        </div>
                    </div>
                </x-card>
            @endforelse
        </div>

        <!-- Enhanced Desktop Table View -->
        <x-card class="hidden overflow-hidden border-0 shadow-lg lg:block">
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead class="bg-gradient-to-r from-gray-50 to-emerald-50">
                        <tr>
                            <th class="transition-colors cursor-pointer hover:bg-emerald-100" wire:click="sortBy('id')">
                                <div class="flex items-center">
                                    ID
                                    @if ($sortBy['column'] === 'id')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-emerald-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="transition-colors cursor-pointer hover:bg-emerald-100" wire:click="sortBy('name')">
                                <div class="flex items-center">
                                    Name
                                    @if ($sortBy['column'] === 'name')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-emerald-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="transition-colors cursor-pointer hover:bg-emerald-100" wire:click="sortBy('code')">
                                <div class="flex items-center">
                                    Code
                                    @if ($sortBy['column'] === 'code')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-emerald-600" />
                                    @endif
                                </div>
                            </th>
                            <th>Subjects</th>
                            <th>Enrollments</th>
                            <th>Status</th>
                            <th class="w-32 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($curricula as $curriculum)
                            <tr class="transition-colors hover:bg-emerald-50 group">
                                <td class="px-3 py-2 font-mono text-sm rounded bg-gray-50">#{{ $curriculum->id }}</td>
                                <td class="py-2">
                                    <div class="font-bold transition-colors text-emerald-800 group-hover:text-emerald-900">{{ $curriculum->name }}</div>
                                    @if($curriculum->description)
                                        <div class="max-w-xs text-sm text-gray-600 truncate">
                                            {{ Str::limit($curriculum->description, 60) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2">
                                    @if(!empty($curriculum->code))
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 font-mono hover:scale-105 transition-transform">
                                            {{ $curriculum->code }}
                                        </span>
                                    @else
                                        <span class="px-2 py-1 text-xs italic text-gray-400 rounded bg-gray-50">No code</span>
                                    @endif
                                </td>
                                <td class="py-2">
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-book-open" class="w-4 h-4 text-green-600" />
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ ($curriculum->subjects_count ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }} hover:scale-105 transition-transform">
                                            {{ $curriculum->subjects_count ?? 0 }}
                                        </span>
                                    </div>
                                </td>
                                <td class="py-2">
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-user-group" class="w-4 h-4 text-purple-600" />
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ ($curriculum->program_enrollments_count ?? 0) > 0 ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-600' }} hover:scale-105 transition-transform">
                                            {{ $curriculum->program_enrollments_count ?? 0 }}
                                        </span>
                                    </div>
                                </td>
                                <td class="py-2">
                                    @if(($curriculum->subjects_count ?? 0) > 0 && ($curriculum->program_enrollments_count ?? 0) > 0)
                                        <div class="flex items-center gap-2">
                                            <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                                            <span class="px-2 py-1 text-xs font-medium rounded text-emerald-800 bg-emerald-50">Active</span>
                                        </div>
                                    @elseif(($curriculum->subjects_count ?? 0) > 0)
                                        <div class="flex items-center gap-2">
                                            <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
                                            <span class="px-2 py-1 text-xs font-medium rounded text-amber-800 bg-amber-50">Ready</span>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-2">
                                            <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
                                            <span class="px-2 py-1 text-xs font-medium text-gray-600 rounded bg-gray-50">Setup</span>
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            wire:click="redirectToShow({{ $curriculum->id }})"
                                            class="p-2 text-gray-600 transition-all duration-200 bg-gray-100 rounded-lg hover:text-gray-900 hover:bg-gray-200 hover:scale-110"
                                            title="View"
                                        >
                                            👁️
                                        </button>
                                        <button
                                            wire:click="redirectToEdit({{ $curriculum->id }})"
                                            class="p-2 transition-all duration-200 rounded-lg text-emerald-600 bg-emerald-100 hover:text-emerald-900 hover:bg-emerald-200 hover:scale-110"
                                            title="Edit"
                                        >
                                            ✏️
                                        </button>
                                        <button
                                            wire:click="confirmDelete({{ $curriculum->id }})"
                                            class="p-2 text-red-600 transition-all duration-200 bg-red-100 rounded-lg hover:text-red-900 hover:bg-red-200 hover:scale-110"
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
                                        <div class="p-4 rounded-full bg-gradient-to-br from-emerald-100 to-teal-100">
                                            <x-icon name="o-academic-cap" class="w-20 h-20 text-emerald-300" />
                                        </div>
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-600">No curricula found</h3>
                                            <p class="mt-1 text-gray-500">
                                                @if($search)
                                                    No curricula match your search criteria.
                                                @else
                                                    Get started by creating your first curriculum.
                                                @endif
                                            </p>
                                        </div>
                                        @if($search)
                                            <x-button
                                                label="Clear Search"
                                                wire:click="resetFilters"
                                                color="secondary"
                                                size="sm"
                                                class="transition-transform hover:scale-105"
                                            />
                                        @else
                                            <x-button
                                                label="Create First Curriculum"
                                                icon="o-plus"
                                                wire:click="redirectToCreate"
                                                color="primary"
                                                class="transition-transform hover:scale-105"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Enhanced Pagination -->
            <div class="p-4 mt-4 bg-gradient-to-r from-gray-50 to-emerald-50">
                {{ $curricula->links() }}
            </div>

            <!-- Enhanced Results summary -->
            @if($curricula->count() > 0)
            <div class="px-4 pt-3 pb-4 mt-4 text-sm text-gray-600 border-t bg-gradient-to-r from-gray-50 to-emerald-50">
                <div class="flex items-center justify-between">
                    <span>
                        Showing {{ $curricula->firstItem() ?? 0 }} to {{ $curricula->lastItem() ?? 0 }}
                        of {{ $curricula->total() }} curricula
                        @if($search)
                            (filtered from total)
                        @endif
                    </span>
                    <div class="text-xs text-gray-500">
                        Page {{ $curricula->currentPage() }} of {{ $curricula->lastPage() }}
                    </div>
                </div>
            </div>
            @endif
        </x-card>

        <!-- Enhanced Mobile/Tablet Pagination -->
        <div class="lg:hidden">
            {{ $curricula->links() }}
        </div>

        <!-- Enhanced Mobile Results Summary -->
        @if($curricula->count() > 0)
        <div class="p-4 pt-3 text-sm text-center text-gray-600 bg-white border-t rounded-lg shadow-sm lg:hidden">
            <div class="space-y-1">
                <div>
                    Showing {{ $curricula->firstItem() ?? 0 }} to {{ $curricula->lastItem() ?? 0 }}
                    of {{ $curricula->total() }} curricula
                </div>
                @if($search)
                    <div class="text-xs text-emerald-600">(filtered results)</div>
                @endif
                <div class="text-xs text-gray-500">
                    Page {{ $curricula->currentPage() }} of {{ $curricula->lastPage() }}
                </div>
            </div>
        </div>
        @endif
    </div>

    <!-- Enhanced Delete confirmation modal -->
    <x-modal wire:model="showDeleteModal" title="Delete Confirmation" class="max-w-md">
        <div class="p-4">
            <div class="flex items-center gap-4">
                <div class="p-3 bg-red-100 rounded-full animate-pulse">
                    <x-icon name="o-exclamation-triangle" class="w-8 h-8 text-red-600" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-gray-900">Delete Curriculum?</h3>
                    <p class="mt-1 text-sm text-gray-600">This action cannot be undone. Curricula with associated subjects, enrollments, or payment plans cannot be deleted.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button
                label="Cancel"
                wire:click="cancelDelete"
                class="transition-transform hover:scale-105"
            />
            <x-button
                label="Delete"
                icon="o-trash"
                wire:click="deleteCurriculum"
                color="error"
                class="transition-transform hover:scale-105"
            />
        </x-slot:actions>
    </x-modal>

    <!-- Enhanced Responsive Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by name or code"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                    class="w-full"
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
                    class="w-full"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button
                label="Reset"
                icon="o-x-mark"
                wire:click="resetFilters"
                class="w-full mb-2 transition-transform sm:w-auto sm:mb-0 hover:scale-105"
            />
            <x-button
                label="Apply"
                icon="o-check"
                wire:click="$set('showFilters', false)"
                color="primary"
                class="w-full transition-transform sm:w-auto hover:scale-105"
            />
        </x-slot:actions>
    </x-drawer>

    <!-- Enhanced Floating Action Button with educational theme -->
    <div class="fixed z-50 bottom-4 right-4 lg:hidden">
        <div class="flex flex-col gap-3">
            <!-- Primary Action -->
            <button
                wire:click="redirectToCreate"
                class="relative flex items-center justify-center text-white transition-all duration-300 transform rounded-full shadow-lg group w-14 h-14 bg-gradient-to-r from-emerald-600 to-teal-600 hover:shadow-xl hover:scale-110 animate-bounce-slow"
                title="New Curriculum"
            >
                <x-icon name="o-plus" class="w-6 h-6 transition-transform duration-300 group-hover:rotate-90" />

                <!-- Ripple effect -->
                <div class="absolute inset-0 transition-all duration-300 bg-white rounded-full opacity-0 group-hover:opacity-20 group-hover:scale-150"></div>
            </button>

            <!-- Secondary Action -->
            <button
                @click="$wire.showFilters = true"
                class="relative flex items-center justify-center w-12 h-12 text-white transition-all duration-300 transform rounded-full shadow-lg group bg-gradient-to-r from-gray-600 to-gray-700 hover:shadow-xl hover:scale-110"
                title="Open Filters"
            >
                <x-icon name="o-funnel" class="w-5 h-5 transition-transform duration-300 group-hover:rotate-12" />
            </button>
        </div>
    </div>

    <!-- Enhanced Mobile Navigation Helper -->
    <div class="block mt-6 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-emerald-50 to-teal-50">
            <div class="p-4">
                <h3 class="flex items-center mb-3 text-sm font-bold text-gray-800">
                    <x-icon name="o-academic-cap" class="w-4 h-4 mr-2 text-emerald-600" />
                    Curriculum Management
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    <button
                        wire:click="redirectToCreate"
                        class="flex items-center justify-center p-3 text-xs font-medium transition-all duration-300 group text-emerald-800 bg-gradient-to-r from-emerald-100 to-emerald-200 rounded-xl hover:from-emerald-200 hover:to-emerald-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-plus" class="w-4 h-4 mr-2 transition-transform duration-300 group-hover:rotate-90" />
                        New Curriculum
                    </button>
                    <button
                        @click="$wire.showFilters = true"
                        class="flex items-center justify-center p-3 text-xs font-medium text-teal-800 transition-all duration-300 group bg-gradient-to-r from-teal-100 to-teal-200 rounded-xl hover:from-teal-200 hover:to-teal-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-funnel" class="w-4 h-4 mr-2 transition-transform duration-300 group-hover:rotate-12" />
                        Search & Filter
                    </button>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Educational Information Guide (mobile only) -->
    <div class="mt-4 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-teal-50 to-emerald-50">
            <div class="p-4">
                <div class="flex items-start">
                    <div class="p-2 mr-3 bg-teal-100 rounded-full">
                        <x-icon name="o-book-open" class="w-5 h-5 text-teal-600" />
                    </div>
                    <div class="flex-1">
                        <h4 class="mb-2 font-semibold text-teal-800">Curriculum Status Guide</h4>
                        <div class="space-y-2">
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                                    <span class="text-xs font-medium text-emerald-800">Active</span>
                                </div>
                                <span class="text-sm text-gray-700">Has subjects and enrolled students</span>
                            </div>
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
                                    <span class="text-xs font-medium text-amber-800">Ready</span>
                                </div>
                                <span class="text-sm text-gray-700">Has subjects, ready for enrollment</span>
                            </div>
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
                                    <span class="text-xs font-medium text-gray-600">Setup</span>
                                </div>
                                <span class="text-sm text-gray-700">Needs subjects to be added</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Curriculum Statistics (Hidden on mobile, collapsible on tablet) -->
    <div class="hidden mt-6 sm:block">
        <details class="group sm:hidden md:block">
            <summary class="flex items-center justify-between p-4 font-medium text-gray-900 border rounded-lg cursor-pointer bg-gradient-to-r from-emerald-50 to-teal-50 border-emerald-200 hover:from-emerald-100 hover:to-teal-100 sm:hidden">
                <span class="flex items-center">
                    <x-icon name="o-chart-bar" class="w-5 h-5 mr-2 text-emerald-600" />
                    Curriculum Overview
                </span>
                <svg class="w-5 h-5 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>
            <div class="mt-2 sm:mt-0">
                <x-card class="border-0 shadow-lg bg-gradient-to-r from-emerald-50 to-teal-50">
                    <div class="p-4 md:p-6">
                        <h3 class="flex items-center mb-4 text-lg font-bold text-gray-800">
                            <x-icon name="o-chart-bar" class="w-5 h-5 mr-2 text-emerald-600" />
                            Curriculum Statistics
                        </h3>
                        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div class="p-3 text-center bg-white border rounded-lg shadow-sm border-emerald-100">
                                <div class="text-2xl font-bold text-emerald-600">
                                    {{ $curricula->where('subjects_count', '>', 0)->where('program_enrollments_count', '>', 0)->count() }}
                                </div>
                                <div class="text-xs text-gray-600">Active Curricula</div>
                            </div>
                            <div class="p-3 text-center bg-white border border-teal-100 rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-teal-600">
                                    {{ $curricula->where('subjects_count', '>', 0)->count() }}
                                </div>
                                <div class="text-xs text-gray-600">With Subjects</div>
                            </div>
                            <div class="p-3 text-center bg-white border border-purple-100 rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-purple-600">
                                    {{ $curricula->sum('program_enrollments_count') }}
                                </div>
                                <div class="text-xs text-gray-600">Total Enrollments</div>
                            </div>
                            <div class="p-3 text-center bg-white border border-green-100 rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-green-600">
                                    {{ $curricula->sum('subjects_count') }}
                                </div>
                                <div class="text-xs text-gray-600">Total Subjects</div>
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>
        </details>
    </div>

    <!-- Advanced Search Tips (Mobile) -->
    @if($search)
    <div class="mt-4 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-blue-50 to-indigo-50">
            <div class="p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h4 class="flex items-center mb-2 font-semibold text-blue-800">
                            <x-icon name="o-magnifying-glass" class="w-4 h-4 mr-2" />
                            Search Results
                        </h4>
                        <div class="text-sm text-blue-700">
                            <strong>Searching for:</strong> "{{ $search }}"
                        </div>
                        <div class="mt-1 text-xs text-blue-600">
                            Search includes curriculum names and codes
                        </div>
                    </div>
                    <button
                        wire:click="resetFilters"
                        class="px-3 py-1 text-xs font-medium text-red-800 transition-colors bg-red-100 rounded-full hover:bg-red-200"
                        title="Clear search"
                    >
                        Clear
                    </button>
                </div>
            </div>
        </x-card>
    </div>
    @endif

    <!-- Curriculum Management Tips (Mobile) -->
    <div class="mt-4 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-indigo-50 to-purple-50">
            <div class="p-4">
                <div class="flex items-start">
                    <div class="p-2 mr-3 bg-indigo-100 rounded-full">
                        <x-icon name="o-light-bulb" class="w-5 h-5 text-indigo-600" />
                    </div>
                    <div class="flex-1">
                        <h4 class="mb-2 font-semibold text-indigo-800">Management Tips</h4>
                        <div class="space-y-2">
                            <div class="text-sm text-indigo-700">
                                📚 <strong>Add Subjects:</strong> Create subjects for each curriculum to enable enrollments
                            </div>
                            <div class="text-sm text-indigo-700">
                                🎯 <strong>Curriculum Codes:</strong> Use short, memorable codes for easy identification
                            </div>
                            <div class="text-sm text-indigo-700">
                                👥 <strong>Student Enrollment:</strong> Monitor enrollment numbers to track popularity
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Quick Actions Summary (Mobile) -->
    <div class="mt-4 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-gray-50 to-slate-50">
            <div class="p-4">
                <h4 class="flex items-center mb-3 font-semibold text-gray-800">
                    <x-icon name="o-bolt" class="w-4 h-4 mr-2 text-gray-600" />
                    Quick Actions
                </h4>
                <div class="grid grid-cols-1 gap-2">
                    <button
                        wire:click="redirectToCreate"
                        class="flex items-center p-3 text-sm text-left transition-colors rounded-lg text-emerald-800 bg-emerald-50 hover:bg-emerald-100"
                    >
                        <x-icon name="o-plus" class="w-4 h-4 mr-3 text-emerald-600" />
                        <div>
                            <div class="font-medium">Create New Curriculum</div>
                            <div class="text-xs text-emerald-600">Add a new educational program</div>
                        </div>
                    </button>
                    <button
                        @click="$wire.showFilters = true"
                        class="flex items-center p-3 text-sm text-left text-teal-800 transition-colors rounded-lg bg-teal-50 hover:bg-teal-100"
                    >
                        <x-icon name="o-funnel" class="w-4 h-4 mr-3 text-teal-600" />
                        <div>
                            <div class="font-medium">Search & Filter</div>
                            <div class="text-xs text-teal-600">Find specific curricula quickly</div>
                        </div>
                    </button>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Loading States and Animations -->

</div>

<style>
/* Educational theme animations */
@keyframes bounce-slow {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}

.animate-bounce-slow {
    animation: bounce-slow 3s ease-in-out infinite;
}

@keyframes fade-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-fade-in {
    animation: fade-in 0.3s ease-out;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.group-hover\:pulse:hover {
    animation: pulse 1s ease-in-out infinite;
}

/* Enhanced hover effects */
.hover-lift:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}

/* Smooth transitions for all interactive elements */
* {
    transition-property: transform, box-shadow, background-color, border-color;
    transition-duration: 200ms;
    transition-timing-function: ease-in-out;
}

/* Educational focus states */
button:focus,
input:focus,
select:focus {
    outline: 2px solid #059669;
    outline-offset: 2px;
}

/* Mobile-optimized touch targets */
@media (max-width: 768px) {
    button, .clickable {
        min-height: 44px;
        min-width: 44px;
    }
}

/* Responsive text scaling */
@media (max-width: 640px) {
    .responsive-text {
        font-size: 0.875rem;
    }
}

@media (min-width: 640px) {
    .responsive-text {
        font-size: 1rem;
    }
}

@media (min-width: 1024px) {
    .responsive-text {
        font-size: 1.125rem;
    }
}

/* Custom gradients for curriculum theme */
.bg-curriculum-gradient {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 50%, #6ee7b7 100%);
}

.text-curriculum-primary {
    color: #059669;
}

.border-curriculum-primary {
    border-color: #059669;
}

/* Status indicators */
.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.25rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-active {
    background-color: #dcfce7;
    color: #166534;
}

.status-ready {
    background-color: #fef3c7;
    color: #92400e;
}

.status-setup {
    background-color: #f3f4f6;
    color: #374151;
}

/* Text truncation utilities */
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Educational icons */
.curriculum-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    font-size: 1rem;
}
</style>
