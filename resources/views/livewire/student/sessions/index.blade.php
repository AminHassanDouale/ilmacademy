<?php

use App\Models\Session;
use App\Models\Attendance;
use App\Models\ProgramEnrollment;
use App\Models\ChildProfile;
use App\Models\Subject;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Carbon\Carbon;

new #[Title('Class Sessions')] class extends Component {
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
    public string $dateRange = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public string $view = 'list'; // 'list', 'calendar', 'upcoming'

    // Load data
    public function mount(): void
    {
        // Default to upcoming view
        if (empty($this->view)) {
            $this->view = 'upcoming';
        }

        // Default to sessions from today onwards if no date range is set
        if (empty($this->dateRange)) {
            $this->dateRange = 'upcoming';
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Student accessed sessions page',
            Session::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->subject = '';
        $this->status = '';
        $this->dateRange = 'upcoming';
        $this->resetPage();
    }

    // Get the user's child profiles
    private function getChildProfiles()
    {
        // Get the client profile associated with this user
        $clientProfile = Auth::user()->clientProfile;

        if (!$clientProfile) {
            return collect();
        }

        // Get child profiles associated with this parent
        return ChildProfile::where('parent_profile_id', $clientProfile->id)->get();
    }

    // Get available subjects that the student is enrolled in
    public function subjects()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        // Get all program enrollments for these children
        $programEnrollmentIds = ProgramEnrollment::whereIn('child_profile_id', $childProfileIds)
            ->where('status', 'active')
            ->pluck('id')
            ->toArray();

        // Get the actual subjects from attendances
        $subjectIds = Attendance::whereHas('session', function($query) {
                $query->where('start_time', '>=', Carbon::now()->subMonths(3));
            })
            ->whereIn('child_profile_id', $childProfileIds)
            ->join('sessions', 'attendances.session_id', '=', 'sessions.id')
            ->pluck('sessions.subject_id')
            ->unique()
            ->toArray();

        return Subject::whereIn('id', $subjectIds)
            ->orderBy('name')
            ->get()
            ->map(function ($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name
                ];
            });
    }

    // Get available status options
    public function statuses()
    {
        return collect([
            ['id' => 'upcoming', 'name' => 'Upcoming'],
            ['id' => 'completed', 'name' => 'Completed'],
            ['id' => 'cancelled', 'name' => 'Cancelled'],
        ]);
    }

    // Get available date ranges
    public function dateRanges()
    {
        return collect([
            ['id' => 'upcoming', 'name' => 'Upcoming Sessions'],
            ['id' => 'today', 'name' => 'Today'],
            ['id' => 'this_week', 'name' => 'This Week'],
            ['id' => 'next_week', 'name' => 'Next Week'],
            ['id' => 'this_month', 'name' => 'This Month'],
            ['id' => 'past', 'name' => 'Past Sessions'],
        ]);
    }

    // Change view type
    public function setView(string $view): void
    {
        $this->view = $view;
    }

    // Join session
    public function joinSession($sessionId)
    {
        $session = Session::find($sessionId);

        if (!$session) {
            $this->error('Session not found.');
            return;
        }

        if ($session->type !== 'online') {
            $this->error('This is not an online session.');
            return;
        }

        // Check if the session is starting soon (within 15 minutes) or has already started
        $now = Carbon::now();
        $sessionStart = Carbon::parse($session->start_time);
        $canJoin = $now->diffInMinutes($sessionStart, false) <= 15;

        if (!$canJoin) {
            $this->error('You can only join a session 15 minutes before its scheduled start time.');
            return;
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'join',
            'Student joined a class session',
            Session::class,
            $sessionId,
            ['ip' => request()->ip()]
        );

        // Redirect to the session link
        return redirect()->away($session->link);
    }

    // View session details
    public function viewSession($sessionId)
    {
        return redirect()->route('student.sessions.show', $sessionId);
    }

    // Get sessions with filtering
    public function sessions()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        // Start with attendances to find sessions the student's children are attending
        $query = Session::query()
            ->with(['subject', 'teacherProfile.user'])
            ->whereHas('attendances', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->when($this->search, function (Builder $query) {
                $query->where(function($q) {
                    $q->whereHas('subject', function ($sq) {
                          $sq->where('name', 'like', '%' . $this->search . '%')
                            ->orWhere('code', 'like', '%' . $this->search . '%');
                      })
                      ->orWhereHas('teacherProfile.user', function ($sq) {
                          $sq->where('name', 'like', '%' . $this->search . '%');
                      });
                });
            })
            ->when($this->subject, function (Builder $query) {
                $query->where('subject_id', $this->subject);
            })
            ->when($this->status, function (Builder $query) {
                if ($this->status === 'upcoming') {
                    $query->where('start_time', '>=', Carbon::now());
                } else if ($this->status === 'completed') {
                    $query->where('start_time', '<', Carbon::now());
                } else if ($this->status === 'cancelled') {
                    $query->where('status', 'cancelled');
                }
            })
            ->when($this->dateRange, function (Builder $query) {
                $now = Carbon::now();

                switch ($this->dateRange) {
                    case 'upcoming':
                        $query->where('start_time', '>=', $now);
                        break;
                    case 'today':
                        $query->whereDate('start_time', $now->toDateString());
                        break;
                    case 'this_week':
                        $query->whereBetween('start_time', [
                            $now->copy()->startOfWeek(),
                            $now->copy()->endOfWeek()
                        ]);
                        break;
                    case 'next_week':
                        $query->whereBetween('start_time', [
                            $now->copy()->addWeek()->startOfWeek(),
                            $now->copy()->addWeek()->endOfWeek()
                        ]);
                        break;
                    case 'this_month':
                        $query->whereBetween('start_time', [
                            $now->copy()->startOfMonth(),
                            $now->copy()->endOfMonth()
                        ]);
                        break;
                    case 'past':
                        $query->where('start_time', '<', $now);
                        break;
                }
            });

        if ($this->view === 'upcoming') {
            // Override other filters for upcoming view
            $query->where('start_time', '>=', Carbon::now())
                ->orderBy('start_time', 'asc')
                ->limit(5);

            return $query->get();
        } else {
            return $query
                ->orderBy('start_time', $this->view === 'calendar' ? 'asc' : 'desc')
                ->paginate($this->perPage);
        }
    }

    // Get next session
    public function nextSession()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        return Session::with(['subject', 'teacherProfile.user'])
            ->whereHas('attendances', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->where('start_time', '>=', Carbon::now())
            ->orderBy('start_time', 'asc')
            ->first();
    }

    // Get sessions for calendar view
    public function calendarSessions()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        $startDate = Carbon::now()->startOfMonth()->subWeek();
        $endDate = Carbon::now()->endOfMonth()->addWeek();

        $sessions = Session::with(['subject', 'teacherProfile.user'])
            ->whereHas('attendances', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->whereBetween('start_time', [$startDate, $endDate])
            ->get();

        // Format for calendar view
        return $sessions->map(function ($session) {
            $status = Carbon::parse($session->start_time)->isPast() ? 'completed' : 'upcoming';

            return [
                'id' => $session->id,
                'title' => $session->subject->name,
                'start' => $session->start_time,
                'end' => $session->end_time,
                'subject' => $session->subject->name,
                'teacher' => $session->teacherProfile->user->name ?? 'TBD',
                'status' => $status,
                'url' => route('student.sessions.show', $session->id)
            ];
        });
    }

    // Get session statistics
    public function sessionStats()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        $now = Carbon::now();

        $upcomingSessions = Session::whereHas('attendances', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->where('start_time', '>=', $now)
            ->count();

        $completedSessions = Session::whereHas('attendances', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->where('start_time', '<', $now)
            ->count();

        $todaySessions = Session::whereHas('attendances', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->whereDate('start_time', $now->toDateString())
            ->count();

        $thisWeekSessions = Session::whereHas('attendances', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->whereBetween('start_time', [
                $now->copy()->startOfWeek(),
                $now->copy()->endOfWeek()
            ])
            ->count();

        return [
            'upcoming' => $upcomingSessions,
            'completed' => $completedSessions,
            'today' => $todaySessions,
            'this_week' => $thisWeekSessions
        ];
    }

    public function with(): array
    {
        return [
            'sessions' => $this->sessions(),
            'nextSession' => $this->nextSession(),
            'calendarSessions' => $this->view === 'calendar' ? $this->calendarSessions() : null,
            'subjects' => $this->subjects(),
            'statuses' => $this->statuses(),
            'dateRanges' => $this->dateRanges(),
            'sessionStats' => $this->sessionStats(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Class Sessions" separator progress-indicator>
        <x-slot:subtitle>
            View your scheduled classes
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search sessions..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <div class="join">
                <x-button
                    icon="o-list-bullet"
                    :class="$view === 'list' ? 'btn-primary' : 'bg-base-300'"
                    class="join-item"
                    wire:click="setView('list')"
                    tooltip="List View"
                />
                <x-button
                    icon="o-calendar"
                    :class="$view === 'calendar' ? 'btn-primary' : 'bg-base-300'"
                    class="join-item"
                    wire:click="setView('calendar')"
                    tooltip="Calendar View"
                />
                <x-button
                    icon="o-clock"
                    :class="$view === 'upcoming' ? 'btn-primary' : 'bg-base-300'"
                    class="join-item"
                    wire:click="setView('upcoming')"
                    tooltip="Upcoming Sessions"
                />
            </div>

            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subject, $status, $dateRange !== 'upcoming' ? $dateRange : '']))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- QUICK STATS PANEL -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-calendar" class="w-8 h-8" />
            </div>
            <div class="stat-title">Upcoming</div>
            <div class="stat-value">{{ $sessionStats['upcoming'] }}</div>
            <div class="stat-desc">Scheduled sessions</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Completed</div>
            <div class="stat-value text-success">{{ $sessionStats['completed'] }}</div>
            <div class="stat-desc">Past sessions</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-warning">
                <x-icon name="o-sun" class="w-8 h-8" />
            </div>
            <div class="stat-title">Today</div>
            <div class="stat-value text-warning">{{ $sessionStats['today'] }}</div>
            <div class="stat-desc">Classes today</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-calendar-days" class="w-8 h-8" />
            </div>
            <div class="stat-title">This Week</div>
            <div class="stat-value text-info">{{ $sessionStats['this_week'] }}</div>
            <div class="stat-desc">Classes this week</div>
        </div>
    </div>

    <!-- NEXT SESSION CARD (only shown in upcoming view) -->
    @if($view === 'upcoming' && $nextSession)
        <div class="mb-6">
            <x-card title="Next Scheduled Session">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <div class="col-span-1 md:col-span-2">
                        <div class="space-y-4">
                            <div>
                                <h3 class="text-xl font-bold">{{ $nextSession->subject->name }}</h3>
                                <p class="text-gray-500">
                                    @if($nextSession->teacherProfile && $nextSession->teacherProfile->user)
                                        With {{ $nextSession->teacherProfile->user->name }}
                                    @else
                                        Teacher to be assigned
                                    @endif
                                </p>
                            </div>

                            <div class="flex items-center gap-4">
                                <div class="flex items-center">
                                    <x-icon name="o-clock" class="w-5 h-5 mr-2 text-gray-500" />
                                    <span>{{ Carbon\Carbon::parse($nextSession->start_time)->format('g:i A') }} - {{ Carbon\Carbon::parse($nextSession->end_time)->format('g:i A') }}</span>
                                </div>

                                <div class="flex items-center">
                                    <x-icon name="o-map-pin" class="w-5 h-5 mr-2 text-gray-500" />
                                    <span>{{ $nextSession->type === 'online' ? 'Online Class' : 'In-Person Class' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-1">
                        <div class="flex flex-col items-center justify-center h-full">
                            <div class="mb-4 text-center">
                                <div class="text-3xl font-bold">{{ Carbon\Carbon::parse($nextSession->start_time)->format('d') }}</div>
                                <div class="text-xl">{{ Carbon\Carbon::parse($nextSession->start_time)->format('M') }}</div>
                                <div class="mt-2 text-gray-500">{{ Carbon\Carbon::parse($nextSession->start_time)->format('l') }}</div>
                            </div>

                            <div>
                                @php
                                    $now = Carbon\Carbon::now();
                                    $sessionStart = Carbon\Carbon::parse($nextSession->start_time);
                                    $canJoin = $nextSession->type === 'online' && $now->diffInMinutes($sessionStart, false) <= 15 && $now->diffInMinutes($sessionStart, false) > -60;
                                @endphp

                                <x-button
                                    label="{{ $canJoin ? 'Join Now' : 'View Details' }}"
                                    icon="{{ $canJoin ? 'o-video-camera' : 'o-eye' }}"
                                    color="{{ $canJoin ? 'success' : 'primary' }}"
                                    class="w-full"
                                    wire:click="{{ $canJoin ? 'joinSession(' . $nextSession->id . ')' : 'viewSession(' . $nextSession->id . ')' }}"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    @endif

    <!-- VIEW: LIST -->
    @if($view === 'list')
        <x-card>
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Subject</th>
                            <th>Teacher</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sessions as $session)
                            <tr class="hover">
                                <td>
                                    <div>
                                        <div class="font-semibold">{{ Carbon\Carbon::parse($session->start_time)->format('M d, Y') }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ Carbon\Carbon::parse($session->start_time)->format('g:i A') }} - {{ Carbon\Carbon::parse($session->end_time)->format('g:i A') }}
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $session->subject->name }}</td>
                                <td>
                                    @if($session->teacherProfile && $session->teacherProfile->user)
                                        {{ $session->teacherProfile->user->name }}
                                    @else
                                        <span class="text-gray-400">TBD</span>
                                    @endif
                                </td>
                                <td>
                                    @if($session->type === 'online')
                                        <x-badge label="Online" color="info" />
                                    @else
                                        <x-badge label="In-Person" color="neutral" />
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $isPast = Carbon\Carbon::parse($session->start_time)->isPast();
                                    @endphp

                                    @if($isPast)
                                        <x-badge label="Completed" color="success" />
                                    @else
                                        <x-badge label="Upcoming" color="warning" />
                                    @endif
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

                                        @php
                                            $now = Carbon\Carbon::now();
                                            $sessionStart = Carbon\Carbon::parse($session->start_time);
                                            $canJoin = $session->type === 'online' && !$isPast && $now->diffInMinutes($sessionStart, false) <= 15 && $now->diffInMinutes($sessionStart, false) > -60;
                                        @endphp

                                        @if ($canJoin)
                                            <x-button
                                                icon="o-video-camera"
                                                color="success"
                                                size="sm"
                                                tooltip="Join Session"
                                                wire:click="joinSession({{ $session->id }})"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <x-icon name="o-calendar" class="w-16 h-16 text-gray-400" />
                                        <h3 class="text-lg font-semibold text-gray-600">No sessions found</h3>
                                        <p class="text-gray-500">No sessions match your current filters</p>
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
    @endif

    <!-- VIEW: CALENDAR -->
    @if($view === 'calendar')
        <x-card>
            <div id="calendar-container" class="min-h-[600px]">
                <!-- Calendar will be rendered here -->
            </div>

            <!-- Calendar Scripts -->
            <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
            <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css" rel="stylesheet" />

            <script>
                document.addEventListener('livewire:initialized', function() {
                    const calendarEl = document.getElementById('calendar-container');
                    const calendar = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'dayGridMonth',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,timeGridWeek,timeGridDay'
                        },
                        events: @json($calendarSessions),
                        eventClick: function(info) {
                            if (info.event.url) {
                                info.jsEvent.preventDefault();
                                @this.viewSession(info.event.id);
                            }
                        },
                        eventTimeFormat: {
                            hour: 'numeric',
                            minute: '2-digit',
                            meridiem: 'short'
                        },
                        eventClassNames: function(arg) {
                            // Add different colors based on session status
                            if (arg.event.extendedProps.status === 'upcoming') {
                                return ['bg-warning', 'border-warning'];
                            } else if (arg.event.extendedProps.status === 'completed') {
                                return ['bg-success', 'border-success'];
                            } else {
                                return ['bg-info', 'border-info'];
                            }
                        }
                    });

                    calendar.render();

                    // Listen for changes to rerender the calendar
                    Livewire.on('sessionUpdated', () => {
                        calendar.refetchEvents();
                    });
                });
            </script>
        </x-card>
    @endif

    <!-- VIEW: UPCOMING -->
    @if($view === 'upcoming')
        <x-card title="Upcoming Sessions">
            @forelse ($sessions as $session)
                <div class="p-4 mb-4 border rounded-lg {{ Carbon\Carbon::parse($session->start_time)->isToday() ? 'border-warning bg-warning bg-opacity-10' : 'border-base-300' }}">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <div class="col-span-1">
                            <div class="flex flex-col items-center justify-center h-full">
                                <div class="text-center">
                                    <div class="text-2xl font-bold">{{ Carbon\Carbon::parse($session->start_time)->format('d') }}</div>
                                    <div class="text-lg">{{ Carbon\Carbon::parse($session->start_time)->format('M') }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ Carbon\Carbon::parse($session->start_time)->format('l') }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-span-1 md:col-span-2">
                            <div>
                                <h3 class="text-lg font-bold">{{ $session->subject->name }}</h3>

                                <div class="mt-2">
                                    <div class="flex items-center">
                                        <x-icon name="o-clock" class="w-4 h-4 mr-1 text-gray-500" />
                                        <span class="text-sm">{{ Carbon\Carbon::parse($session->start_time)->format('g:i A') }} - {{ Carbon\Carbon::parse($session->end_time)->format('g:i A') }}</span>
                                    </div>

                                    <div class="flex items-center mt-1">
                                        <x-icon name="o-user" class="w-4 h-4 mr-1 text-gray-500" />
                                        <span class="text-sm">
                                            @if($session->teacherProfile && $session->teacherProfile->user)
                                                {{ $session->teacherProfile->user->name }}
                                            @else
                                                <span class="text-gray-400">Teacher to be assigned</span>
                                            @endif
                                        </span>
                                    </div>

                                    <div class="flex items-center mt-1">
                                        <x-icon name="o-map-pin" class="w-4 h-4 mr-1 text-gray-500" />
                                        <span class="text-sm">{{ $session->type === 'online' ? 'Online Class' : 'In-Person Class' }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-span-1">
                            <div class="flex flex-col justify-center h-full gap-2">
                                @php
                                    $now = Carbon\Carbon::now();
                                    $sessionStart = Carbon\Carbon::parse($session->start_time);
                                    $canJoin = $session->type === 'online' && $now->diffInMinutes($sessionStart, false) <= 15 && $now->diffInMinutes($sessionStart, false) > -60;
                                @endphp

                                @if ($canJoin)
                                    <x-button
                                        label="Join Now"
                                        icon="o-video-camera"
                                        color="success"
                                        class="w-full"
                                        wire:click="joinSession({{ $session->id }})"
                                    />
                                @else
                                    <x-button
                                        label="View Details"
                                        icon="o-eye"
                                        color="primary"
                                        class="w-full"
                                        wire:click="viewSession({{ $session->id }})"
                                    />
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <div class="flex flex-col items-center justify-center gap-2">
                        <x-icon name="o-calendar" class="w-16 h-16 text-gray-400" />
                        <h3 class="text-lg font-semibold text-gray-600">No upcoming sessions</h3>
                        <p class="text-gray-500">You don't have any scheduled sessions in the near future</p>
                    </div>
                </div>
            @endforelse
        </x-card>
    @endif

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Session Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search sessions"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by subject or teacher..."
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
                    :options="$statuses"
                    wire:model.live="status"
                    option-label="name"
                    option-value="id"
                    empty-message="No statuses found"
                />
            </div>

            <div>
                <x-select
                    label="Filter by date range"
                    placeholder="Select date range"
                    :options="$dateRanges"
                    wire:model.live="dateRange"
                    option-label="name"
                    option-value="id"
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
