<?php

use App\Models\TeacherProfile;
use App\Models\TimetableSlot;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Teacher Timetable')] class extends Component {
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
    public string $dayFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'day', 'direction' => 'asc'];

    // View mode
    #[Url]
    public string $viewMode = 'table'; // 'table' or 'calendar'

    // Stats
    public array $stats = [];

    // Filter options
    public array $subjectOptions = [];
    public array $dayOptions = [];

    // Calendar data
    public array $weeklySchedule = [];

    public function mount(TeacherProfile $teacherProfile): void
    {
        $this->teacherProfile = $teacherProfile->load(['user', 'subjects']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed timetable page for teacher: {$teacherProfile->user->name}",
            TeacherProfile::class,
            $teacherProfile->id,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
        $this->loadWeeklySchedule();
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

        // Day options
        $this->dayOptions = [
            ['id' => '', 'name' => 'All Days'],
            ['id' => 'monday', 'name' => 'Monday'],
            ['id' => 'tuesday', 'name' => 'Tuesday'],
            ['id' => 'wednesday', 'name' => 'Wednesday'],
            ['id' => 'thursday', 'name' => 'Thursday'],
            ['id' => 'friday', 'name' => 'Friday'],
            ['id' => 'saturday', 'name' => 'Saturday'],
            ['id' => 'sunday', 'name' => 'Sunday'],
        ];
    }

    protected function loadStats(): void
    {
    protected function loadStats(): void
    {
        try {
            $totalSlots = $this->teacherProfile->timetableSlots()->count();
            $subjectsCount = $this->teacherProfile->timetableSlots()->distinct('subject_id')->count();
            $weekdaySlots = $this->teacherProfile->timetableSlots()
                ->whereIn('day', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'])
                ->count();
            $weekendSlots = $this->teacherProfile->timetableSlots()
                ->whereIn('day', ['saturday', 'sunday'])
                ->count();

            // Get slots count by day
            $dayCounts = $this->teacherProfile->timetableSlots()
                ->selectRaw('day, COUNT(*) as count')
                ->groupBy('day')
                ->orderByRaw("FIELD(day, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday')")
                ->get();

            // Calculate total teaching hours per week
            $totalHours = $this->teacherProfile->timetableSlots()
                ->get()
                ->sum(function ($slot) {
                    if ($slot->start_time && $slot->end_time) {
                        return $slot->start_time->diffInHours($slot->end_time);
                    }
                    return 0;
                });

            $this->stats = [
                'total_slots' => $totalSlots,
                'subjects_count' => $subjectsCount,
                'weekday_slots' => $weekdaySlots,
                'weekend_slots' => $weekendSlots,
                'total_hours' => $totalHours,
                'day_counts' => $dayCounts,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_slots' => 0,
                'subjects_count' => 0,
                'weekday_slots' => 0,
                'weekend_slots' => 0,
                'total_hours' => 0,
                'day_counts' => collect(),
            ];
        }
    }

    protected function loadWeeklySchedule(): void
    {
        try {
            $slots = $this->teacherProfile->timetableSlots()
                ->with('subject')
                ->get()
                ->groupBy('day');

            $this->weeklySchedule = [
                'monday' => $slots->get('monday', collect())->sortBy('start_time'),
                'tuesday' => $slots->get('tuesday', collect())->sortBy('start_time'),
                'wednesday' => $slots->get('wednesday', collect())->sortBy('start_time'),
                'thursday' => $slots->get('thursday', collect())->sortBy('start_time'),
                'friday' => $slots->get('friday', collect())->sortBy('start_time'),
                'saturday' => $slots->get('saturday', collect())->sortBy('start_time'),
                'sunday' => $slots->get('sunday', collect())->sortBy('start_time'),
            ];
        } catch (\Exception $e) {
            $this->weeklySchedule = [
                'monday' => collect(),
                'tuesday' => collect(),
                'wednesday' => collect(),
                'thursday' => collect(),
                'friday' => collect(),
                'saturday' => collect(),
                'sunday' => collect(),
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

    // Switch view mode
    public function switchViewMode(string $mode): void
    {
        $this->viewMode = $mode;
        if ($mode === 'calendar') {
            $this->loadWeeklySchedule();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDayFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->subjectFilter = '';
        $this->dayFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated timetable slots
    public function timetableSlots(): LengthAwarePaginator
    {
        return TimetableSlot::query()
            ->with(['subject'])
            ->where('teacher_profile_id', $this->teacherProfile->id)
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->whereHas('subject', function ($subjectQuery) {
                        $subjectQuery->where('name', 'like', "%{$this->search}%");
                    })
                    ->orWhere('day', 'like', "%{$this->search}%");
                });
            })
            ->when($this->subjectFilter, function (Builder $query) {
                $query->where('subject_id', $this->subjectFilter);
            })
            ->when($this->dayFilter, function (Builder $query) {
                $query->where('day', $this->dayFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->subjectFilter = '';
        $this->dayFilter = '';
        $this->resetPage();
    }

    // Helper function to get day color
    private function getDayColor(string $day): string
    {
        return match(strtolower($day)) {
            'monday' => 'bg-blue-100 text-blue-800',
            'tuesday' => 'bg-green-100 text-green-800',
            'wednesday' => 'bg-yellow-100 text-yellow-800',
            'thursday' => 'bg-purple-100 text-purple-800',
            'friday' => 'bg-red-100 text-red-800',
            'saturday' => 'bg-orange-100 text-orange-800',
            'sunday' => 'bg-gray-100 text-gray-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to format time
    private function formatTime($time): string
    {
        if (!$time) return 'Not set';

        if (is_string($time)) {
            $time = \Carbon\Carbon::parse($time);
        }

        return $time->format('g:i A');
    }

    public function with(): array
    {
        return [
            'timetableSlots' => $this->viewMode === 'table' ? $this->timetableSlots() : null,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Timetable: {{ $teacherProfile->user->name }}" subtitle="Manage teacher weekly schedule" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search schedule..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <!-- View Mode Toggle -->
            <div class="btn-group">
                <button
                    wire:click="switchViewMode('table')"
                    class="btn btn-sm {{ $viewMode === 'table' ? 'btn-active' : '' }}"
                >
                    📋 Table
                </button>
                <button
                    wire:click="switchViewMode('calendar')"
                    class="btn btn-sm {{ $viewMode === 'calendar' ? 'btn-active' : '' }}"
                >
                    📅 Calendar
                </button>
            </div>

            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subjectFilter, $dayFilter]))"
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
                label="Add Slot"
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
                        <x-icon name="o-clock" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_slots']) }}</div>
                        <div class="text-sm text-gray-500">Total Slots</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-book-open" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['subjects_count']) }}</div>
                        <div class="text-sm text-gray-500">Subjects</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-calendar-days" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['weekday_slots']) }}</div>
                        <div class="text-sm text-gray-500">Weekday Slots</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-orange-100 rounded-full">
                        <x-icon name="o-sun" class="w-8 h-8 text-orange-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['weekend_slots']) }}</div>
                        <div class="text-sm text-gray-500">Weekend Slots</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-red-100 rounded-full">
                        <x-icon name="o-chart-bar" class="w-8 h-8 text-red-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['total_hours']) }}</div>
                        <div class="text-sm text-gray-500">Hours/Week</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Day Distribution Cards -->
    @if($stats['day_counts']->count() > 0)
    <div class="grid grid-cols-1 gap-4 mb-8 md:grid-cols-7">
        @foreach($stats['day_counts'] as $dayData)
        <x-card class="border-indigo-200 bg-indigo-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ number_format($dayData->count) }}</div>
                <div class="text-sm text-indigo-600">{{ ucfirst($dayData->day) }}</div>
            </div>
        </x-card>
        @endforeach
    </div>
    @endif

    @if($viewMode === 'calendar')
        <!-- Calendar View -->
        <x-card title="Weekly Schedule">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-7">
                @foreach(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day)
                    <div class="border rounded-lg">
                        <!-- Day Header -->
                        <div class="p-3 text-center border-b {{ $this->getDayColor($day) }}">
                            <div class="font-semibold">{{ ucfirst($day) }}</div>
                            <div class="text-xs">{{ $weeklySchedule[$day]->count() }} slots</div>
                        </div>

                        <!-- Day Slots -->
                        <div class="p-2 space-y-2 min-h-[200px]">
                            @forelse($weeklySchedule[$day] as $slot)
                                <div class="p-2 border rounded bg-gray-50 hover:bg-gray-100">
                                    <div class="text-xs font-medium text-gray-900">
                                        {{ $slot->subject->name ?? 'Unknown Subject' }}
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        {{ $this->formatTime($slot->start_time) }} - {{ $this->formatTime($slot->end_time) }}
                                    </div>
                                    @if($slot->start_time && $slot->end_time)
                                        <div class="text-xs text-gray-500">
                                            {{ $slot->start_time->diffInMinutes($slot->end_time) }} min
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div class="flex items-center justify-center h-24 text-gray-400">
                                    <div class="text-center">
                                        <x-icon name="o-calendar-x" class="w-6 h-6 mx-auto mb-1" />
                                        <div class="text-xs">No classes</div>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    @else
        <!-- Table View -->
        <x-card>
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead>
                        <tr>
                            <th class="cursor-pointer" wire:click="sortBy('day')">
                                <div class="flex items-center">
                                    Day
                                    @if ($sortBy['column'] === 'day')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer" wire:click="sortBy('subject.name')">
                                <div class="flex items-center">
                                    Subject
                                    @if ($sortBy['column'] === 'subject.name')
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
                            <th class="cursor-pointer" wire:click="sortBy('created_at')">
                                <div class="flex items-center">
                                    Created
                                    @if ($sortBy['column'] === 'created_at')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($timetableSlots as $slot)
                            @php
                                $duration = $slot->start_time && $slot->end_time
                                    ? $slot->start_time->diffInMinutes($slot->end_time) . ' min'
                                    : 'Unknown';
                            @endphp
                            <tr class="hover">
                                <td>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getDayColor($slot->day) }}">
                                        {{ ucfirst($slot->day) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="font-semibold">{{ $slot->subject->name ?? 'Unknown Subject' }}</div>
                                    @if($slot->subject && $slot->subject->description)
                                        <div class="text-xs text-gray-500">{{ Str::limit($slot->subject->description, 50) }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="text-sm">{{ $this->formatTime($slot->start_time) }}</div>
                                </td>
                                <td>
                                    <div class="text-sm">{{ $this->formatTime($slot->end_time) }}</div>
                                </td>
                                <td>
                                    <div class="text-sm">{{ $duration }}</div>
                                </td>
                                <td>
                                    <div class="text-sm">{{ $slot->created_at->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $slot->created_at->diffForHumans() }}</div>
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
                                            title="Edit Slot"
                                        >
                                            ✏️
                                        </button>
                                        <button
                                            class="p-2 text-red-600 bg-red-100 rounded-md hover:text-red-900 hover:bg-red-200"
                                            title="Delete Slot"
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
                                        <x-icon name="o-calendar" class="w-20 h-20 text-gray-300" />
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-600">No timetable slots found</h3>
                                            <p class="mt-1 text-gray-500">
                                                @if($search || $subjectFilter || $dayFilter)
                                                    No slots match your current filters.
                                                @else
                                                    This teacher doesn't have any timetable slots yet.
                                                @endif
                                            </p>
                                        </div>
                                        @if($search || $subjectFilter || $dayFilter)
                                            <x-button
                                                label="Clear Filters"
                                                wire:click="resetFilters"
                                                color="secondary"
                                                size="sm"
                                            />
                                        @else
                                            <x-button
                                                label="Add First Slot"
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

            @if($viewMode === 'table' && $timetableSlots)
                <!-- Pagination -->
                <div class="mt-4">
                    {{ $timetableSlots->links() }}
                </div>

                <!-- Results summary -->
                @if($timetableSlots->count() > 0)
                <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
                    Showing {{ $timetableSlots->firstItem() ?? 0 }} to {{ $timetableSlots->lastItem() ?? 0 }}
                    of {{ $timetableSlots->total() }} slots
                    @if($search || $subjectFilter || $dayFilter)
                        (filtered from total)
                    @endif
                </div>
                @endif
            @endif
        </x-card>
    @endif

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search schedule"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by subject or day..."
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
                    label="Filter by day"
                    :options="$dayOptions"
                    wire:model.live="dayFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All days"
                />
            </div>

            @if($viewMode === 'table')
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
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Reset" icon="o-x-mark" wire:click="resetFilters" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" />
        </x-slot:actions>
    </x-drawer>
</div>
