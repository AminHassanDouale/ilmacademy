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

new #[Title('Mark Attendance')] class extends Component {
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
            'Teacher accessed attendance marking for session: ' . $session->topic,
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

    // Save attendance
    public function saveAttendance(): void
    {
        try {
            foreach ($this->students as $student) {
                $studentId = $student['id'];
                $status = $this->attendance[$studentId];
                $note = $this->notes[$studentId] ?? '';

                // Skip if not marked
                if ($status === 'not_marked') {
                    continue;
                }

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
    <x-header title="Mark Attendance" subtitle="{{ $session->topic }} | {{ $formatDate($session->date) }}" separator progress-indicator>
        <x-slot:actions>
            <div class="flex gap-2">
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
                    label="Save Attendance"
                    icon="o-check-circle"
                    class="btn-primary"
                    wire:click="saveAttendance"
                    wire:loading.attr="disabled"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Session Info -->
    <x-card class="mb-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
                <h3 class="text-sm font-medium text-gray-500">Subject</h3>
                <p class="mt-1 font-medium">{{ $session->subject->name ?? 'Unknown Subject' }}</p>
                <p class="text-sm text-gray-500">{{ $session->subject->code ?? '' }}</p>
            </div>

            <div>
                <h3 class="text-sm font-medium text-gray-500">Date & Time</h3>
                <p class="mt-1 font-medium">{{ $formatDate($session->date) }}</p>
                <p class="text-sm text-gray-500">{{ $formatTime($session->start_time) }} - {{ $formatTime($session->end_time) }}</p>
            </div>

            <div>
                <h3 class="text-sm font-medium text-gray-500">Status</h3>
                <div class="mt-1">
                    @if ($isInProgress)
                        <x-badge label="In Progress" color="warning" />
                    @elseif ($isCompleted)
                        <x-badge label="Completed" color="success" />
                    @endif
                </div>
            </div>
        </div>
    </x-card>

    <!-- Attendance Stats -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-5">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-users" class="w-8 h-8" />
            </div>
            <div class="stat-title">Total</div>
            <div class="stat-value">{{ $totalStudents }}</div>
            <div class="stat-desc">Enrolled Students</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Present</div>
            <div class="stat-value text-success">{{ $presentCount }}</div>
            <div class="stat-desc">{{ $totalStudents > 0 ? round(($presentCount / $totalStudents) * 100) : 0 }}%</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-error">
                <x-icon name="o-x-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Absent</div>
            <div class="stat-value text-error">{{ $absentCount }}</div>
            <div class="stat-desc">{{ $totalStudents > 0 ? round(($absentCount / $totalStudents) * 100) : 0 }}%</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-warning">
                <x-icon name="o-clock" class="w-8 h-8" />
            </div>
            <div class="stat-title">Late</div>
            <div class="stat-value text-warning">{{ $lateCount }}</div>
            <div class="stat-desc">{{ $totalStudents > 0 ? round(($lateCount / $totalStudents) * 100) : 0 }}%</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-information-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Excused</div>
            <div class="stat-value text-info">{{ $excusedCount }}</div>
            <div class="stat-desc">{{ $totalStudents > 0 ? round(($excusedCount / $totalStudents) * 100) : 0 }}%</div>
        </div>
    </div>

    <!-- Students List -->
    <x-card title="Student Attendance">
        @if (count($students) > 0)
            <div class="overflow-x-auto">
                <table class="table table-zebra">
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
                            <tr class="hover">
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
                                    <div class="flex items-center space-x-2">
                                        <label class="cursor-pointer label">
                                            <span class="mr-1 label-text text-success">Present</span>
                                            <input
                                                type="radio"
                                                name="status_{{ $student['id'] }}"
                                                class="radio radio-success radio-sm"
                                                value="present"
                                                wire:model.live="attendance.{{ $student['id'] }}"
                                                wire:change="updateStatus({{ $student['id'] }}, $event.target.value)"
                                            />
                                        </label>
                                        <label class="cursor-pointer label">
                                            <span class="mr-1 label-text text-error">Absent</span>
                                            <input
                                                type="radio"
                                                name="status_{{ $student['id'] }}"
                                                class="radio radio-error radio-sm"
                                                value="absent"
                                                wire:model.live="attendance.{{ $student['id'] }}"
                                                wire:change="updateStatus({{ $student['id'] }}, $event.target.value)"
                                            />
                                        </label>
                                        <label class="cursor-pointer label">
                                            <span class="mr-1 label-text text-warning">Late</span>
                                            <input
                                                type="radio"
                                                name="status_{{ $student['id'] }}"
                                                class="radio radio-warning radio-sm"
                                                value="late"
                                                wire:model.live="attendance.{{ $student['id'] }}"
                                                wire:change="updateStatus({{ $student['id'] }}, $event.target.value)"
                                            />
                                        </label>
                                        <label class="cursor-pointer label">
                                            <span class="mr-1 label-text text-info">Excused</span>
                                            <input
                                                type="radio"
                                                name="status_{{ $student['id'] }}"
                                                class="radio radio-info radio-sm"
                                                value="excused"
                                                wire:model.live="attendance.{{ $student['id'] }}"
                                                wire:change="updateStatus({{ $student['id'] }}, $event.target.value)"
                                            />
                                        </label>
                                    </div>
                                </td>
                                <td>
                                    <input
                                        type="text"
                                        class="w-full input input-bordered input-sm"
                                        placeholder="Add note (optional)"
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
        @else
            <div class="flex flex-col items-center justify-center py-8">
                <x-icon name="o-users" class="w-16 h-16 text-gray-400" />
                <h3 class="mt-2 text-lg font-semibold text-gray-600">No students found</h3>
                <p class="mt-1 text-sm text-gray-500">There are no students enrolled in this subject.</p>
            </div>
        @endif
    </x-card>
</div>
