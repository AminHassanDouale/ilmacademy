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

<div>
    <!-- Page header -->
    <x-header title="Curricula Management" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search by name or code..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="New Curriculum"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Curricula table -->
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
                        <th>Subjects</th>
                        <th>Enrollments</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($curricula as $curriculum)
                        <tr class="hover">
                            <td class="font-mono text-sm">#{{ $curriculum->id }}</td>
                            <td>
                                <div class="font-bold">{{ $curriculum->name }}</div>
                                @if($curriculum->description)
                                    <div class="max-w-xs text-sm truncate opacity-70">
                                        {{ Str::limit($curriculum->description, 60) }}
                                    </div>
                                @endif
                            </td>
                            <td class="py-2">
                                @if(!empty($curriculum->code))
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 font-mono">
                                        {{ $curriculum->code }}
                                    </span>
                                @else
                                    <span class="text-xs italic text-gray-400">No code</span>
                                @endif
                            </td>
                            <td class="py-2">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ ($curriculum->subjects_count ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $curriculum->subjects_count ?? 0 }}
                                </span>
                            </td>
                            <td class="py-2">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ ($curriculum->program_enrollments_count ?? 0) > 0 ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $curriculum->program_enrollments_count ?? 0 }}
                                </span>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToShow({{ $curriculum->id }})"
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View"
                                    >
                                        👁️
                                    </button>
                                    <button
                                        wire:click="redirectToEdit({{ $curriculum->id }})"
                                        class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                        title="Edit"
                                    >
                                        ✏️
                                    </button>
                                    <button
                                        wire:click="confirmDelete({{ $curriculum->id }})"
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
                            <td colspan="6" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-academic-cap" class="w-20 h-20 text-gray-300" />
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
                                        />
                                    @else
                                        <x-button
                                            label="Create First Curriculum"
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
            {{ $curricula->links() }}
        </div>

        <!-- Results summary -->
        @if($curricula->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $curricula->firstItem() ?? 0 }} to {{ $curricula->lastItem() ?? 0 }}
            of {{ $curricula->total() }} curricula
            @if($search)
                (filtered from total)
            @endif
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
                    <h3 class="text-lg font-semibold">Are you sure you want to delete this curriculum?</h3>
                    <p class="text-gray-600">This action cannot be undone. Curricula with associated subjects, enrollments, or payment plans cannot be deleted.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancelDelete" />
            <x-button label="Delete" icon="o-trash" wire:click="deleteCurriculum" color="error" />
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
