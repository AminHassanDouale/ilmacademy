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

    // Model instance
    public TeacherProfile $teacherProfile;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $subjectFilter = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $dateFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'start_time', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $subjectOptions = [];
    public array $typeOptions = [];

    public function mount(TeacherProfile $teacherProfile): void
    {
        $this->teacherProfile = $teacherProfile->load(['user', 'subjects']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed sessions page for teacher: {$teacherProfile->user->name}",
            TeacherProfile::class,
            $teacherProfile->id,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        // Subject options - only subjects assigned to this teacher
        $this->subjectOptions = [
            ['id' => '', 'name' => 'All Subjects'],
            ...$this->teacherProfile->subjects->map(fn($subject) => [
                'id' => $subject->id,
                'name' => $subject->name
            ])->toArray()
        ];

        // Type options
        $this->typeOptions = [
            ['id' => '', 'name' => 'All Types'],
            ['id' => 'lecture', 'name' => 'Lecture'],
            ['id' => 'practical', 'name' => 'Practical'],
            ['id' => 'tutorial', 'name' => 'Tutorial'],
            ['id' => 'workshop', 'name' => 'Workshop'],
            ['id' => 'seminar', 'name' => 'Seminar'],
            ['id' => 'online', 'name' => 'Online'],
        ];
    }

    protected function loadStats(): void
    {
        try {
            $totalSessions = $this->teacherProfile->sessions()->count();
            $upcomingSessions = $this->teacherProfile->sessions()
                ->where('start_time', '>', now())
                ->count();
            $pastSessions = $this->teacherProfile->sessions()
                ->where('end_time', '<', now())
                ->count();
            $todaySessions = $this->teacherProfile->sessions()
                ->whereDate('start_time', today())
                ->count();
            $thisWeekSessions = $this->teacherProfile->sessions()
                ->whereBetween('start_time', [now()->startOfWeek(), now()->endOfWeek()])
                ->count();

            // Get session counts by subject
            $subjectCounts = $this->teacherProfile->sessions()
                ->with('subject')
                ->get()
                ->groupBy('subject.name')
                ->map(fn($sessions) => $sessions->count())
                ->sortDesc()
                ->take(5);

            $this->stats = [
                'total_sessions' => $totalSessions,
                'upcoming_sessions' => $upcomingSessions,
                'past_sessions' => $pastSessions,
                'today_sessions' => $todaySessions,
                'this_week_sessions' => $thisWeekSessions,
                'subject_counts' => $subjectCounts,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_sessions' => 0,
                'upcoming_sessions' => 0,
                'past_sessions' => 0,
                'today_sessions' => 0,
                'this_week_sessions' => 0,
                'subject_counts' => collect(),
            ];
        }
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
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->subjectFilter = '';
        $this->typeFilter = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated sessions
    public function sessions(): LengthAwarePaginator
    {
        return Session::query()
            ->with(['subject', 'attendances'])
            ->where('teacher_profile_id', $this->teacherProfile->id)
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->whereHas('subject', function ($subjectQuery) {
                        $subjectQuery->where('name', 'like', "%{$this->search}%");
                    })
                    ->orWhere('type', 'like', "%{$this->search}%")
                    ->orWhere('link', 'like', "%{$this->search}%");
                });
            })
            ->when($this->subjectFilter, function (Builder $query) {
                $query->where('subject_id', $this->subjectFilter);
            })
            ->when($this->typeFilter, function (Builder $query) {
                $query->where('type', $this->typeFilter);
            })
            ->when($this->dateFilter, function (Builder $query) {
                match($this->dateFilter) {
                    'today' => $query->whereDate('start_time', today()),
                    'tomorrow' => $query->whereDate('start_time', today()->addDay()),
                    'this_week' => $query->whereBetween('start_time', [now()->startOfWeek(), now()->endOfWeek()]),
                    'next_week' => $query->whereBetween('start_time', [now()->addWeek()->startOfWeek(), now()->addWeek()->endOfWeek()]),
                    'upcoming' => $query->where('start_time', '>', now()),
                    'past' => $query->where('end_time', '<', now()),
                    default => null
                };
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->subjectFilter = '';
        $this->typeFilter = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    // Helper function to get session status
    private function getSessionStatus($session): array
    {
        $now = now();

        if ($session->end_time < $now) {
            return ['status' => 'completed', 'color' => 'bg-gray-100 text-gray-800', 'label' => 'Completed'];
        } elseif ($session->start_time <= $now && $session->end_time >= $now) {
            return ['status' => 'ongoing', 'color' => 'bg-green-100 text-green-800', 'label' => 'Ongoing'];
        } elseif ($session->start_time > $now) {
            return ['status' => 'upcoming', 'color' => 'bg-blue-100 text-blue-800', 'label' => 'Upcoming'];
        }

        return ['status' => 'unknown', 'color' => 'bg-gray-100 text-gray-600', 'label' => 'Unknown'];
    }

    // Helper function to get type color
    private function getTypeColor(string $type): string
    {
        return match($type) {
            'lecture' => 'bg-blue-100 text-blue-800',
            'practical' => 'bg-green-100 text-green-800',
            'tutorial' => 'bg-purple-100 text-purple-800',
            'workshop' => 'bg-orange-100 text-orange-800',
            'seminar' => 'bg-red-100 text-red-800',
            'online' => 'bg-indigo-100 text-indigo-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function with(): array
    {
        return [
            'sessions' => $this->sessions(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Sessions: {{ $teacherProfile->user->name }}" subtitle="Manage teacher sessions and schedules" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search sessions..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subjectFilter, $typeFilter, $dateFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="Teacher Profile"
                icon="o-user"
                link="{{ route('admin.teachers.show', $teacherProfile->id) }}"
                class="btn-outline"
                responsive />

            <x-button
                label="Create Session"
                icon="o-plus"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-5">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-calendar" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Total Sessions</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-clock" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['upcoming_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Upcoming</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-orange-100 rounded-full">
                        <x-icon name="o-calendar-days" class="w-8 h-8 text-orange-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['today_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Today</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-chart-bar" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['this_week_sessions']) }}</div>
                        <div class="text-sm text-gray-500">This Week</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-gray-100 rounded-full">
                        <x-icon name="o-check-circle" class="w-8 h-8 text-gray-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-gray-600">{{ number_format($stats['past_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Completed</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Subject Distribution Cards -->
    @if($stats['subject_counts']->count() > 0)
    <div class="grid grid-cols-1 gap-4 mb-8 md:grid-cols-5">
        @foreach($stats['subject_counts'] as $subject => $count)
        <x-card class="border-blue-200 bg-blue-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($count) }}</div>
                <div class="text-sm text-blue-600">{{ $subject ?: 'Unknown Subject' }}</div>
            </div>
        </x-card>
        @endforeach
    </div>
    @endif

    <!-- Sessions Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('subject.name')">
                            <div class="flex items-center">
                                Subject
                                @if ($sortBy['column'] === 'subject.name')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('type')">
                            <div class="flex items-center">
                                Type
                                @if ($sortBy['column'] === 'type')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('start_time')">
                            <div class="flex items-center">
                                Start Time
                                @if ($sortBy['column'] === 'start_time')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('end_time')">
                            <div class="flex items-center">
                                End Time
                                @if ($sortBy['column'] === 'end_time')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Attendances</th>
                        <th>Link</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sessions as $session)
                        @php
                            $status = $this->getSessionStatus($session);
                            $duration = $session->start_time && $session->end_time
                                ? $session->start_time->diffInMinutes($session->end_time) . ' min'
                                : 'Unknown';
                        @endphp
                        <tr class="hover">
                            <td>
                                <div class="font-semibold">{{ $session->subject->name ?? 'Unknown Subject' }}</div>
                                @if($session->subject->description)
                                    <div class="text-xs text-gray-500">{{ Str::limit($session->subject->description, 50) }}</div>
                                @endif
                            </td>
                            <td>
                                @if($session->type)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getTypeColor($session->type) }}">
                                        {{ ucfirst($session->type) }}
                                    </span>
                                @else
                                    <span class="text-sm text-gray-500">Not specified</span>
                                @endif
                            </td>
                            <td>
                                @if($session->start_time)
                                    <div class="text-sm">{{ $session->start_time->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $session->start_time->format('g:i A') }}</div>
                                @else
                                    <span class="text-sm text-gray-500">Not set</span>
                                @endif
                            </td>
                            <td>
                                @if($session->end_time)
                                    <div class="text-sm">{{ $session->end_time->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $session->end_time->format('g:i A') }}</div>
                                @else
                                    <span class="text-sm text-gray-500">Not set</span>
                                @endif
                            </td>
                            <td>
                                <div class="text-sm">{{ $duration }}</div>
                            </td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $status['color'] }}">
                                    {{ $status['label'] }}
                                </span>
                            </td>
                            <td>
                                <div class="text-sm">{{ $session->attendances->count() }} students</div>
                                <div class="text-xs text-gray-500">attended</div>
                            </td>
                            <td>
                                @if($session->link)
                                    <a href="{{ $session->link }}" target="_blank" class="text-blue-600 hover:text-blue-800">
                                        <x-icon name="o-link" class="w-4 h-4" />
                                    </a>
                                @else
                                    <span class="text-gray-400">No link</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View Details"
                                    >
                                        👁️
                                    </button>
                                    <button
                                        class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                        title="Edit Session"
                                    >
                                        ✏️
                                    </button>
                                    <button
                                        class="p-2 text-green-600 bg-green-100 rounded-md hover:text-green-900 hover:bg-green-200"
                                        title="View Attendances"
                                    >
                                        📊
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-calendar" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No sessions found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $subjectFilter || $typeFilter || $dateFilter)
                                                No sessions match your current filters.
                                            @else
                                                This teacher hasn't created any sessions yet.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $subjectFilter || $typeFilter || $dateFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="resetFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Create First Session"
                                            icon="o-plus"
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
            {{ $sessions->links() }}
        </div>

        <!-- Results summary -->
        @if($sessions->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $sessions->firstItem() ?? 0 }} to {{ $sessions->lastItem() ?? 0 }}
            of {{ $sessions->total() }} sessions
            @if($search || $subjectFilter || $typeFilter || $dateFilter)
                (filtered from total)
            @endif
        </div>
        @endif
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search sessions"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by subject, type, or link..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by subject"
                    :options="$subjectOptions"
                    wire:model.live="subjectFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All subjects"
                />
            </div>

            <div>
                <x-select
                    label="Filter by type"
                    :options="$typeOptions"
                    wire:model.live="typeFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All types"
                />
            </div>

            <div>
                <x-select
                    label="Filter by date"
                    :options="[
                        ['id' => '', 'name' => 'All dates'],
                        ['id' => 'today', 'name' => 'Today'],
                        ['id' => 'tomorrow', 'name' => 'Tomorrow'],
                        ['id' => 'this_week', 'name' => 'This week'],
                        ['id' => 'next_week', 'name' => 'Next week'],
                        ['id' => 'upcoming', 'name' => 'Upcoming'],
                        ['id' => 'past', 'name' => 'Past'],
                    ]"
                    wire:model.live="dateFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All dates"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[
                        ['id' => 10, 'name' => '10 per page'],
                        ['id' => 15, 'name' => '15 per page'],
                        ['id' => 25, 'name' => '25 per page'],
                        ['id' => 50, 'name' => '50 per page']
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
