<?php

use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Teacher Profile')] class extends Component {
    use Toast;

    // Teacher to display
    public TeacherProfile $teacher;

    // Loaded relationships
    public $sessions;
    public $exams;
    public $timetableSlots;

    // Toggle states for sections
    public bool $showBio = true;
    public bool $showSessions = true;
    public bool $showExams = true;
    public bool $showTimetable = true;

    // Component initialization
    public function mount(TeacherProfile $teacher): void
    {
        $this->teacher = $teacher;

        // Just load the relations without any filtering
        $this->sessions = $teacher->sessions()->latest()->take(5)->get();
        $this->exams = $teacher->exams()->latest()->take(5)->get();
        $this->timetableSlots = $teacher->timetableSlots()->latest()->take(10)->get();

        // Log access to view page
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'access',
            'description' => "Viewed teacher profile: " . ($teacher->user ? $teacher->user->name : 'Unknown'),
            'loggable_type' => TeacherProfile::class,
            'loggable_id' => $teacher->id,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Edit this teacher
     */
    public function edit(): void
    {
        $this->redirect(route('admin.teachers.edit', $this->teacher));
    }

    /**
     * Go back to teachers list
     */
    public function backToList(): void
    {
        $this->redirect(route('admin.teachers.index'));
    }

    /**
     * Toggle active status
     */
    public function toggleStatus(): void
    {
        try {
            $user = $this->teacher->user;

            if (!$user) {
                $this->error("Teacher profile has no associated user account.");
                return;
            }

            $newStatus = $user->status === 'active' ? 'inactive' : 'active';
            $statusText = $newStatus === 'active' ? 'activated' : 'deactivated';

            $user->status = $newStatus;
            $user->save();

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'update',
                'description' => "Changed teacher status to {$statusText}: {$user->name}",
                'loggable_type' => TeacherProfile::class,
                'loggable_id' => $this->teacher->id,
                'ip_address' => request()->ip(),
                'additional_data' => [
                    'old_status' => $newStatus === 'active' ? 'inactive' : 'active',
                    'new_status' => $newStatus
                ]
            ]);

            $this->success("Teacher status has been changed to {$statusText}.");

        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    /**
     * Get teacher's activity logs
     */
    public function activityLogs()
    {
        return ActivityLog::where(function ($query) {
                $query->where('loggable_type', TeacherProfile::class)
                      ->where('loggable_id', $this->teacher->id);
            })
            ->orWhere(function ($query) {
                $query->where('loggable_type', User::class)
                      ->where('loggable_id', $this->teacher->user_id);
            })
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();
    }

    /**
     * Calculate basic session stats without problematic queries
     */
    public function sessionStats()
    {
        $totalSessions = $this->teacher->sessions()->count();

        return [
            'total' => $totalSessions,
            'upcoming' => 0,
            'completed' => 0,
            'completion_rate' => 0
        ];
    }

    /**
     * Calculate basic exam stats without problematic queries
     */
    public function examStats()
    {
        $totalExams = $this->teacher->exams()->count();

        return [
            'total' => $totalExams,
            'upcoming' => 0,
            'graded' => 0,
            'grading_rate' => 0
        ];
    }

    public function with(): array
    {
        return [
            'activity_logs' => $this->activityLogs(),
            'session_stats' => $this->sessionStats(),
            'exam_stats' => $this->examStats(),
        ];
    }
};
?>

