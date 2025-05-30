<?php

use App\Models\TeacherProfile;
use App\Models\Session;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Teacher Sessions')] class extends Component {
    use WithPagination;
    use Toast;

    // Teacher to display sessions for
    public TeacherProfile $teacher;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $dateRange = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public array $sortBy = ['column' => 'start_time', 'direction' => 'desc'];

    #[Url]
    public bool $showFilters = false;

    // Component initialization - FIXED: parameter name should match route parameter
    public function mount(TeacherProfile $teacherProfile): void
    {
        $this->teacher = $teacherProfile; // FIXED: was $this->teacher = $teacherProfile;

        // Log access to sessions page
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'access',
            'description' => "Viewed sessions for teacher: " . ($teacherProfile->user ? $teacherProfile->user->name : 'Unknown'),
            'loggable_type' => TeacherProfile::class,
            'loggable_id' => $teacherProfile->id,
            'ip_address' => request()->ip(),
        ]);
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

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->dateRange = '';
        $this->resetPage();
    }

    // Go back to teacher profile
    public function backToProfile(): void
    {
        redirect()->route('admin.teachers.show', $this->teacher);
    }

    // Get filtered and paginated sessions
    public function sessions(): LengthAwarePaginator
    {
        // Check if the teacher has sessions relationship
        if (!method_exists($this->teacher, 'sessions')) {
            // If no sessions relationship, return empty paginator
            return new LengthAwarePaginator(
                collect([]),
                0,
                $this->perPage,
                request()->get('page', 1),
                ['path' => request()->url(), 'pageName' => 'page']
            );
        }

        return $this->teacher->sessions()
            ->when($this->search, function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->where('title', 'like', '%' . $this->search . '%')
                      ->orWhere('description', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->dateRange, function (Builder $query) {
                // Parse date range if present
                if ($this->dateRange === 'today') {
                    $query->whereDate('start_time', now()->toDateString());
                } elseif ($this->dateRange === 'upcoming') {
                    $query->where('start_time', '>', now());
                } elseif ($this->dateRange === 'past') {
                    $query->where('start_time', '<', now());
                } elseif ($this->dateRange === 'week') {
                    $query->whereBetween('start_time', [
                        now()->startOfWeek(),
                        now()->endOfWeek()
                    ]);
                } elseif ($this->dateRange === 'month') {
                    $query->whereBetween('start_time', [
                        now()->startOfMonth(),
                        now()->endOfMonth()
                    ]);
                }
            })
            ->when($this->sortBy['column'], function (Builder $query) {
                // Only apply sort if column exists in the table
                $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
            }, function (Builder $query) {
                // Default sorting
                $query->latest('start_time');
            })
            ->paginate($this->perPage);
    }

    // Get session counts for stats
    public function getSessionStats()
    {
        // Check if the teacher has sessions relationship
        if (!method_exists($this->teacher, 'sessions')) {
            return [
                'total' => 0,
                'upcoming' => 0,
                'past' => 0
            ];
        }

        $total = $this->teacher->sessions()->count();
        $upcoming = $this->teacher->sessions()->where('start_time', '>', now())->count();
        $past = $this->teacher->sessions()->where('start_time', '<', now())->count();

        return [
            'total' => $total,
            'upcoming' => $upcoming,
            'past' => $past
        ];
    }

    public function with(): array
    {
        return [
            'sessions' => $this->sessions(),
            'sessionStats' => $this->getSessionStats()
        ];
    }
};?>

<div>
    <x-header title="Sessions for {{ $teacher->user ? $teacher->user->name : 'Unknown Teacher' }}" separator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <x-slot:actions>
            <div class="flex gap-2">
                <x-button
                    label="Filters"
                    icon="o-funnel"
                    :badge="count(array_filter([$dateRange]))"
                    badge-classes="font-mono"
                    @click="$wire.showFilters = true"
                    class="bg-base-300"
                    responsive />

                <x-button
                    label="Back to Profile"
                    icon="o-arrow-left"
                    wire:click="backToProfile"
                    class="btn-ghost"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-3">
        <x-card class="bg-base-100">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold">{{ $sessionStats['total'] }}</h3>
                    <p class="text-sm opacity-70">Total Sessions</p>
                </div>
                <div class="p-3 rounded-full bg-primary/10">
                    <x-icon name="o-calendar" class="w-6 h-6 text-primary" />
                </div>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold">{{ $sessionStats['upcoming'] }}</h3>
                    <p class="text-sm opacity-70">Upcoming Sessions</p>
                </div>
                <div class="p-3 rounded-full bg-success/10">
                    <x-icon name="o-clock" class="w-6 h-6 text-success" />
                </div>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold">{{ $sessionStats['past'] }}</h3>
                    <p class="text-sm opacity-70">Past Sessions</p>
                </div>
                <div class="p-3 rounded-full bg-secondary/10">
                    <x-icon name="o-document-check" class="w-6 h-6 text-secondary" />
                </div>
            </div>
        </x-card>
    </div>

    <!-- Sessions Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('title')">
                            <div class="flex items-center">
                                Title
                                @if ($sortBy['column'] === 'title')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('start_time')">
                            <div class="flex items-center">
                                Date & Time
                                @if ($sortBy['column'] === 'start_time')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Duration</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sessions as $session)
                        <tr class="hover">
                            <td>
                                <div class="font-semibold">{{ $session->title }}</div>
                                <div class="text-xs text-gray-500">
                                    @if(isset($session->course))
                                        {{ $session->course->name }}
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if(isset($session->start_time))
                                    <div>{{ $session->start_time->format('d/m/Y') }}</div>
                                    <div class="text-xs">{{ $session->start_time->format('H:i') }}</div>
                                    <div class="mt-1">
                                        @if($session->start_time->isFuture())
                                            <x-badge label="Upcoming" color="info" />
                                        @else
                                            <x-badge label="Past" color="secondary" />
                                        @endif
                                    </div>
                                @else
                                    <div>Not scheduled</div>
                                @endif
                            </td>
                            <td>
                                {{ $session->duration ?: 'N/A' }} {{ $session->duration ? 'min' : '' }}
                            </td>
                            <td>
                                @if($session->description)
                                    <div class="max-w-xs overflow-hidden text-sm">
                                        {{ \Illuminate\Support\Str::limit($session->description, 100) }}
                                    </div>
                                @else
                                    <div class="text-sm italic text-gray-400">No description available</div>
                                @endif

                                @if(isset($session->location))
                                    <div class="flex items-center mt-2 text-xs">
                                        <x-icon name="o-map-pin" class="w-3 h-3 mr-1" />
                                        {{ $session->location }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-calendar" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No sessions found</h3>
                                    <p class="text-gray-500">Try modifying your search criteria</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $sessions->links() }}
        </div>
    </x-card>

    <!-- Filters Drawer -->
    <x-drawer wire:model="showFilters" title="Session Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by title or description"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by date"
                    placeholder="All dates"
                    :options="[
                        ['label' => 'Today', 'value' => 'today'],
                        ['label' => 'This Week', 'value' => 'week'],
                        ['label' => 'This Month', 'value' => 'month'],
                        ['label' => 'Upcoming', 'value' => 'upcoming'],
                        ['label' => 'Past', 'value' => 'past']
                    ]"
                    wire:model.live="dateRange"
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
