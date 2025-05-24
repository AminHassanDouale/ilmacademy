<?php

use App\Models\Session;
use App\Models\TeacherProfile;
use App\Models\StudentAttendance;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Session Details')] class extends Component {
    use WithPagination;
    use Toast;

    // Session model
    public Session $session;

    // Statistics
    public $totalStudents = 0;
    public $attendedStudents = 0;
    public $absentStudents = 0;
    public $attendancePercentage = 0;

    // Active tab
    public string $activeTab = 'details';

    // Attendance data
    public $attendance = [];

    // Session status data
    public $isUpcoming = false;
    public $isInProgress = false;
    public $isCompleted = false;
    public $canEdit = false;
    public $canMarkAttendance = false;

    public function mount(Session $session): void
    {
        $this->session = $session;

        // Load session with relationships
        $this->session->load(['subject', 'teacherProfile.user']);

        // Check if current teacher is the owner of this session
        $isOwner = Auth::user()->teacherProfile &&
                  Auth::user()->teacherProfile->id === $this->session->teacher_profile_id;

        // Calculate session status
        $this->calculateSessionStatus();

        // Set permissions based on ownership and status
        $this->canEdit = $isOwner && $this->isUpcoming;
        $this->canMarkAttendance = $isOwner && ($this->isInProgress || $this->isCompleted);

        // Load attendance statistics
        $this->loadAttendanceStats();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher viewed session details: ' . $this->session->topic,
            Session::class,
            $this->session->id,
            ['ip' => request()->ip()]
        );
    }

    // Calculate session status based on current time
    private function calculateSessionStatus(): void
    {
        $now = Carbon::now();
        $sessionDate = Carbon::parse($this->session->date);
        $startTime = Carbon::parse($this->session->date . ' ' . $this->session->start_time);
        $endTime = Carbon::parse($this->session->date . ' ' . $this->session->end_time);

        $this->isUpcoming = $startTime->isFuture();
        $this->isInProgress = $startTime->isPast() && $endTime->isFuture();
        $this->isCompleted = $endTime->isPast();
    }

    // Load attendance statistics
    private function loadAttendanceStats(): void
    {
        try {
            // Get enrolled students count
            $this->totalStudents = $this->session->subject->enrolledStudents()->count();

            // Get attendance stats if the session model has a relationship for this
            if (method_exists($this->session, 'attendance')) {
                $this->attendedStudents = $this->session->attendance()
                    ->where('status', 'present')
                    ->count();

                $this->absentStudents = $this->session->attendance()
                    ->where('status', 'absent')
                    ->count();

                // Calculate percentage
                if ($this->totalStudents > 0) {
                    $this->attendancePercentage = round(($this->attendedStudents / $this->totalStudents) * 100);
                }
            }
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading attendance stats: ' . $e->getMessage(),
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );
        }
    }

    // Change active tab
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    // Edit session
    public function editSession(): void
    {
        if ($this->canEdit) {
            return redirect()->route('teacher.sessions.edit', $this->session->id);
        } else {
            $this->error('You cannot edit this session as it has already started or completed.');
        }
    }

    // Mark attendance
    public function markAttendance(): void
    {
        if ($this->canMarkAttendance) {
            return redirect()->route('teacher.sessions.attendance', $this->session->id);
        } else {
            $this->error('You can only mark attendance for ongoing or completed sessions.');
        }
    }

    // Cancel session
    public function cancelSession(): void
    {
        if (!$this->canEdit) {
            $this->error('You cannot cancel this session as it has already started or completed.');
            return;
        }

        try {
            // Update session status
            $this->session->update([
                'status' => 'cancelled',
            ]);

            // Refresh status
            $this->session->refresh();

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'cancel',
                'Teacher cancelled session: ' . $this->session->topic,
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            $this->success('Session has been cancelled successfully.');

            // Redirect back to sessions list
            return redirect()->route('teacher.sessions.index');
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error cancelling session: ' . $e->getMessage(),
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            $this->error('Failed to cancel session: ' . $e->getMessage());
        }
    }

    // Get enrolled students with attendance status
    public function studentsWithAttendance()
    {
        try {
            if (method_exists($this->session, 'attendance') &&
                method_exists($this->session->subject, 'enrolledStudents')) {

                // Get all enrolled students
                $students = $this->session->subject->enrolledStudents()
                    ->with('childProfile.user')
                    ->paginate(10);

                // Get attendance records for this session
                $attendanceRecords = $this->session->attendance()
                    ->pluck('status', 'student_id')
                    ->toArray();

                // Add attendance status to each student
                foreach ($students as $student) {
                    $student->attendance_status = $attendanceRecords[$student->id] ?? 'not_marked';
                }

                return $students;
            }

            return [];
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading students with attendance: ' . $e->getMessage(),
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            return [];
        }
    }

    // Get session materials/resources
    public function sessionMaterials()
    {
        try {
            if (method_exists($this->session, 'materials')) {
                return $this->session->materials;
            }

            return [];
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading session materials: ' . $e->getMessage(),
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            return [];
        }
    }

    public function with(): array
    {
        return [
            'studentsWithAttendance' => $this->studentsWithAttendance(),
            'sessionMaterials' => $this->sessionMaterials(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header :title="$session->topic" separator progress-indicator>
        <x-slot:subtitle>
            {{ $session->subject->name ?? 'Unknown Subject' }} | {{ $session->date->format('d M Y') }} | {{ $session->start_time }} - {{ $session->end_time }}
        </x-slot:subtitle>

        <x-slot:actions>
            @if ($canEdit && $session->status !== 'cancelled')
                <x-button
                    label="Edit Session"
                    icon="o-pencil-square"
                    wire:click="editSession"
                    class="btn-primary"
                />

                <x-button
                    label="Cancel Session"
                    icon="o-x-circle"
                    wire:click="cancelSession"
                    class="btn-error"
                    onclick="confirm('Are you sure you want to cancel this session?') || event.stopImmediatePropagation()"
                />
            @endif

            @if ($canMarkAttendance && $session->status !== 'cancelled')
                <x-button
                    label="Mark Attendance"
                    icon="o-clipboard-document-check"
                    wire:click="markAttendance"
                    class="{{ $isInProgress ? 'btn-warning' : 'btn-primary' }}"
                />
            @endif
        </x-slot:actions>
    </x-header>

    <!-- Session Status -->
    <div class="mb-6">
        @if ($session->status === 'cancelled')
            <div class="p-4 text-white shadow-lg alert bg-error">
                <div>
                    <x-icon name="o-exclamation-triangle" class="w-6 h-6" />
                    <span>This session has been cancelled.</span>
                </div>
            </div>
        @elseif ($isInProgress)
            <div class="p-4 shadow-lg alert bg-warning text-warning-content">
                <div>
                    <x-icon name="o-clock" class="w-6 h-6" />
                    <span>This session is currently in progress.</span>
                </div>
            </div>
        @elseif ($isUpcoming)
            <div class="p-4 shadow-lg alert bg-info text-info-content">
                <div>
                    <x-icon name="o-calendar" class="w-6 h-6" />
                    <span>This session is scheduled for {{ $session->date->format('d M Y') }} at {{ $session->start_time }}.</span>
                </div>
            </div>
        @elseif ($isCompleted)
            <div class="p-4 shadow-lg alert bg-success text-success-content">
                <div>
                    <x-icon name="o-check-circle" class="w-6 h-6" />
                    <span>This session was completed on {{ $session->date->format('d M Y') }}.</span>
                </div>
            </div>
        @endif
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-4">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-users" class="w-8 h-8" />
            </div>
            <div class="stat-title">Total Students</div>
            <div class="stat-value">{{ $totalStudents }}</div>
            <div class="stat-desc">Enrolled in the subject</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Present</div>
            <div class="stat-value text-success">{{ $attendedStudents }}</div>
            <div class="stat-desc">Attended the session</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-error">
                <x-icon name="o-x-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Absent</div>
            <div class="stat-value text-error">{{ $absentStudents }}</div>
            <div class="stat-desc">Missed the session</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-presentation-chart-bar" class="w-8 h-8" />
            </div>
            <div class="stat-title">Attendance</div>
            <div class="stat-value text-info">{{ $attendancePercentage }}%</div>
            <div class="stat-desc">Attendance rate</div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="mb-6 tabs">
        <a
            wire:click="setActiveTab('details')"
            class="tab tab-bordered {{ $activeTab === 'details' ? 'tab-active' }}"
        >
            Session Details
        </a>
        <a
            wire:click="setActiveTab('students')"
            class="tab tab-bordered {{ $activeTab === 'students' ? 'tab-active' }}"
        >
            Students
        </a>
        <a
            wire:click="setActiveTab('materials')"
            class="tab tab-bordered {{ $activeTab === 'materials' ? 'tab-active' }}"
        >
            Materials
        </a>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Details Tab -->
        @if ($activeTab === 'details')
            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <!-- Session Information -->
                <div class="md:col-span-2">
                    <x-card title="Session Information">
                        <div class="space-y-4">
                            <div>
                                <h3 class="font-medium">Subject</h3>
                                <p>{{ $session->subject->name ?? 'Unknown Subject' }} ({{ $session->subject->code ?? 'N/A' }})</p>
                            </div>

                            <div>
                                <h3 class="font-medium">Topic</h3>
                                <p>{{ $session->topic }}</p>
                            </div>

                            <div>
                                <h3 class="font-medium">Description</h3>
                                <div class="prose max-w-none">
                                    @if ($session->description)
                                        {!! nl2br(e($session->description)) !!}
                                    @else
                                        <p class="text-gray-500">No description provided.</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </x-card>

                    <!-- Teacher Notes -->
                    <x-card title="Teacher Notes" class="mt-6">
                        <div class="prose max-w-none">
                            @if (isset($session->teacher_notes) && $session->teacher_notes)
                                {!! nl2br(e($session->teacher_notes)) !!}
                            @else
                                <p class="text-gray-500">No teacher notes have been added yet.</p>

                                @if ($canEdit || $isInProgress || $isCompleted)
                                    <div class="mt-4">
                                        <x-button
                                            label="Add Notes"
                                            icon="o-pencil-square"
                                            href="{{ route('teacher.sessions.edit', $session->id) }}#notes"
                                            class="btn-sm btn-outline"
                                        />
                                    </div>
                                @endif
                            @endif
                        </div>
                    </x-card>
                </div>

                <!-- Session Details -->
                <div>
                    <x-card title="Details">
                        <ul class="space-y-3">
                            <li class="flex justify-between">
                                <span class="text-gray-600">Date:</span>
                                <span class="font-medium">{{ $session->date->format('d F Y') }}</span>
                            </li>
                            <li class="flex justify-between">
                                <span class="text-gray-600">Time:</span>
                                <span class="font-medium">{{ $session->start_time }} - {{ $session->end_time }}</span>
                            </li>
                            <li class="flex justify-between">
                                <span class="text-gray-600">Duration:</span>
                                @php
                                    $startTime = Carbon\Carbon::parse($session->start_time);
                                    $endTime = Carbon\Carbon::parse($session->end_time);
                                    $durationMinutes = $endTime->diffInMinutes($startTime);
                                    $hours = floor($durationMinutes / 60);
                                    $minutes = $durationMinutes % 60;
                                    $duration = ($hours > 0 ? $hours . ' hr ' : '') . ($minutes > 0 ? $minutes . ' min' : '');
                                @endphp
                                <span class="font-medium">{{ $duration }}</span>
                            </li>
                            <li class="flex justify-between">
                                <span class="text-gray-600">Status:</span>
                                <span class="font-medium">
                                    @if ($session->status === 'cancelled')
                                        <x-badge label="Cancelled" color="error" />
                                    @elseif ($isInProgress)
                                        <x-badge label="In Progress" color="warning" />
                                    @elseif ($isUpcoming)
                                        <x-badge label="Upcoming" color="info" />
                                    @elseif ($isCompleted)
                                        <x-badge label="Completed" color="success" />
                                    @endif
                                </span>
                            </li>
                            <li class="flex justify-between">
                                <span class="text-gray-600">Location:</span>
                                <span class="font-medium">
                                    @if ($session->is_online)
                                        <span class="flex items-center">
                                            <x-icon name="o-computer-desktop" class="w-4 h-4 mr-1" />
                                            Online
                                        </span>
                                    @else
                                        <span class="flex items-center">
                                            <x-icon name="o-building-office" class="w-4 h-4 mr-1" />
                                            {{ optional($session->classroom)->name ?? 'Room not specified' }}
                                        </span>
                                    @endif
                                </span>
                            </li>

                            @if ($session->is_online)
                                <li class="flex justify-between">
                                    <span class="text-gray-600">Meeting URL:</span>
                                    <a href="{{ $session->online_meeting_url }}" target="_blank" class="font-medium text-primary hover:underline">
                                        Join Meeting
                                    </a>
                                </li>

                                @if ($session->online_meeting_password)
                                    <li class="flex justify-between">
                                        <span class="text-gray-600">Password:</span>
                                        <span class="font-medium">{{ $session->online_meeting_password }}</span>
                                    </li>
                                @endif
                            @endif

                            <li class="flex justify-between">
                                <span class="text-gray-600">Teacher:</span>
                                <span class="font-medium">{{ optional($session->teacherProfile)->user->name ?? 'Unknown Teacher' }}</span>
                            </li>
                        </ul>
                    </x-card>

                    <!-- Quick Actions -->
                    <x-card title="Actions" class="mt-6">
                        <div class="space-y-2">
                            @if ($canMarkAttendance && $session->status !== 'cancelled')
                                <x-button
                                    label="Mark Attendance"
                                    icon="o-clipboard-document-check"
                                    wire:click="markAttendance"
                                    class="w-full"
                                />
                            @endif

                            @if (method_exists($session, 'materials'))
                                <x-button
                                    label="Upload Materials"
                                    icon="o-paper-clip"
                                    href="{{ route('teacher.sessions.materials', $session->id) }}"
                                    class="w-full btn-outline"
                                />
                            @endif

                            @if ($canEdit && $session->status !== 'cancelled')
                                <x-button
                                    label="Edit Session"
                                    icon="o-pencil-square"
                                    wire:click="editSession"
                                    class="w-full btn-outline"
                                />
                            @endif
                        </div>
                    </x-card>
                </div>
            </div>
        @endif

        <!-- Students Tab -->
        @if ($activeTab === 'students')
            <x-card title="Enrolled Students">
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($studentsWithAttendance as $student)
                                <tr class="hover">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <div class="avatar">
                                                <div class="w-10 h-10 mask mask-squircle">
                                                    @if ($student->childProfile->photo)
                                                        <img src="{{ asset('storage/' . $student->childProfile->photo) }}" alt="{{ $student->childProfile->user->name ?? 'Student' }}">
                                                    @else
                                                        <img src="{{ 'https://ui-avatars.com/api/?name=' . urlencode($student->childProfile->user->name ?? 'Student') . '&color=7F9CF5&background=EBF4FF' }}" alt="{{ $student->childProfile->user->name ?? 'Student' }}">
                                                    @endif
                                                </div>
                                            </div>
                                            <div>
                                                {{ $student->childProfile->user->name ?? 'Unknown Student' }}
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $student->program->name ?? 'Unknown Program' }}</td>
                                    <td>
                                        @if ($student->attendance_status === 'present')
                                            <x-badge label="Present" color="success" />
                                        @elseif ($student->attendance_status === 'absent')
                                            <x-badge label="Absent" color="error" />
                                        @elseif ($student->attendance_status === 'late')
                                            <x-badge label="Late" color="warning" />
                                        @elseif ($student->attendance_status === 'excused')
                                            <x-badge label="Excused" color="info" />
                                        @else
                                            <x-badge label="Not Marked" color="ghost" />
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <x-button
                                                icon="o-user"
                                                color="secondary"
                                                size="sm"
                                                tooltip="View Student Profile"
                                                href="{{ route('teacher.students.show', $student->childProfile->id) }}"
                                            />

                                            @if ($canMarkAttendance && $session->status !== 'cancelled')
                                                <x-button
                                                    icon="o-clipboard-document-check"
                                                    color="primary"
                                                    size="sm"
                                                    tooltip="Mark Attendance"
                                                    wire:click="markAttendance"
                                                />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-8 text-center">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <x-icon name="o-users" class="w-16 h-16 text-gray-400" />
                                            <h3 class="text-lg font-semibold text-gray-600">No students enrolled</h3>
                                            <p class="text-gray-500">There are no students enrolled in this subject yet</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                @if ($studentsWithAttendance instanceof \Illuminate\Pagination\LengthAwarePaginator)
                    <div class="mt-4">
                        {{ $studentsWithAttendance->links() }}
                    </div>
                @endif
            </x-card>
        @endif

        <!-- Materials Tab -->
        @if ($activeTab === 'materials')
            <x-card title="Session Materials">
                <div class="mb-4">
                    <p class="text-sm text-gray-600">Resources and materials for this session:</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Uploaded</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sessionMaterials as $material)
                                <tr class="hover">
                                    <td>{{ $material->title }}</td>
                                    <td>
                                        @php
                                            $icon = 'o-document-text';
                                            $type = 'Document';

                                            if (isset($material->file_type)) {
                                                switch ($material->file_type) {
                                                    case 'pdf':
                                                        $icon = 'o-document-text';
                                                        $type = 'PDF Document';
                                                        break;
                                                    case 'doc':
                                                    case 'docx':
                                                        $icon = 'o-document-text';
                                                        $type = 'Word Document';
                                                        break;
                                                    case 'xls':
                                                    case 'xlsx':
                                                        $icon = 'o-table-cells';
                                                        $type = 'Spreadsheet';
                                                        break;
                                                    case 'ppt':
                                                    case 'pptx':
                                                        $icon = 'o-presentation-chart-bar';
                                                        $type = 'Presentation';
                                                        break;
                                                    case 'jpg':
                                                    case 'jpeg':
                                                    case 'png':
                                                        $icon = 'o-photo';
                                                        $type = 'Image';
                                                        break;
                                                    case 'mp4':
                                                    case 'avi':
                                                    case 'mov':
                                                        $icon = 'o-video-camera';
                                                        $type = 'Video';
                                                        break;
                                                    case 'mp3':
                                                    case 'wav':
                                                        $icon = 'o-musical-note';
                                                        $type = 'Audio';
                                                        break;
                                                    case 'zip':
                                                    case 'rar':
                                                        $icon = 'o-archive-box';
                                                        $type = 'Archive';
                                                        break;
                                                    default:
                                                        $icon = 'o-document-text';
                                                        $type = 'Document';
                                                }
                                            }
                                        @endphp

                                        <div class="flex items-center gap-2">
                                            <x-icon name="{{ $icon }}" class="w-5 h-5" />
                                            <span>{{ $type }}</span>
                                        </div>
                                    </td>
                                    <td>{{ isset($material->created_at) ? $material->created_at->format('d M Y, H:i') : 'Unknown' }}</td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <x-button
                                                icon="o-arrow-down-tray"
                                                color="primary"
                                                size="sm"
                                                tooltip="Download Material"
                                                href="{{ isset($material->file_path) ? asset('storage/' . $material->file_path) : '#' }}"
                                                target="_blank"
                                            />

                                            @if (($canEdit || $isInProgress) && optional(Auth::user()->teacherProfile)->id === $session->teacher_profile_id)
                                                <x-button
                                                    icon="o-trash"
                                                    color="error"
                                                    size="sm"
                                                    tooltip="Delete Material"
                                                    href="{{ route('teacher.materials.delete', $material->id) }}"
                                                    onclick="confirm('Are you sure you want to delete this material?') || event.stopImmediatePropagation()"
                                                />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-8 text-center">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <x-icon name="o-document-text" class="w-16 h-16 text-gray-400" />
                                            <h3 class="text-lg font-semibold text-gray-600">No materials available</h3>
                                            <p class="text-gray-500">No learning materials have been uploaded for this session yet</p>

                                            @if (($canEdit || $isInProgress) && optional(Auth::user()->teacherProfile)->id === $session->teacher_profile_id)
                                                <div class="mt-4">
                                                    <x-button
                                                        label="Upload Materials"
                                                        icon="o-arrow-up-tray"
                                                        href="{{ route('teacher.sessions.materials', $session->id) }}"
                                                        class="btn-primary"
                                                    />
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (($canEdit || $isInProgress) && optional(Auth::user()->teacherProfile)->id === $session->teacher_profile_id)
                    <div class="flex justify-end mt-6">
                        <x-button
                            label="Upload New Material"
                            icon="o-arrow-up-tray"
                            href="{{ route('teacher.sessions.materials', $session->id) }}"
                            class="btn-primary"
                        />
                    </div>
                @endif
            </x-card>
        @endif
    </div>
</div>
