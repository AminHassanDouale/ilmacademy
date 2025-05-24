<?php

use App\Models\ProgramEnrollment;
use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Program Enrollments')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $child = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'enrollment_date', 'direction' => 'desc'];

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Parent accessed enrollments page',
            ProgramEnrollment::class,
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

    // Get filtered and paginated enrollments for all children of the current parent
    public function enrollments(): LengthAwarePaginator
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        // Get all children IDs for this parent
        $childrenIds = ChildProfile::where('parent_profile_id', $parentProfile->id)
            ->pluck('id')
            ->toArray();

        if (empty($childrenIds)) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        return ProgramEnrollment::query()
            ->whereIn('child_profile_id', $childrenIds)
            ->with(['childProfile.user', 'program'])
            ->when($this->search, function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->whereHas('program', function (Builder $q) {
                        $q->where('name', 'like', '%' . $this->search . '%')
                            ->orWhere('description', 'like', '%' . $this->search . '%');
                    })
                    ->orWhereHas('childProfile.user', function (Builder $q) {
                        $q->where('name', 'like', '%' . $this->search . '%');
                    });
                });
            })
            ->when($this->status, function (Builder $query) {
                $query->where('status', $this->status);
            })
            ->when($this->child, function (Builder $query) {
                $query->where('child_profile_id', $this->child);
            })
            ->when($this->sortBy['column'] === 'program', function (Builder $query) {
                $query->join('programs', 'program_enrollments.program_id', '=', 'programs.id')
                    ->orderBy('programs.name', $this->sortBy['direction'])
                    ->select('program_enrollments.*');
            }, function (Builder $query) {
                if ($this->sortBy['column'] === 'child') {
                    $query->join('child_profiles', 'program_enrollments.child_profile_id', '=', 'child_profiles.id')
                        ->join('users', 'child_profiles.user_id', '=', 'users.id')
                        ->orderBy('users.name', $this->sortBy['direction'])
                        ->select('program_enrollments.*');
                } else {
                    $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
                }
            })
            ->paginate($this->perPage);
    }

    // Get children for filter
    public function children()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return collect();
        }

        return ChildProfile::where('parent_profile_id', $parentProfile->id)
            ->with('user')
            ->get()
            ->map(function ($child) {
                return [
                    'id' => $child->id,
                    'name' => $child->user?->name ?? 'Unknown'
                ];
            });
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->child = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'enrollments' => $this->enrollments(),
            'children' => $this->children(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Program Enrollments" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search programs or children..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$status, $child]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="Browse Programs"
                icon="o-academic-cap"

                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Enrollments table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('program')">
                            <div class="flex items-center">
                                Program
                                @if ($sortBy['column'] === 'program')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('child')">
                            <div class="flex items-center">
                                Child
                                @if ($sortBy['column'] === 'child')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('enrollment_date')">
                            <div class="flex items-center">
                                Enrollment Date
                                @if ($sortBy['column'] === 'enrollment_date')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('status')">
                            <div class="flex items-center">
                                Status
                                @if ($sortBy['column'] === 'status')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('progress')">
                            <div class="flex items-center">
                                Progress
                                @if ($sortBy['column'] === 'progress')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($enrollments as $enrollment)
                        <tr class="hover">
                            <td>
                                <div class="font-bold">{{ $enrollment->program->name ?? 'Unknown Program' }}</div>
                                <div class="max-w-xs text-sm truncate opacity-70">{{ $enrollment->program->description ?? '' }}</div>
                            </td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar">
                                        <div class="w-10 h-10 mask mask-squircle">
                                            @if ($enrollment->childProfile->photo)
                                                <img src="{{ asset('storage/' . $enrollment->childProfile->photo) }}" alt="{{ $enrollment->childProfile->user?->name ?? 'Child' }}">
                                            @else
                                                <img src="{{ $enrollment->childProfile->user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $enrollment->childProfile->user?->name ?? 'Child' }}">
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        {{ $enrollment->childProfile->user?->name ?? 'Unknown Child' }}
                                    </div>
                                </div>
                            </td>
                            <td>{{ $enrollment->enrollment_date?->format('d/m/Y') ?? 'Not set' }}</td>
                            <td>
                                <x-badge
                                    label="{{ ucfirst($enrollment->status ?? 'Unknown') }}"
                                    color="{{ match($enrollment->status ?? '') {
                                        'active' => 'success',
                                        'pending' => 'warning',
                                        'completed' => 'info',
                                        'cancelled' => 'error',
                                        default => 'ghost'
                                    } }}"
                                />
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1">
                                        <progress class="w-full progress progress-primary" value="{{ $enrollment->progress ?? 0 }}" max="100"></progress>
                                    </div>
                                    <div class="text-sm font-medium">
                                        {{ $enrollment->progress ?? 0 }}%
                                    </div>
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        link="/parent/children/{{ $enrollment->child_profile_id }}/programs/{{ $enrollment->id }}"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View Details"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-academic-cap" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No enrollments found</h3>
                                    <p class="text-gray-500">No program enrollments match your filters, or your children are not enrolled in any programs yet</p>
                                    <x-button label="Browse Available Programs" icon="o-book-open"  class="mt-2" />
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $enrollments->links() }}
        </div>
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search programs or children"
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
                        ['label' => 'Pending', 'value' => 'pending'],
                        ['label' => 'Completed', 'value' => 'completed'],
                        ['label' => 'Cancelled', 'value' => 'cancelled']
                    ]"
                    wire:model.live="status"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Filter by child"
                    placeholder="All children"
                    :options="$children"
                    wire:model.live="child"
                    option-label="name"
                    option-value="id"
                    empty-message="No children found"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[10, 25, 50]"
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
