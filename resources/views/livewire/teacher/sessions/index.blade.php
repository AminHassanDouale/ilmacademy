<?php

use App\Models\Session;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Sessions')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $subject = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $period = 'upcoming';

    #[Url]
    public string $startDate = '';

    #[Url]
    public string $endDate = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'date', 'direction' => 'asc'];

    public function mount(): void
    {
        // Default dates if not set
        if (empty($this->startDate)) {
            $this->startDate = Carbon::now()->format('Y-m-d');
        }

        if (empty($this->endDate)) {
            $this->endDate = Carbon::now()->addMonths(3)->format('Y-m-d');
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed sessions page',
            Session::class,
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

    // Set time period
    public function setPeriod(string $period): void
    {
        $this->period = $period;

        switch ($period) {
            case 'today':
                $this->startDate = Carbon::now()->format('Y-m-d');
                $this->endDate = Carbon::now()->format('Y-m-d');
                break;
            case 'tomorrow':
                $this->startDate = Carbon::tomorrow()->format('Y-m-d');
                $this->endDate = Carbon::tomorrow()->format('Y-m-d');
                break;
            case 'this_week':
                $this->startDate = Carbon::now()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfWeek()->format('Y-m-d');
                break;
            case 'next_week':
                $this->startDate = Carbon::now()->next('monday')->format('Y-m-d');
                $this->endDate = Carbon::now()->next('monday')->addDays(6)->format('Y-m-d');
                break;
            case 'this_month':
                $this->startDate = Carbon::now()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfMonth()->format('Y-m-d');
                break;
            case 'upcoming':
                $this->startDate = Carbon::now()->format('Y-m-d');
                $this->endDate = Carbon::now()->addMonths(3)->format('Y-m-d');
                break;
            case 'past':
                $this->startDate = Carbon::now()->subMonths(3)->format('Y-m-d');
                $this->endDate = Carbon::yesterday()->format('Y-m-d');
                break;
            case 'all':
                $this->startDate = Carbon::now()->subYear()->format('Y-m-d');
                $this->endDate = Carbon::now()->addYear()->format('Y-m-d');
                break;
            case 'custom':
                // Keep existing dates
                break;
        }
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->subject = '';
        $this->status = '';
        $this->period = 'upcoming';
        $this->startDate = Carbon::now()->format('Y-m-d');
        $this->endDate = Carbon::now()->addMonths(3)->format('Y-m-d');
        $this->resetPage();
    }

    // Get subjects for this teacher
    public function subjects()
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return collect();
        }

        try {
            // If there's a subjects relationship
            if (method_exists($teacherProfile, 'subjects')) {
                return $teacherProfile->subjects()
                    ->orderBy('name')
                    ->get()
                    ->map(function ($subject) {
                        return [
                            'id' => $subject->id,
                            'name' => $subject->name . ' (' . $subject->code . ')'
                        ];
                    });
            }

            // Fallback: Get all subjects
            return Subject::orderBy('name')
                ->take(50)
                ->get()
                ->map(function ($subject) {
                    return [
                        'id' => $subject->id,
                        'name' => $subject->name . ' (' . $subject->code . ')'
                    ];
                });
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading subjects: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            return collect();
        }
    }

    // View session details
    public function viewSession($sessionId)
    {
        return redirect()->route('teacher.sessions.show', $sessionId);
    }

    // Edit session
    public function editSession($sessionId)
    {
        return redirect()->route('teacher.sessions.edit', $sessionId);
    }

    // Create new session
    public function createSession()
    {
        return redirect()->route('teacher.sessions.create');
    }

    // Mark attendance for a session
    public function markAttendance($sessionId)
    {
        return redirect()->route('teacher.sessions.attendance', $sessionId);
    }

    // Get filtered sessions
    public function sessions()
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return [];
        }

        try {
            $query = Session::query()
                ->where('teacher_profile_id', $teacherProfile->id)
                ->with(['subject', 'subject.curriculum'])
                ->whereBetween('date', [$this->startDate, $this->endDate])
                ->when($this->search, function (Builder $query) {
                    $query->where(function($q) {
                        $q->where('topic', 'like', '%' . $this->search . '%')
                          ->orWhereHas('subject', function ($subquery) {
                              $subquery->where('name', 'like', '%' . $this->search . '%')
                                      ->orWhere('code', 'like', '%' . $this->search . '%');
                          });
                    });
                })
                ->when($this->subject, function (Builder $query) {
                    $query->where('subject_id', $this->subject);
                })
                ->when($this->status, function (Builder $query) {
                    // Convert status to date query
                    $now = Carbon::now();
                    $today = Carbon::today();

                    if ($this->status === 'upcoming') {
                        $query->where(function($q) use ($now) {
                            $q->where('date', '>', $now->format('Y-m-d'))
                              ->orWhere(function($q2) use ($now) {
                                  $q2->where('date', '=', $now->format('Y-m-d'))
                                     ->where('start_time', '>', $now->format('H:i:s'));
                              });
                        });
                    } elseif ($this->status === 'past') {
                        $query->where(function($q) use ($now) {
                            $q->where('date', '<', $now->format('Y-m-d'))
                              ->orWhere(function($q2) use ($now) {
                                  $q2->where('date', '=', $now->format('Y-m-d'))
                                     ->where('end_time', '<', $now->format('H:i:s'));
                              });
                        });
                    } elseif ($this->status === 'today') {
                        $query->where('date', '=', $today->format('Y-m-d'));
                    }
                });

            return $query->orderBy($this->sortBy['column'], $this->sortBy['direction'])
                ->paginate($this->perPage);
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading sessions: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            return [];
        }
    }

    // Get session statistics
    public function sessionStats()
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return [
                'total' => 0,
                'upcoming' => 0,
                'today' => 0,
                'past' => 0,
                'thisWeek' => 0
            ];
        }

        try {
            $now = Carbon::now();
            $today = Carbon::today();
            $startOfWeek = Carbon::now()->startOfWeek();
            $endOfWeek = Carbon::now()->endOfWeek();

            // Total sessions
            $total = Session::where('teacher_profile_id', $teacherProfile->id)->count();

            // Upcoming sessions
            $upcoming = Session::where('teacher_profile_id', $teacherProfile->id)
                ->where(function($q) use ($now) {
                    $q->where('date', '>', $now->format('Y-m-d'))
                      ->orWhere(function($q2) use ($now) {
                          $q2->where('date', '=', $now->format('Y-m-d'))
                             ->where('start_time', '>', $now->format('H:i:s'));
                      });
                })
                ->count();

            // Today's sessions
            $todaySessions = Session::where('teacher_profile_id', $teacherProfile->id)
                ->where('date', '=', $today->format('Y-m-d'))
                ->count();

            // Past sessions
            $past = Session::where('teacher_profile_id', $teacherProfile->id)
                ->where(function($q) use ($now) {
                    $q->where('date', '<', $now->format('Y-m-d'))
                      ->orWhere(function($q2) use ($now) {
                          $q2->where('date', '=', $now->format('Y-m-d'))
                             ->where('end_time', '<', $now->format('H:i:s'));
                      });
                })
                ->count();

            // This week's sessions
            $thisWeek = Session::where('teacher_profile_id', $teacherProfile->id)
                ->whereBetween('date', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')])
                ->count();

            return [
                'total' => $total,
                'upcoming' => $upcoming,
                'today' => $todaySessions,
                'past' => $past,
                'thisWeek' => $thisWeek
            ];
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error calculating session stats: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            return [
                'total' => 0,
                'upcoming' => 0,
                'today' => 0,
                'past' => 0,
                'thisWeek' => 0
            ];
        }
    }

    public function with(): array
    {
        return [
            'sessions' => $this->sessions(),
            'subjects' => $this->subjects(),
            'sessionStats' => $this->sessionStats(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Teaching Sessions" separator progress-indicator>
        <x-slot:subtitle>
            View and manage your scheduled class sessions
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search sessions..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subject, $status, $period !== 'upcoming']))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive
            />
            <x-button
                label="New Session"
                icon="o-plus"
                @click="$wire.createSession()"
                class="btn-primary"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- QUICK STATS PANEL -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-5">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-calendar" class="w-8 h-8" />
            </div>
            <div class="stat-title">Total</div>
            <div class="stat-value">{{ $sessionStats['total'] }}</div>
            <div class="stat-desc">All sessions</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-arrow-trending-up" class="w-8 h-8" />
            </div>
            <div class="stat-title">Upcoming</div>
            <div class="stat-value text-info">{{ $sessionStats['upcoming'] }}</div>
            <div class="stat-desc">Future sessions</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-warning">
                <x-icon name="o-sun" class="w-8 h-8" />
            </div>
            <div class="stat-title">Today</div>
            <div class="stat-value text-warning">{{ $sessionStats['today'] }}</div>
            <div class="stat-desc">Today's classes</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Past</div>
            <div class="stat-value text-success">{{ $sessionStats['past'] }}</div>
            <div class="stat-desc">Completed sessions</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-secondary">
                <x-icon name="o-rectangle-stack" class="w-8 h-8" />
            </div>
            <div class="stat-title">This Week</div>
            <div class="stat-value text-secondary">{{ $sessionStats['thisWeek'] }}</div>
            <div class="stat-desc">Current week</div>
        </div>
    </div>

    <!-- DATE RANGE SELECTOR -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex flex-wrap gap-2">
            <x-button
                label="Today"
                @click="$wire.setPeriod('today')"
                class="{{ $period === 'today' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="Tomorrow"
                @click="$wire.setPeriod('tomorrow')"
                class="{{ $period === 'tomorrow' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Week"
                @click="$wire.setPeriod('this_week')"
                class="{{ $period === 'this_week' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Month"
                @click="$wire.setPeriod('this_month')"
                class="{{ $period === 'this_month' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="Upcoming"
                @click="$wire.setPeriod('upcoming')"
                class="{{ $period === 'upcoming' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="Past"
                @click="$wire.setPeriod('past')"
                class="{{ $period === 'past' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
        </div>

        <div class="flex items-center gap-2">
            <x-input type="date" wire:model.live="startDate" />
            <span>to</span>
            <x-input type="date" wire:model.live="endDate" />
            <x-button
                label="Apply"
                icon="o-check"
                @click="$wire.setPeriod('custom')"
                class="btn-primary"
                size="sm"
            />
        </div>
    </div>

    <!-- SESSIONS TABLE -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('date')">
                            <div class="flex items-center">
                                Date & Time
                                @if ($sortBy['column'] === 'date')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('subject_id')">
                            <div class="flex items-center">
                                Subject
                                @if ($sortBy['column'] === 'subject_id')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('topic')">
                            <div class="flex items-center">
                                Topic
                                @if ($sortBy['column'] === 'topic')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sessions as $session)
                        <tr class="hover">
                            <td>
                                <div class="flex flex-col">
                                    <span class="font-medium">{{ $session->date->format('d M Y') }}</span>
                                    <span class="text-sm text-gray-600">{{ $session->start_time }} - {{ $session->end_time }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-col">
                                    <span>{{ $session->subject->name ?? 'Unknown Subject' }}</span>
                                    <span class="text-sm text-gray-600">{{ $session->subject->code ?? '' }}</span>
                                </div>
                            </td>
                            <td>{{ $session->topic }}</td>
                            <td>
                                @php
                                    $now = now();
                                    $sessionDate = Carbon::parse($session->date);
                                    $startTime = Carbon::parse($session->date . ' ' . $session->start_time);
                                    $endTime = Carbon::parse($session->date . ' ' . $session->end_time);
                                    $status = 'upcoming';
                                    $statusColor = 'info';

                                    if ($startTime->isFuture()) {
                                        $status = 'upcoming';
                                        $statusColor = 'info';
                                    } elseif ($startTime->isPast() && $endTime->isFuture()) {
                                        $status = 'in-progress';
                                        $statusColor = 'warning';
                                    } elseif ($endTime->isPast()) {
                                        $status = 'completed';
                                        $statusColor = 'success';
                                    }
                                @endphp

                                <x-badge
                                    label="{{ ucfirst($status) }}"
                                    color="{{ $statusColor }}"
                                />
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View Session Details"
                                        wire:click="viewSession({{ $session->id }})"
                                    />

                                    @if ($status === 'upcoming')
                                        <x-button
                                            icon="o-pencil-square"
                                            color="primary"
                                            size="sm"
                                            tooltip="Edit Session"
                                            wire:click="editSession({{ $session->id }})"
                                        />
                                    @endif

                                    @if ($status === 'in-progress' || $status === 'completed')
                                        <x-button
                                            icon="o-clipboard-document-check"
                                            color="success"
                                            size="sm"
                                            tooltip="Mark Attendance"
                                            wire:click="markAttendance({{ $session->id }})"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-calendar" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No sessions found</h3>
                                    <p class="text-gray-500">No sessions match your current filters for the selected time period</p>
                                    <x-button
                                        label="Schedule New Session"
                                        icon="o-plus"
                                        @click="$wire.createSession()"
                                        class="mt-2 btn-primary"
                                    />
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if ($sessions instanceof \Illuminate\Pagination\LengthAwarePaginator)
            <div class="mt-4">
                {{ $sessions->links() }}
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
                    placeholder="Topic or subject..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by subject"
                    placeholder="All subjects"
                    :options="$subjects"
                    wire:model.live="subject"
                    option-label="name"
                    option-value="id"
                    empty-message="No subjects found"
                />
            </div>

            <div>
                <x-select
                    label="Filter by status"
                    placeholder="All statuses"
                    :options="[
                        ['label' => 'Upcoming', 'value' => 'upcoming'],
                        ['label' => 'Past', 'value' => 'past'],
                        ['label' => 'Today', 'value' => 'today']
                    ]"
                    wire:model.live="status"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Time period"
                    :options="[
                        ['label' => 'Today', 'value' => 'today'],
                        ['label' => 'Tomorrow', 'value' => 'tomorrow'],
                        ['label' => 'This Week', 'value' => 'this_week'],
                        ['label' => 'Next Week', 'value' => 'next_week'],
                        ['label' => 'This Month', 'value' => 'this_month'],
                        ['label' => 'Upcoming', 'value' => 'upcoming'],
                        ['label' => 'Past', 'value' => 'past'],
                        ['label' => 'All', 'value' => 'all'],
                        ['label' => 'Custom', 'value' => 'custom']
                    ]"
                    wire:model.live="period"
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
