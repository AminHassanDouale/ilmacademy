<?php

use App\Models\Session;
use App\Models\TeacherProfile;
use App\Models\Attendance;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Session Details')] class extends Component {
    use Toast;

    // Model instances
    public Session $session;
    public ?TeacherProfile $teacherProfile = null;

    // Data collections
    public $attendanceRecords = [];

    // Stats
    public array $stats = [];

    // Mount the component
    public function mount(Session $session): void
    {
        $this->session = $session->load(['subject', 'subject.curriculum', 'teacherProfile', 'attendances', 'attendances.childProfile']);
        $this->teacherProfile = Auth::user()->teacherProfile;

        if (!$this->teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            $this->redirect(route('teacher.profile.edit'));
            return;
        }

        // Check if teacher owns this session
        if ($this->session->teacher_profile_id !== $this->teacherProfile->id) {
            $this->error('You are not authorized to view this session.');
            $this->redirect(route('teacher.sessions.index'));
            return;
        }

        Log::info('Session Show Component Mounted', [
            'teacher_user_id' => Auth::id(),
            'session_id' => $session->id,
            'subject_id' => $session->subject_id,
            'ip' => request()->ip()
        ]);

        $this->loadAttendanceData();
        $this->loadStats();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed session details: {$session->subject->name} session on {$session->start_time->format('M d, Y \a\t g:i A')}",
            Session::class,
            $session->id,
            [
                'session_id' => $session->id,
                'subject_name' => $session->subject->name,
                'session_start' => $session->start_time->toDateTimeString(),
                'ip' => request()->ip()
            ]
        );
    }

    protected function loadAttendanceData(): void
    {
        try {
            $this->attendanceRecords = $this->session->attendances()
                ->with(['childProfile', 'childProfile.user'])
                ->orderBy('created_at', 'desc')
                ->get();

            Log::info('Attendance Data Loaded', [
                'session_id' => $this->session->id,
                'attendance_count' => $this->attendanceRecords->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to load attendance data', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage()
            ]);

            $this->attendanceRecords = collect();
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalAttendees = $this->attendanceRecords->count();
            $presentCount = $this->attendanceRecords->where('status', 'present')->count();
            $absentCount = $this->attendanceRecords->where('status', 'absent')->count();
            $lateCount = $this->attendanceRecords->where('status', 'late')->count();
            $excusedCount = $this->attendanceRecords->where('status', 'excused')->count();

            $attendanceRate = $totalAttendees > 0 ? round(($presentCount + $lateCount) / $totalAttendees * 100, 1) : 0;

            $this->stats = [
                'total_attendees' => $totalAttendees,
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'late_count' => $lateCount,
                'excused_count' => $excusedCount,
                'attendance_rate' => $attendanceRate,
            ];

        } catch (\Exception $e) {
            $this->stats = [
                'total_attendees' => 0,
                'present_count' => 0,
                'absent_count' => 0,
                'late_count' => 0,
                'excused_count' => 0,
                'attendance_rate' => 0,
            ];
        }
    }

    // Get session status
    public function getSessionStatusProperty(): array
    {
        $now = now();

        if ($this->session->start_time > $now) {
            return ['status' => 'upcoming', 'color' => 'bg-blue-100 text-blue-800', 'text' => 'Upcoming'];
        } elseif ($this->session->start_time <= $now && $this->session->end_time >= $now) {
            return ['status' => 'ongoing', 'color' => 'bg-green-100 text-green-800', 'text' => 'Ongoing'];
        } else {
            return ['status' => 'completed', 'color' => 'bg-gray-100 text-gray-600', 'text' => 'Completed'];
        }
    }

    // Get session type color
    public function getSessionTypeColor(string $type): string
    {
        return match(strtolower($type)) {
            'lecture' => 'bg-blue-100 text-blue-800',
            'practical' => 'bg-green-100 text-green-800',
            'tutorial' => 'bg-purple-100 text-purple-800',
            'lab' => 'bg-orange-100 text-orange-800',
            'seminar' => 'bg-indigo-100 text-indigo-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Get attendance status color
    public function getAttendanceStatusColor(string $status): string
    {
        return match(strtolower($status)) {
            'present' => 'bg-green-100 text-green-800',
            'absent' => 'bg-red-100 text-red-800',
            'late' => 'bg-yellow-100 text-yellow-800',
            'excused' => 'bg-blue-100 text-blue-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Navigation methods
    public function redirectToEdit(): void
    {
        $this->redirect(route('teacher.sessions.edit', $this->session->id));
    }

    public function redirectToTakeAttendance(): void
    {
        $this->redirect(route('teacher.attendance.take', $this->session->id));
    }

    public function redirectToSessionsList(): void
    {
        $this->redirect(route('teacher.sessions.index'));
    }

    public function redirectToSubjectShow(): void
    {
        $this->redirect(route('teacher.subjects.show', $this->session->subject_id));
    }

    // Format duration
    public function getDurationProperty(): string
    {
        if ($this->session->start_time && $this->session->end_time) {
            $duration = $this->session->start_time->diffInMinutes($this->session->end_time);

            if ($duration >= 60) {
                $hours = floor($duration / 60);
                $minutes = $duration % 60;
                return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
            }

            return "{$duration}m";
        }

        return 'Unknown';
    }

    public function with(): array
    {
        return [
            'sessionStatus' => $this->sessionStatus,
            'duration' => $this->duration,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Session: {{ $session->subject->name }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Session Status -->
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $sessionStatus['color'] }}">
                {{ $sessionStatus['text'] }}
            </span>
        </x-slot:middle>

        <x-slot:actions>
            @if($sessionStatus['status'] === 'upcoming' || $sessionStatus['status'] === 'ongoing')
                <x-button
                    label="Take Attendance"
                    icon="o-clipboard-document-check"
                    wire:click="redirectToTakeAttendance"
                    class="btn-primary"
                />
            @endif

            <x-button
                label="Edit Session"
                icon="o-pencil"
                wire:click="redirectToEdit"
                class="btn-secondary"
            />

            <x-button
                label="Back to Sessions"
                icon="o-arrow-left"
                wire:click="redirectToSessionsList"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Main Content -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Session Information -->
            <x-card title="Session Information">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject</div>
                        <div class="text-lg font-semibold">{{ $session->subject->name }}</div>
                        <div class="text-sm text-gray-600">{{ $session->subject->code }}</div>
                        @if($session->subject->curriculum)
                            <div class="text-xs text-gray-500">{{ $session->subject->curriculum->name }}</div>
                        @endif
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Session Type</div>
                        <div>
                            @if($session->type)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getSessionTypeColor($session->type) }}">
                                    {{ ucfirst($session->type) }}
                                </span>
                            @else
                                <span class="text-gray-500">Not specified</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Date & Time</div>
                        <div class="font-semibold">{{ $session->start_time->format('l, M d, Y') }}</div>
                        <div class="text-sm text-gray-600">
                            {{ $session->start_time->format('g:i A') }}
                            @if($session->end_time)
                                - {{ $session->end_time->format('g:i A') }}
                            @endif
                        </div>
                        <div class="text-xs text-gray-500">Duration: {{ $duration }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $sessionStatus['color'] }}">
                                {{ $sessionStatus['text'] }}
                            </span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            @if($sessionStatus['status'] === 'upcoming')
                                Starts {{ $session->start_time->diffForHumans() }}
                            @elseif($sessionStatus['status'] === 'ongoing')
                                Ends {{ $session->end_time->diffForHumans() }}
                            @else
                                Ended {{ $session->end_time->diffForHumans() }}
                            @endif
                        </div>
                    </div>

                    @if($session->classroom_id)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Room</div>
                            <div class="font-medium">{{ $session->room ? $session->room->name : 'Room #' . $session->classroom_id }}</div>
                            @if($session->room)
                                <div class="text-sm text-gray-600">
                                    @if($session->room->location)
                                        {{ $session->room->location }}
                                    @endif
                                    @if($session->room->building)
                                        • {{ $session->room->building }}
                                    @endif
                                </div>
                                <div class="text-xs text-gray-500">Capacity: {{ $session->room->capacity }}</div>
                            @endif
                        </div>
                    @endif

                    @if($session->link)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Online Link</div>
                            <div>
                                <a
                                    href="{{ $session->link }}"
                                    target="_blank"
                                    class="text-sm text-blue-600 break-all hover:text-blue-800"
                                >
                                    <x-icon name="o-link" class="inline w-4 h-4 mr-1" />
                                    Join Session
                                </a>
                            </div>
                        </div>
                    @endif
                </div>

                @if($session->description)
                    <div class="pt-4 mt-4 border-t">
                        <div class="mb-2 text-sm font-medium text-gray-500">Description</div>
                        <div class="p-3 text-sm text-gray-600 rounded-md bg-gray-50">
                            {{ $session->description }}
                        </div>
                    </div>
                @endif
            </x-card>

            <!-- Attendance Overview -->
            <x-card title="Attendance Overview">
                @if($stats['total_attendees'] > 0)
                    <!-- Attendance Stats -->
                    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
                        <div class="p-4 text-center rounded-lg bg-green-50">
                            <div class="text-2xl font-bold text-green-600">{{ $stats['present_count'] }}</div>
                            <div class="text-sm text-green-600">Present</div>
                        </div>

                        <div class="p-4 text-center rounded-lg bg-yellow-50">
                            <div class="text-2xl font-bold text-yellow-600">{{ $stats['late_count'] }}</div>
                            <div class="text-sm text-yellow-600">Late</div>
                        </div>

                        <div class="p-4 text-center rounded-lg bg-red-50">
                            <div class="text-2xl font-bold text-red-600">{{ $stats['absent_count'] }}</div>
                            <div class="text-sm text-red-600">Absent</div>
                        </div>

                        <div class="p-4 text-center rounded-lg bg-blue-50">
                            <div class="text-2xl font-bold text-blue-600">{{ $stats['excused_count'] }}</div>
                            <div class="text-sm text-blue-600">Excused</div>
                        </div>
                    </div>

                    <!-- Attendance Rate -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-gray-700">Attendance Rate</span>
                            <span class="text-sm font-bold text-gray-900">{{ $stats['attendance_rate'] }}%</span>
                        </div>
                        <div class="w-full h-2 bg-gray-200 rounded-full">
                            <div class="h-2 bg-green-600 rounded-full" style="width: {{ $stats['attendance_rate'] }}%"></div>
                        </div>
                    </div>

                    <!-- Attendance Records -->
                    <div class="space-y-3">
                        <div class="text-sm font-medium text-gray-700">Student Attendance</div>
                        @foreach($attendanceRecords as $attendance)
                            <div class="flex items-center justify-between p-3 border rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="avatar">
                                        <div class="w-8 h-8 rounded-full">
                                            <img src="{{ $attendance->childProfile->user->profile_photo_url ?? 'https://ui-avatars.com/api/?name=' . urlencode($attendance->childProfile->full_name) }}" alt="{{ $attendance->childProfile->full_name }}" />
                                        </div>
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ $attendance->childProfile->full_name }}</div>
                                        @if($attendance->notes)
                                            <div class="text-xs text-gray-500">{{ $attendance->notes }}</div>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getAttendanceStatusColor($attendance->status) }}">
                                        {{ ucfirst($attendance->status) }}
                                    </span>
                                    @if($attendance->check_in_time)
                                        <div class="text-xs text-gray-500">
                                            {{ $attendance->check_in_time->format('g:i A') }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-clipboard-document-check" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No attendance records</div>
                        @if($sessionStatus['status'] === 'upcoming' || $sessionStatus['status'] === 'ongoing')
                            <x-button
                                label="Take Attendance"
                                icon="o-plus"
                                wire:click="redirectToTakeAttendance"
                                class="mt-2 btn-primary btn-sm"
                            />
                        @else
                            <p class="mt-1 text-xs text-gray-400">Attendance was not taken for this session</p>
                        @endif
                    </div>
                @endif
            </x-card>
        </div>

        <!-- Right column (1/3) - Sidebar -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-3">
                    @if($sessionStatus['status'] === 'upcoming' || $sessionStatus['status'] === 'ongoing')
                        <x-button
                            label="Take Attendance"
                            icon="o-clipboard-document-check"
                            wire:click="redirectToTakeAttendance"
                            class="w-full btn-primary"
                        />
                    @endif

                    <x-button
                        label="Edit Session"
                        icon="o-pencil"
                        wire:click="redirectToEdit"
                        class="w-full btn-secondary"
                    />

                    @if($session->link)
                        <a
                            href="{{ $session->link }}"
                            target="_blank"
                            class="w-full btn btn-outline"
                        >
                            <x-icon name="o-link" class="w-4 h-4 mr-2" />
                            Join Online Session
                        </a>
                    @endif

                    <x-button
                        label="View Subject"
                        icon="o-academic-cap"
                        wire:click="redirectToSubjectShow"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="All Sessions"
                        icon="o-presentation-chart-line"
                        wire:click="redirectToSessionsList"
                        class="w-full btn-outline"
                    />
                </div>
            </x-card>

            <!-- Session Statistics -->
            <x-card title="Session Statistics">
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Total Students:</span>
                        <span class="font-medium">{{ $stats['total_attendees'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Present:</span>
                        <span class="font-medium text-green-600">{{ $stats['present_count'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Late:</span>
                        <span class="font-medium text-yellow-600">{{ $stats['late_count'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Absent:</span>
                        <span class="font-medium text-red-600">{{ $stats['absent_count'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Excused:</span>
                        <span class="font-medium text-blue-600">{{ $stats['excused_count'] }}</span>
                    </div>
                    <div class="pt-2 border-t">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Attendance Rate:</span>
                            <span class="font-bold text-gray-900">{{ $stats['attendance_rate'] }}%</span>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Session Details -->
            <x-card title="Session Details">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $session->created_at->format('M d, Y \a\t g:i A') }}</div>
                        <div class="text-xs text-gray-400">{{ $session->created_at->diffForHumans() }}</div>
                    </div>

                    @if($session->updated_at->ne($session->created_at))
                        <div>
                            <div class="font-medium text-gray-500">Last Updated</div>
                            <div>{{ $session->updated_at->format('M d, Y \a\t g:i A') }}</div>
                            <div class="text-xs text-gray-400">{{ $session->updated_at->diffForHumans() }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="font-medium text-gray-500">Session ID</div>
                        <div class="font-mono text-xs">#{{ $session->id }}</div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
