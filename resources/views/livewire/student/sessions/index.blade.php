<?php

use App\Models\User;
use App\Models\ChildProfile;
use App\Models\Session;
use App\Models\ProgramEnrollment;
use App\Models\Subject;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('My Sessions')] class extends Component {
    use WithPagination;
    use Toast;

    // Current user
    public User $user;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $subjectFilter = '';

    #[Url]
    public string $enrollmentFilter = '';

    #[Url]
    public string $dateFilter = '';

    #[Url]
    public int $perPage = 12;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'session_date', 'direction' => 'desc'];

    // View mode
    #[Url]
    public string $viewMode = 'grid'; // 'grid' or 'list'

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $subjectOptions = [];
    public array $enrollmentOptions = [];
    public array $dateOptions = [];

    // Child profiles for students with multiple children (if user is parent)
    public array $childProfiles = [];

    public function mount(): void
    {
        $this->user = Auth::user();

        // Log activity
        ActivityLog::log(
            $this->user->id,
            'access',
            'Accessed student sessions page',
            Session::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadChildProfiles();
        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadChildProfiles(): void
    {
        try {
            if ($this->user->hasRole('student')) {
                // If user is a student, get their own child profile
                $childProfile = ChildProfile::where('user_id', $this->user->id)->first();
                $this->childProfiles = $childProfile ? [$childProfile] : [];
            } else {
                // If user is a parent, get all their children
                $this->childProfiles = ChildProfile::where('parent_id', $this->user->id)
                    ->orderBy('first_name')
                    ->get()
                    ->toArray();
            }
        } catch (\Exception $e) {
            $this->childProfiles = [];
        }
    }

    protected function loadFilterOptions(): void
    {
        // Status options
        $this->statusOptions = [
            ['id' => '', 'name' => 'All Statuses'],
            ['id' => 'scheduled', 'name' => 'Scheduled'],
            ['id' => 'in_progress', 'name' => 'In Progress'],
            ['id' => 'completed', 'name' => 'Completed'],
            ['id' => 'cancelled', 'name' => 'Cancelled'],
            ['id' => 'postponed', 'name' => 'Postponed'],
        ];

        // Date filter options
        $this->dateOptions = [
            ['id' => '', 'name' => 'All Dates'],
            ['id' => 'today', 'name' => 'Today'],
            ['id' => 'tomorrow', 'name' => 'Tomorrow'],
            ['id' => 'this_week', 'name' => 'This Week'],
            ['id' => 'next_week', 'name' => 'Next Week'],
            ['id' => 'this_month', 'name' => 'This Month'],
            ['id' => 'past', 'name' => 'Past Sessions'],
        ];

        try {
            // Get subjects from user's enrollments
            $subjects = Subject::whereHas('subjectEnrollments.programEnrollment.childProfile', function ($query) {
                if ($this->user->hasRole('student')) {
                    $query->where('user_id', $this->user->id);
                } else {
                    $query->where('parent_id', $this->user->id);
                }
            })->orderBy('name')->get();

            $this->subjectOptions = [
                ['id' => '', 'name' => 'All Subjects'],
                ...$subjects->map(fn($subject) => [
                    'id' => $subject->id,
                    'name' => $subject->name . ($subject->code ? " ({$subject->code})" : '')
                ])->toArray()
            ];

            // Get enrollments for the user
            $enrollments = ProgramEnrollment::whereHas('childProfile', function ($query) {
                if ($this->user->hasRole('student')) {
                    $query->where('user_id', $this->user->id);
                } else {
                    $query->where('parent_id', $this->user->id);
                }
            })->with(['curriculum', 'academicYear'])->get();

            $this->enrollmentOptions = [
                ['id' => '', 'name' => 'All Enrollments'],
                ...$enrollments->map(fn($enrollment) => [
                    'id' => $enrollment->id,
                    'name' => ($enrollment->curriculum->name ?? 'Unknown') . ' - ' . ($enrollment->academicYear->name ?? 'Unknown Year')
                ])->toArray()
            ];

        } catch (\Exception $e) {
            $this->subjectOptions = [['id' => '', 'name' => 'All Subjects']];
            $this->enrollmentOptions = [['id' => '', 'name' => 'All Enrollments']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $baseQuery = $this->getBaseSessionsQuery();

            $totalSessions = (clone $baseQuery)->count();
            $scheduledSessions = (clone $baseQuery)->where('status', 'scheduled')->count();
            $completedSessions = (clone $baseQuery)->where('status', 'completed')->count();
            $todaySessions = (clone $baseQuery)->whereDate('session_date', today())->count();
            $upcomingSessions = (clone $baseQuery)
                ->where('status', 'scheduled')
                ->where('session_date', '>', now())
                ->count();

            $this->stats = [
                'total_sessions' => $totalSessions,
                'scheduled_sessions' => $scheduledSessions,
                'completed_sessions' => $completedSessions,
                'today_sessions' => $todaySessions,
                'upcoming_sessions' => $upcomingSessions,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_sessions' => 0,
                'scheduled_sessions' => 0,
                'completed_sessions' => 0,
                'today_sessions' => 0,
                'upcoming_sessions' => 0,
            ];
        }
    }

    protected function getBaseSessionsQuery(): Builder
    {
        // This is a placeholder query since we don't have the Session model defined
        // You'll need to create the Session model with appropriate relationships
        return \DB::table('sessions')
            ->whereExists(function ($query) {
                $query->select(\DB::raw(1))
                    ->from('program_enrollments')
                    ->join('child_profiles', 'program_enrollments.child_profile_id', '=', 'child_profiles.id')
                    ->whereColumn('sessions.program_enrollment_id', 'program_enrollments.id')
                    ->where(function($q) {
                        if ($this->user->hasRole('student')) {
                            $q->where('child_profiles.user_id', $this->user->id);
                        } else {
                            $q->where('child_profiles.parent_id', $this->user->id);
                        }
                    });
            });
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

    // Toggle view mode
    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode;
    }

    // Redirect to session show page
    public function redirectToShow(int $sessionId): void
    {
        $this->redirect(route('student.sessions.show', $sessionId));
    }

    // Filter update methods
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEnrollmentFilter(): void
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
        $this->statusFilter = '';
        $this->subjectFilter = '';
        $this->enrollmentFilter = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated sessions
    public function sessions(): LengthAwarePaginator
    {
        // Placeholder implementation - you'll need to update this based on your Session model
        $query = $this->getBaseSessionsQuery();

        // Apply filters
        if ($this->search) {
            $query->where(function($q) {
                $q->where('title', 'like', "%{$this->search}%")
                  ->orWhere('description', 'like', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->dateFilter) {
            switch ($this->dateFilter) {
                case 'today':
                    $query->whereDate('session_date', today());
                    break;
                case 'tomorrow':
                    $query->whereDate('session_date', today()->addDay());
                    break;
                case 'this_week':
                    $query->whereBetween('session_date', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'next_week':
                    $query->whereBetween('session_date', [now()->addWeek()->startOfWeek(), now()->addWeek()->endOfWeek()]);
                    break;
                case 'this_month':
                    $query->whereMonth('session_date', now()->month)
                          ->whereYear('session_date', now()->year);
                    break;
                case 'past':
                    $query->where('session_date', '<', now());
                    break;
            }
        }

        // Apply sorting
        $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);

        return $query->paginate($this->perPage);
    }

    // Helper function to get status color
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'scheduled' => 'bg-blue-100 text-blue-800',
            'in_progress' => 'bg-yellow-100 text-yellow-800',
            'completed' => 'bg-green-100 text-green-800',
            'cancelled' => 'bg-red-100 text-red-800',
            'postponed' => 'bg-orange-100 text-orange-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to format session time
    private function formatSessionTime($session): string
    {
        try {
            $date = \Carbon\Carbon::parse($session->session_date ?? $session['session_date']);
            $startTime = $session->start_time ?? $session['start_time'] ?? null;
            $endTime = $session->end_time ?? $session['end_time'] ?? null;

            $formatted = $date->format('M d, Y');

            if ($startTime && $endTime) {
                $formatted .= ' • ' . date('g:i A', strtotime($startTime)) . ' - ' . date('g:i A', strtotime($endTime));
            } elseif ($startTime) {
                $formatted .= ' • ' . date('g:i A', strtotime($startTime));
            }

            return $formatted;
        } catch (\Exception $e) {
            return 'Date not available';
        }
    }

    public function with(): array
    {
        return [
            'sessions' => $this->sessions(),
        ];
    }
};?>

