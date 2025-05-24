<?php

use App\Models\Session;
use App\Models\Attendance;
use App\Models\Subject;
use App\Models\ChildProfile;
use App\Models\SubjectEnrollment;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Rule;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Take Attendance')] class extends Component {
    use Toast;

    // Session data
    public Session $session;

    // Session status
    public bool $isCompleted = false;
    public bool $isInProgress = false;

    // Attendance data
    public array $students = [];
    public array $attendance = [];
    public array $notes = [];

    // Statistics
    public $totalStudents = 0;
    public $presentCount = 0;
    public $absentCount = 0;
    public $lateCount = 0;
    public $excusedCount = 0;
    public $attendanceRate = 0;

    public function mount(Session $session): void
    {
        $this->session = $session;

        // Check if teacher owns this session
        $teacherProfile = Auth::user()->teacherProfile;
        if (!$teacherProfile || $teacherProfile->id !== $session->teacher_profile_id) {
            $this->error('You are not authorized to manage attendance for this session.');
            redirect()->route('teacher.sessions.index');
            return;
        }

        // Check if session is completed or in progress
        $now = Carbon::now();
        $sessionDate = Carbon::parse($session->date);
        $startTime = Carbon::parse($session->date . ' ' . $session->start_time);
        $endTime = Carbon::parse($session->date . ' ' . $session->end_time);

        $this->isInProgress = $startTime->isPast() && $endTime->isFuture();
        $this->isCompleted = $endTime->isPast();

        if (!$this->isInProgress && !$this->isCompleted) {
            $this->error('You cannot mark attendance for an upcoming session.');
            redirect()->route('teacher.sessions.show', $session->id);
            return;
        }

        // Load enrolled students
        $this->loadStudents();

        // Load existing attendance
        $this->loadAttendance();

        // Calculate statistics
        $this->calculateStats();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed attendance taking for session: ' . $session->topic,
            Session::class,
            $session->id,
            ['ip' => request()->ip()]
        );
    }

    // Load students
    private function loadStudents(): void
    {
        try {
            $this->students = [];

            if (method_exists($this->session->subject, 'enrolledStudents')) {
                $enrolledStudents = $this->session->subject->enrolledStudents()
                    ->with(['childProfile.user'])
                    ->get();

                foreach ($enrolledStudents as $enrollment) {
                    if ($enrollment->childProfile && $enrollment->childProfile->user) {
                        $this->students[] = [
                            'id' => $enrollment->childProfile->id,
                            'name' => $enrollment->childProfile->user->name,
                            'photo' => $enrollment->childProfile->photo,
                            'profile_photo_url' => $enrollment->childProfile->user->profile_photo_url ?? null,
                            'program' => $enrollment->programEnrollment->program->name ?? 'Unknown Program'
                        ];
                    }
                }
            } else {
                // Fallback to get students from subject enrollments
                $subjectEnrollments = SubjectEnrollment::where('subject_id', $this->session->subject_id)
                    ->with(['programEnrollment.childProfile.user', 'programEnrollment.program'])
                    ->get();

                foreach ($subjectEnrollments as $enrollment) {
                    if ($enrollment->programEnrollment && $enrollment->programEnrollment->childProfile) {
                        $child = $enrollment->programEnrollment->childProfile;
                        if ($child->user) {
                            $this->students[] = [
                                'id' => $child->id,
                                'name' => $child->user->name,
                                'photo' => $child->photo,
                                'profile_photo_url' => $child->user->profile_photo_url ?? null,
                                'program' => $enrollment->programEnrollment->program->name ?? 'Unknown Program'
                            ];
                        }
                    }
                }
            }

            // Sort students by name
            usort($this->students, function($a, $b) {
                return $a['name'] <=> $b['name'];
            });

            $this->totalStudents = count($this->students);

            // Initialize attendance array with default values
            foreach ($this->students as $student) {
                $this->attendance[$student['id']] = 'not_marked';
                $this->notes[$student['id']] = '';
            }
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading students: ' . $e->getMessage(),
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            $this->error('An error occurred while loading student data.');
        }
    }

    // Load existing attendance records
    private function loadAttendance(): void
    {
        try {
            $attendanceRecords = Attendance::where('session_id', $this->session->id)->get();

            foreach ($attendanceRecords as $record) {
                if (isset($this->attendance[$record->child_profile_id])) {
                    $this->attendance[$record->child_profile_id] = $record->status;
                    $this->notes[$record->child_profile_id] = $record->notes ?? '';
                }
            }

            $this->calculateStats();
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading attendance records: ' . $e->getMessage(),
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            $this->error('An error occurred while loading attendance records.');
        }
    }

    // Calculate attendance statistics
    private function calculateStats(): void
    {
        $this->presentCount = 0;
        $this->absentCount = 0;
        $this->lateCount = 0;
        $this->excusedCount = 0;

        foreach ($this->attendance as $status) {
            if ($status === 'present') {
                $this->presentCount++;
            } elseif ($status === 'absent') {
                $this->absentCount++;
            } elseif ($status === 'late') {
                $this->lateCount++;
            } elseif ($status === 'excused') {
                $this->excusedCount++;
            }
        }

        $this->attendanceRate = $this->totalStudents > 0
            ? round((($this->presentCount + $this->lateCount) / $this->totalStudents) * 100)
            : 0;
    }

    // Update attendance status for a student
    public function updateStatus($studentId, $status): void
    {
        if (isset($this->attendance[$studentId])) {
            $this->attendance[$studentId] = $status;
            $this->calculateStats();
        }
    }

    // Update note for a student
    public function updateNote($studentId, $note): void
    {
        if (isset($this->notes[$studentId])) {
            $this->notes[$studentId] = $note;
        }
    }

    // Mark all students as present
    public function markAllPresent(): void
    {
        foreach ($this->students as $student) {
            $this->attendance[$student['id']] = 'present';
        }
        $this->calculateStats();
        $this->success('All students marked as present.');
    }

    // Mark all students as absent
    public function markAllAbsent(): void
    {
        foreach ($this->students as $student) {
            $this->attendance[$student['id']] = 'absent';
        }
        $this->calculateStats();
        $this->success('All students marked as absent.');
    }

    // Mark all unmarked students as absent
    public function markRemainingAbsent(): void
    {
        foreach ($this->students as $student) {
            if ($this->attendance[$student['id']] === 'not_marked') {
                $this->attendance[$student['id']] = 'absent';
            }
        }
        $this->calculateStats();
        $this->success('Unmarked students marked as absent.');
    }

    // Save attendance
    public function saveAttendance(): void
    {
        try {
            $markedCount = 0;

            foreach ($this->students as $student) {
                $studentId = $student['id'];
                $status = $this->attendance[$studentId];
                $note = $this->notes[$studentId] ?? '';

                // Skip if not marked
                if ($status === 'not_marked') {
                    continue;
                }

                $markedCount++;

                // Check if record exists
                $record = Attendance::where('session_id', $this->session->id)
                    ->where('child_profile_id', $studentId)
                    ->first();

                if ($record) {
                    // Update existing record
                    $record->update([
                        'status' => $status,
                        'notes' => $note,
                        'updated_at' => now(),
                    ]);
                } else {
                    // Create new record
                    Attendance::create([
                        'session_id' => $this->session->id,
                        'child_profile_id' => $studentId,
                        'status' => $status,
                        'notes' => $note,
                    ]);
                }
            }

            // Check if all students are marked
            if ($markedCount === 0) {
                $this->error('Please mark at least one student\'s attendance before saving.');
                return;
            }

            // Update session status if completed
            if ($this->isCompleted && $this->session->status !== 'completed') {
                $this->session->update([
                    'status' => 'completed'
                ]);
            }

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                'Teacher marked attendance for session: ' . $this->session->topic,
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            $this->success('Attendance saved successfully.');

            // Redirect to session details
            redirect()->route('teacher.sessions.show', $this->session->id);
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error saving attendance: ' . $e->getMessage(),
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            $this->error('An error occurred while saving attendance records.');
        }
    }

    // Format date in d/m/Y format
    public function formatDate($date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    // Format time for display
    public function formatTime($time): string
    {
        return Carbon::parse($time)->format('H:i');
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Take Attendance" separator progress-indicator>
        <x-slot:subtitle>
            {{ $session->topic }} | {{ formatDate($session->date) }} | {{ formatTime($session->start_time) }} - {{ formatTime($session->end_time) }}
        </x-slot:subtitle>

        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                <x-button
                    label="All Present"
                    icon="o-check"
                    color="success"
                    wire:click="markAllPresent"
                    wire:loading.attr="disabled"
                    size="sm"
                />

                <x-button
                    label="All Absent"
                    icon="o-x-mark"
                    color="error"
                    wire:click="markAllAbsent"
                    wire:loading.attr="disabled"
                    size="sm"
                />

                <x-button
                    label="Mark Unmarked Absent"
                    icon="o-exclamation-circle"
                    color="warning"
                    wire:click="markRemainingAbsent"
                    wire:loading.attr="disabled"
                    size="sm"
                />

                <x-button
                    label="Save Attendance"
                    icon="o-check-circle"
                    class="btn-primary"
                    wire:click="saveAttendance"
                    wire:loading.attr="disabled"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Session Status -->
    <div class="mb-6">
        @if ($isInProgress)
            <div class="p-4 shadow-lg alert bg-warning text-warning-content">
                <div>
                    <x-icon name="o-clock" class="w-6 h-6" />
                    <span>This session is currently in progress. You can update attendance in real-time.</span>
                </div>
            </div>
        @elseif ($isCompleted)
            <div class="p-4 shadow-lg alert bg-success text-success-content">
                <div>
                    <x-icon name="o-check-circle" class="w-6 h-6" />
                    <span>This session was completed on {{ formatDate($session->date) }}. You can still update attendance records.</span>
                </div>
            </div>
        @endif
    </div>

    <!-- Attendance Progress -->
    <x-card class="mb-6">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-lg font-semibold">Attendance Progress</span>
                    <span class="font-medium {{ $attendanceRate >= 75 ? 'text-success' : ($attendanceRate >= 50 ? 'text-warning' : 'text-error') }}">
                        {{ $attendanceRate }}%
                    </span>
                </div>
                <div class="flex gap-1">
                    <span class="font-medium">{{ $presentCount + $lateCount + $absentCount + $excusedCount }}/{{ $totalStudents }}</span>
                    <span class="text-gray-500">students marked</span>
                </div>
            </div>

            <div class="w-full h-4 rounded-full bg-base-300">
                @if ($totalStudents > 0)
                    <div class="h-full rounded-full bg-success" style="width: {{ ($presentCount / $totalStudents) * 100 }}%"></div>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 rounded-full bg-success"></div>
                    <span>{{ $presentCount }} Present</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 rounded-full bg-error"></div>
                    <span>{{ $absentCount }} Absent</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 rounded-full bg-warning"></div>
                    <span>{{ $lateCount }} Late</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 rounded-full bg-info"></div>
                    <span>{{ $excusedCount }} Excused</span>
                </div>
            </div>
        </div>
    </x-card>

    <!-- Search and Sort -->
    <div class="grid gap-4 mb-6 md:flex md:justify-between">
        <x-input placeholder="Search students..." id="student-search" onkeyup="filterStudents()" class="w-full md:max-w-xs" />

        <x-select
            placeholder="Filter by status"
            id="status-filter"
            onchange="filterStudents()"
            class="w-full md:max-w-xs"
            :options="[
                ['label' => 'All Students', 'value' => 'all'],
                ['label' => 'Marked Present', 'value' => 'present'],
                ['label' => 'Marked Absent', 'value' => 'absent'],
                ['label' => 'Marked Late', 'value' => 'late'],
                ['label' => 'Marked Excused', 'value' => 'excused'],
                ['label' => 'Not Marked', 'value' => 'not_marked'],
            ]"
            option-label="label"
            option-value="value"
        />
    </div>

    <!-- Students List -->
    <x-card title="Student Attendance">
        @if (count($students) > 0)
            <div class="overflow-x-auto">
                <table class="table table-zebra" id="students-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Program</th>
                            <th>Status</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($students as $student)
                            <tr class="hover student-row" data-name="{{ strtolower($student['name']) }}" data-status="{{ $attendance[$student['id']] }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar">
                                            <div class="w-10 h-10 mask mask-squircle">
                                                @if ($student['photo'])
                                                    <img src="{{ asset('storage/' . $student['photo']) }}" alt="{{ $student['name'] }}">
                                                @elseif ($student['profile_photo_url'])
                                                    <img src="{{ $student['profile_photo_url'] }}" alt="{{ $student['name'] }}">
                                                @else
                                                    <img src="https://ui-avatars.com/api/?name={{ urlencode($student['name']) }}&color=7F9CF5&background=EBF4FF" alt="{{ $student['name'] }}">
                                                @endif
                                            </div>
                                        </div>
                                        <div>
                                            <div class="font-bold">{{ $student['name'] }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $student['program'] }}</td>
                                <td>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <label class="gap-1 cursor-pointer label">
                                            <input
                                                type="radio"
                                                name="status_{{ $student['id'] }}"
                                                class="radio radio-success radio-sm"
                                                value="present"
                                                {{ $attendance[$student['id']] === 'present' ? 'checked' : '' }}
                                                wire:model.live="attendance.{{ $student['id'] }}"
                                                wire:change="updateStatus({{ $student['id'] }}, $event.target.value)"
                                            />
                                            <span class="text-success">Present</span>
                                        </label>
                                        <label class="gap-1 cursor-pointer label">
                                            <input
                                                type="radio"
                                                name="status_{{ $student['id'] }}"
                                                class="radio radio-error radio-sm"
                                                value="absent"
                                                {{ $attendance[$student['id']] === 'absent' ? 'checked' : '' }}
                                                wire:model.live="attendance.{{ $student['id'] }}"
                                                wire:change="updateStatus({{ $student['id'] }}, $event.target.value)"
                                            />
                                            <span class="text-error">Absent</span>
                                        </label>
                                        <label class="gap-1 cursor-pointer label">
                                            <input
                                                type="radio"
                                                name="status_{{ $student['id'] }}"
                                                class="radio radio-warning radio-sm"
                                                value="late"
                                                {{ $attendance[$student['id']] === 'late' ? 'checked' : '' }}
                                                wire:model.live="attendance.{{ $student['id'] }}"
                                                wire:change="updateStatus({{ $student['id'] }}, $event.target.value)"
                                            />
                                            <span class="text-warning">Late</span>
                                        </label>
                                        <label class="gap-1 cursor-pointer label">
                                            <input
                                                type="radio"
                                                name="status_{{ $student['id'] }}"
                                                class="radio radio-info radio-sm"
                                                value="excused"
                                                {{ $attendance[$student['id']] === 'excused' ? 'checked' : '' }}
                                                wire:model.live="attendance.{{ $student['id'] }}"
                                                wire:change="updateStatus({{ $student['id'] }}, $event.target.value)"
                                            />
                                            <span class="text-info">Excused</span>
                                        </label>
                                    </div>
                                </td>
                                <td>
                                    <input
                                        type="text"
                                        class="w-full input input-bordered input-sm"
                                        placeholder="Add note (optional)"
                                        value="{{ $notes[$student['id']] }}"
                                        wire:model.lazy="notes.{{ $student['id'] }}"
                                        wire:change="updateNote({{ $student['id'] }}, $event.target.value)"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end mt-6">
                <x-button
                    label="Save Attendance"
                    icon="o-check-circle"
                    class="btn-primary"
                    wire:click="saveAttendance"
                    wire:loading.attr="disabled"
                />
            </div>

            <!-- Search and Filter Script -->
            <script>
                function filterStudents() {
                    const searchText = document.getElementById('student-search').value.toLowerCase();
                    const statusFilter = document.getElementById('status-filter').value;
                    const rows = document.querySelectorAll('.student-row');

                    rows.forEach(row => {
                        const name = row.getAttribute('data-name');
                        const status = row.getAttribute('data-status');

                        const nameMatch = name.includes(searchText);
                        const statusMatch = (statusFilter === 'all' || status === statusFilter);

                        row.style.display = (nameMatch && statusMatch) ? '' : 'none';
                    });
                }
            </script>
        @else
            <div class="flex flex-col items-center justify-center py-8">
                <x-icon name="o-users" class="w-16 h-16 text-gray-400" />
                <h3 class="mt-2 text-lg font-semibold text-gray-600">No students found</h3>
                <p class="mt-1 text-sm text-gray-500">There are no students enrolled in this subject.</p>
            </div>
        @endif
    </x-card>
</div>