<div>
    <x-header title="Teacher Profile" separator back="{{ route('admin.teachers.index') }}">
        <x-slot:actions>
            <div class="flex gap-2">
                <x-button
                    icon="{{ $teacher->user && $teacher->user->status === 'active' ? 'o-x-circle' : 'o-check-circle' }}"
                    wire:click="toggleStatus"
                    color="{{ $teacher->user && $teacher->user->status === 'active' ? 'warning' : 'success' }}"
                    label="{{ $teacher->user && $teacher->user->status === 'active' ? 'Deactivate' : 'Activate' }}"
                />
                <x-button label="Edit" icon="o-pencil" wire:click="edit" class="btn-primary" />
            </div>
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 mb-6 lg:grid-cols-3">
        <!-- Profile sidebar -->
        <div class="lg:col-span-1">
            <!-- Profile card -->
            <x-card class="mb-6">
                <div class="flex flex-col items-center p-4 text-center">
                    <div class="mb-4 avatar">
                        <div class="w-32 h-32 rounded-full ring ring-primary ring-offset-base-100 ring-offset-2">
                            @if ($teacher->user)
                                <img src="{{ $teacher->user->profile_photo_url }}" alt="{{ $teacher->user->name }}">
                            @else
                                <div class="flex items-center justify-center w-32 h-32 rounded-full bg-base-200">
                                    <x-icon name="o-user" class="w-16 h-16 text-base-content/30" />
                                </div>
                            @endif
                        </div>
                    </div>
                    <h2 class="text-2xl font-bold">{{ $teacher->user ? $teacher->user->name : 'Unknown' }}</h2>
                    <p class="mb-2 text-gray-500">{{ $teacher->user ? $teacher->user->email : 'No email' }}</p>

                    <div class="badge badge-lg {{ $teacher->user && $teacher->user->status === 'active' ? 'badge-success' : 'badge-warning' }} my-2">
                        {{ $teacher->user && $teacher->user->status === 'active' ? 'Active' : 'Inactive' }}
                    </div>

                    <div class="my-4 divider"></div>

                    <div class="w-full">
                        @if($teacher->specialization)
                            <div class="flex items-center gap-2 mb-2">
                                <x-icon name="o-academic-cap" class="w-5 h-5 text-primary" />
                                <span>{{ $teacher->specialization }}</span>
                            </div>
                        @endif

                        @if($teacher->phone)
                            <div class="flex items-center gap-2 mb-2">
                                <x-icon name="o-phone" class="w-5 h-5 text-primary" />
                                <span>{{ $teacher->phone }}</span>
                            </div>
                        @endif

                        @if($teacher->user)
                            <div class="flex items-center gap-2 mb-2">
                                <x-icon name="o-calendar" class="w-5 h-5 text-primary" />
                                <span>Joined {{ $teacher->created_at->format('d/m/Y') }}</span>
                            </div>

                            @if($teacher->user->last_login_at)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-clock" class="w-5 h-5 text-primary" />
                                    <span>Last login {{ $teacher->user->last_login_at->diffForHumans() }}</span>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Stats card -->
            <x-card title="Basic Information" class="mb-6">
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-2 stat">
                        <div class="stat-title">Sessions</div>
                        <div class="text-lg stat-value">{{ $session_stats['total'] }}</div>
                    </div>

                    <div class="p-2 stat">
                        <div class="stat-title">Exams</div>
                        <div class="text-lg stat-value">{{ $exam_stats['total'] }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Activity log card -->
            <x-card title="Recent Activity">
                <div class="overflow-auto max-h-96">
                    @forelse($activity_logs as $log)
                        <div class="flex items-start gap-2 pb-3 mb-3 border-b border-base-300 last:border-0 last:mb-0 last:pb-0">
                            <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0
                                {{ match($log->action) {
                                    'access' => 'bg-info/10 text-info',
                                    'create' => 'bg-success/10 text-success',
                                    'update' => 'bg-warning/10 text-warning',
                                    'delete' => 'bg-error/10 text-error',
                                    'email' => 'bg-primary/10 text-primary',
                                    default => 'bg-secondary/10 text-secondary'
                                } }}">
                                <x-icon name="{{ match($log->action) {
                                    'access' => 'o-eye',
                                    'create' => 'o-plus',
                                    'update' => 'o-pencil',
                                    'delete' => 'o-trash',
                                    'email' => 'o-envelope',
                                    default => 'o-document-text'
                                } }}" class="w-4 h-4" />
                            </div>

                            <div class="flex-grow">
                                <div class="text-sm">{{ $log->description }}</div>
                                <div class="text-xs text-gray-500">{{ $log->created_at->diffForHumans() }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="py-4 text-center text-gray-500">
                            <x-icon name="o-document-text" class="w-10 h-10 mx-auto mb-2" />
                            <p>No recent activity recorded</p>
                        </div>
                    @endforelse
                </div>
            </x-card>
        </div>

        <!-- Main content -->
        <div class="lg:col-span-2">
            <!-- Bio section -->
            <x-card class="mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">Teacher Biography</h3>
                    <x-button icon="{{ $showBio ? 'o-chevron-up' : 'o-chevron-down' }}" wire:click="$toggle('showBio')" size="sm" class="btn-ghost" />
                </div>

                @if($showBio)
                    <div class="prose max-w-none">
                        @if($teacher->bio)
                            <p>{{ $teacher->bio }}</p>
                        @else
                            <p class="italic text-gray-500">No biography provided.</p>
                        @endif
                    </div>
                @endif
            </x-card>

            <!-- Sessions section -->
            <x-card title="Recent Sessions" class="mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">Recent Sessions</h3>
                    <div class="flex gap-2">
                        <x-button icon="{{ $showSessions ? 'o-chevron-up' : 'o-chevron-down' }}" wire:click="$toggle('showSessions')" size="sm" class="btn-ghost" />
                        <x-button icon="o-arrow-right" label="View All"  size="sm" />
                    </div>
                </div>

                @if($showSessions)
                    <div class="overflow-x-auto">
                        <table class="table w-full table-zebra">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($sessions as $session)
                                    <tr>
                                        <td class="font-semibold">{{ $session->title }}</td>
                                        <td>
                                            <div class="flex flex-col">
                                                @if(isset($session->start_time))
                                                    <span>{{ $session->start_time->format('d/m/Y H:i') }}</span>
                                                @endif
                                                @if(isset($session->duration))
                                                    <span class="text-xs">Duration: {{ $session->duration }} min</span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="py-4 text-center">
                                            <div class="flex flex-col items-center justify-center gap-2">
                                                <x-icon name="o-calendar" class="w-10 h-10 text-gray-400" />
                                                <p class="text-gray-500">No sessions found</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <!-- Exams section -->
            <x-card title="Recent Exams" class="mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">Recent Exams</h3>
                    <div class="flex gap-2">
                        <x-button icon="{{ $showExams ? 'o-chevron-up' : 'o-chevron-down' }}" wire:click="$toggle('showExams')" size="sm" class="btn-ghost" />
                        <x-button icon="o-arrow-right" label="View All"  size="sm" />
                    </div>
                </div>

                @if($showExams)
                    <div class="overflow-x-auto">
                        <table class="table w-full table-zebra">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($exams as $exam)
                                    <tr>
                                        <td class="font-semibold">{{ $exam->title }}</td>
                                        <td>
                                            <div class="flex flex-col">
                                                @if(isset($exam->exam_date))
                                                    <span>{{ \Carbon\Carbon::parse($exam->exam_date)->format('d/m/Y') }}</span>
                                                @endif
                                                @if(isset($exam->description))
                                                    <span class="text-xs">{{ \Illuminate\Support\Str::limit($exam->description, 50) }}</span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="py-4 text-center">
                                            <div class="flex flex-col items-center justify-center gap-2">
                                                <x-icon name="o-academic-cap" class="w-10 h-10 text-gray-400" />
                                                <p class="text-gray-500">No exams found</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <!-- Timetable section -->
            <x-card title="Schedule">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">Schedule Information</h3>
                    <div class="flex gap-2">
                        <x-button icon="{{ $showTimetable ? 'o-chevron-up' : 'o-chevron-down' }}" wire:click="$toggle('showTimetable')" size="sm" class="btn-ghost" />
                        <x-button icon="o-arrow-right" label="View Full"  size="sm" />
                    </div>
                </div>

                @if($showTimetable)
                    <div class="overflow-x-auto">
                        <table class="table w-full table-zebra">
                            <thead>
                                <tr>
                                    <th>Details</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($timetableSlots as $slot)
                                    <tr>
                                        <td>
                                            <div class="font-semibold">{{ $slot->title ?? 'Class Session' }}</div>
                                            <div class="text-xs">{{ $slot->description ?? '' }}</div>
                                        </td>
                                        <td>
                                            @if(isset($slot->start_time) && isset($slot->end_time))
                                                <div>{{ \Carbon\Carbon::parse($slot->start_time)->format('d/m/Y') }}</div>
                                                <div class="text-xs">
                                                    {{ \Carbon\Carbon::parse($slot->start_time)->format('H:i') }} -
                                                    {{ \Carbon\Carbon::parse($slot->end_time)->format('H:i') }}
                                                </div>
                                            @else
                                                <div>Time not specified</div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="py-4 text-center">
                                            <div class="flex flex-col items-center justify-center gap-2">
                                                <x-icon name="o-clock" class="w-10 h-10 text-gray-400" />
                                                <p class="text-gray-500">No schedule information found</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</div>
