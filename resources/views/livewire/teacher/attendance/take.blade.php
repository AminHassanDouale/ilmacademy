<?php

use App\Models\Session;
use App\Models\Attendance;
use App\Models\TeacherProfile;
use App\Models\SubjectEnrollment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Take Attendance')] class extends Component {
    use Toast;

    // Model instances
    public Session $session;
    public ?TeacherProfile $teacherProfile = null;

    // Student data and attendance
    public $enrolledStudents = [];
    public array $attendanceData = [];
    public string $notes = '';

    // UI state
    public bool $isSubmitted = false;
    public bool $bulkMode = false;
    public string $bulkStatus = 'present';

    // Attendance status options
    public array $statusOptions = [
        'present' => ['label' => 'Present', 'color' => 'bg-green-100 text-green-800', 'icon' => 'o-check-circle'],
        'absent' => ['label' => 'Absent', 'color' => 'bg-red-100 text-red-800', 'icon' => 'o-x-circle'],
        'late' => ['label' => 'Late', 'color' => 'bg-yellow-100 text-yellow-800', 'icon' => 'o-clock'],
        'excused' => ['label' => 'Excused', 'color' => 'bg-blue-100 text-blue-800', 'icon' => 'o-shield-check'],
    ];

    // Mount the component
    public function mount(Session $session): void
    {
        $this->session = $session->load(['subject', 'subject.curriculum', 'teacherProfile', 'attendances']);
        $this->teacherProfile = Auth::user()->teacherProfile;

        if (!$this->teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            $this->redirect(route('teacher.profile.edit'));
            return;
        }

        // Check if teacher owns this session
        if ($this->session->teacher_profile_id !== $this->teacherProfile->id) {
            $this->error('You are not authorized to take attendance for this session.');
            $this->redirect(route('teacher.sessions.index'));
            return;
        }

        Log::info('Attendance Take Component Mounted', [
            'teacher_user_id' => Auth::id(),
            'session_id' => $session->id,
            'subject_id' => $session->subject_id,
            'ip' => request()->ip()
        ]);

        $this->loadStudentsAndAttendance();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed attendance page for session: {$session->subject->name} on {$session->start_time->format('M d, Y \a\t g:i A')}",
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

    protected function loadStudentsAndAttendance(): void
    {
        try {
            // Get enrolled students for this subject
            $this->enrolledStudents = SubjectEnrollment::with(['childProfile', 'childProfile.user'])
                ->where('subject_id', $this->session->subject_id)
                ->where('status', 'active')
                ->get()
                ->sortBy('childProfile.full_name')
                ->values();

            // Initialize attendance data
            $this->attendanceData = [];

            foreach ($this->enrolledStudents as $enrollment) {
                $studentId = $enrollment->child_profile_id;

                // Check if attendance already exists
                $existingAttendance = $this->session->attendances
                    ->where('child_profile_id', $studentId)
                    ->first();

                $this->attendanceData[$studentId] = [
                    'status' => $existingAttendance ? $existingAttendance->status : 'present',
                    'notes' => $existingAttendance ? $existingAttendance->notes : '',
                    'check_in_time' => $existingAttendance ? $existingAttendance->check_in_time?->format('H:i') : now()->format('H:i'),
                    'existing_id' => $existingAttendance ? $existingAttendance->id : null,
                ];
            }

            // Check if attendance was already submitted
            $this->isSubmitted = $this->session->attendances->count() > 0;

            Log::info('Students and Attendance Data Loaded', [
                'session_id' => $this->session->id,
                'enrolled_students_count' => $this->enrolledStudents->count(),
                'existing_attendance_count' => $this->session->attendances->count(),
                'is_submitted' => $this->isSubmitted
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to load students and attendance data', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage()
            ]);

            $this->enrolledStudents = collect();
            $this->attendanceData = [];
        }
    }

    // Update individual student attendance
    public function updateAttendance(int $studentId, string $status): void
    {
        if (isset($this->attendanceData[$studentId])) {
            $this->attendanceData[$studentId]['status'] = $status;

            // Update check-in time if marking as present or late
            if (in_array($status, ['present', 'late'])) {
                $this->attendanceData[$studentId]['check_in_time'] = now()->format('H:i');
            }

            Log::debug('Attendance Updated', [
                'student_id' => $studentId,
                'status' => $status,
                'session_id' => $this->session->id
            ]);
        }
    }

    // Bulk update attendance for all students
    public function bulkUpdateAttendance(): void
    {
        foreach ($this->attendanceData as $studentId => $data) {
            $this->attendanceData[$studentId]['status'] = $this->bulkStatus;

            // Update check-in time if marking as present or late
            if (in_array($this->bulkStatus, ['present', 'late'])) {
                $this->attendanceData[$studentId]['check_in_time'] = now()->format('H:i');
            }
        }

        $this->bulkMode = false;

        $this->success("All students marked as {$this->statusOptions[$this->bulkStatus]['label']}");

        Log::info('Bulk Attendance Update', [
            'session_id' => $this->session->id,
            'bulk_status' => $this->bulkStatus,
            'student_count' => count($this->attendanceData)
        ]);
    }

    // Save attendance
    public function saveAttendance(): void
    {
        Log::info('Attendance Save Started', [
            'teacher_user_id' => Auth::id(),
            'session_id' => $this->session->id,
            'student_count' => count($this->attendanceData)
        ]);

        try {
            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            $createdCount = 0;
            $updatedCount = 0;
            $summary = [
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'excused' => 0
            ];

            foreach ($this->attendanceData as $studentId => $data) {
                // Validate check-in time format
                $checkInTime = null;
                if (!empty($data['check_in_time'])) {
                    try {
                        $checkInTime = \Carbon\Carbon::parse($this->session->start_time->format('Y-m-d') . ' ' . $data['check_in_time']);
                    } catch (\Exception $e) {
                        $checkInTime = $this->session->start_time;
                    }
                }

                $attendanceData = [
                    'session_id' => $this->session->id,
                    'child_profile_id' => $studentId,
                    'status' => $data['status'],
                    'notes' => $data['notes'] ?: null,
                    'check_in_time' => $checkInTime,
                ];

                if ($data['existing_id']) {
                    // Update existing attendance
                    Attendance::where('id', $data['existing_id'])->update($attendanceData);
                    $updatedCount++;
                } else {
                    // Create new attendance
                    Attendance::create($attendanceData);
                    $createdCount++;
                }

                // Count by status for summary
                $summary[$data['status']]++;
            }

            // Log activity
            $description = "Recorded attendance for {$this->session->subject->name} session on {$this->session->start_time->format('M d, Y')}";
            if ($updatedCount > 0) {
                $description .= " (Updated existing records)";
            }

            ActivityLog::log(
                Auth::id(),
                $this->isSubmitted ? 'update' : 'create',
                $description,
                Session::class,
                $this->session->id,
                [
                    'session_id' => $this->session->id,
                    'subject_name' => $this->session->subject->name,
                    'created_count' => $createdCount,
                    'updated_count' => $updatedCount,
                    'attendance_summary' => $summary,
                    'total_students' => count($this->attendanceData),
                    'session_start' => $this->session->start_time->toDateTimeString(),
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Update submitted status
            $this->isSubmitted = true;

            // Show success message
            if ($updatedCount > 0) {
                $this->success("Attendance updated successfully for {$this->enrolledStudents->count()} students.");
            } else {
                $this->success("Attendance recorded successfully for {$this->enrolledStudents->count()} students.");
            }

            Log::info('Attendance Save Completed', [
                'session_id' => $this->session->id,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'summary' => $summary
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Attendance Save Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'session_id' => $this->session->id,
                'student_count' => count($this->attendanceData)
            ]);

            $this->error("An error occurred while saving attendance: {$e->getMessage()}");
        }
    }

    // Get attendance summary
    public function getAttendanceSummaryProperty(): array
    {
        $summary = [
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'excused' => 0,
            'total' => count($this->attendanceData)
        ];

        foreach ($this->attendanceData as $data) {
            $summary[$data['status']]++;
        }

        $attendingCount = $summary['present'] + $summary['late'];
        $summary['attendance_rate'] = $summary['total'] > 0 ? round(($attendingCount / $summary['total']) * 100, 1) : 0;

        return $summary;
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

    public function with(): array
    {
        return [
            'attendanceSummary' => $this->attendanceSummary,
            'sessionStatus' => $this->sessionStatus,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Take Attendance: {{ $session->subject->name }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Session Status -->
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $sessionStatus['color'] }}">
                {{ $sessionStatus['text'] }}
            </span>

            @if($isSubmitted)
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium text-green-800 bg-green-100 rounded-full">
                    <x-icon name="o-check-circle" class="w-4 h-4 mr-1" />
                    Submitted
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="View Session"
                icon="o-eye"
                link="{{ route('teacher.sessions.show', $session->id) }}"
                class="btn-ghost"
            />
            <x-button
                label="Back to Sessions"
                icon="o-arrow-left"
                link="{{ route('teacher.sessions.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <!-- Left column (3/4) - Attendance Form -->
        <div class="space-y-6 lg:col-span-3">
            <!-- Session Information -->
            <x-card title="Session Information">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject</div>
                        <div class="font-semibold">{{ $session->subject->name }}</div>
                        <div class="text-sm text-gray-600">{{ $session->subject->code }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Date & Time</div>
                        <div class="font-semibold">{{ $session->start_time->format('M d, Y') }}</div>
                        <div class="text-sm text-gray-600">
                            {{ $session->start_time->format('g:i A') }}
                            @if($session->end_time)
                                - {{ $session->end_time->format('g:i A') }}
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Students Enrolled</div>
                        <div class="text-2xl font-bold text-blue-600">{{ $enrolledStudents->count() }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Bulk Actions -->
            <x-card title="Quick Actions">
                <div class="flex flex-wrap gap-4">
                    @if(!$bulkMode)
                        <x-button
                            label="Bulk Actions"
                            icon="o-squares-plus"
                            wire:click="$set('bulkMode', true)"
                            class="btn-outline"
                        />
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium">Mark all as:</span>
                            @foreach($statusOptions as $status => $config)
                                <x-button
                                    :label="$config['label']"
                                    wire:click="$set('bulkStatus', '{{ $status }}')"
                                    class="btn-xs {{ $bulkStatus === $status ? 'btn-primary' : 'btn-outline' }}"
                                />
                            @endforeach
                            <x-button
                                label="Apply"
                                icon="o-check"
                                wire:click="bulkUpdateAttendance"
                                class="btn-sm btn-success"
                            />
                            <x-button
                                label="Cancel"
                                icon="o-x-mark"
                                wire:click="$set('bulkMode', false)"
                                class="btn-sm btn-ghost"
                            />
                        </div>
                    @endif

                    <div class="ml-auto">
                        <x-button
                            label="{{ $isSubmitted ? 'Update Attendance' : 'Save Attendance' }}"
                            icon="o-check"
                            wire:click="saveAttendance"
                            class="btn-primary"
                            wire:confirm="Are you sure you want to {{ $isSubmitted ? 'update' : 'save' }} attendance for this session?"
                        />
                    </div>
                </div>
            </x-card>

            <!-- Students Attendance List -->
            <x-card title="Student Attendance">
                @if($enrolledStudents->count() > 0)
                    <div class="space-y-4">
                        @foreach($enrolledStudents as $enrollment)
                            @php
                                $student = $enrollment->childProfile;
                                $studentId = $student->id;
                                $attendance = $attendanceData[$studentId] ?? ['status' => 'present', 'notes' => '', 'check_in_time' => now()->format('H:i')];
                            @endphp
                            <div class="p-4 border rounded-lg {{ $attendance['status'] === 'present' ? 'border-green-200 bg-green-50' : ($attendance['status'] === 'absent' ? 'border-red-200 bg-red-50' : ($attendance['status'] === 'late' ? 'border-yellow-200 bg-yellow-50' : 'border-blue-200 bg-blue-50')) }}">
                                <div class="flex items-start justify-between">
                                    <!-- Student Info -->
                                    <div class="flex items-center space-x-3">
                                        <div class="avatar">
                                            <div class="w-12 h-12 rounded-full">
                                                <img src="{{ $student->user->profile_photo_url ?? 'https://ui-avatars.com/api/?name=' . urlencode($student->full_name) }}" alt="{{ $student->full_name }}" />
                                            </div>
                                        </div>
                                        <div>
                                            <div class="font-semibold">{{ $student->full_name }}</div>
                                            <div class="text-sm text-gray-500">ID: {{ $student->id }}</div>
                                            @if($student->date_of_birth)
                                                <div class="text-xs text-gray-400">{{ $student->date_of_birth->format('M d, Y') }}</div>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Attendance Controls -->
                                    <div class="flex flex-col space-y-3">
                                        <!-- Status Buttons -->
                                        <div class="flex space-x-2">
                                            @foreach($statusOptions as $status => $config)
                                                <button
                                                    wire:click="updateAttendance({{ $studentId }}, '{{ $status }}')"
                                                    class="flex items-center px-3 py-2 text-xs font-medium rounded-md transition-colors {{ $attendance['status'] === $status ? $config['color'] : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
                                                    title="{{ $config['label'] }}"
                                                >
                                                    <x-icon name="{{ $config['icon'] }}" class="w-4 h-4 mr-1" />
                                                    {{ $config['label'] }}
                                                </button>
                                            @endforeach
                                        </div>

                                        <!-- Check-in Time (for present/late students) -->
                                        @if(in_array($attendance['status'], ['present', 'late']))
                                            <div class="flex items-center space-x-2">
                                                <label class="text-xs text-gray-500">Check-in:</label>
                                                <input
                                                    type="time"
                                                    wire:model.live="attendanceData.{{ $studentId }}.check_in_time"
                                                    class="px-2 py-1 text-xs border border-gray-300 rounded"
                                                />
                                            </div>
                                        @endif

                                        <!-- Notes -->
                                        <div>
                                            <input
                                                type="text"
                                                wire:model.live="attendanceData.{{ $studentId }}.notes"
                                                placeholder="Add notes (optional)"
                                                class="w-full px-2 py-1 text-xs border border-gray-300 rounded"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Save Button -->
                    <div class="pt-6 text-center border-t">
                        <x-button
                            label="{{ $isSubmitted ? 'Update Attendance' : 'Save Attendance' }}"
                            icon="o-check"
                            wire:click="saveAttendance"
                            class="btn-primary btn-lg"
                            wire:confirm="Are you sure you want to {{ $isSubmitted ? 'update' : 'save' }} attendance for this session?"
                        />
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-users" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No students enrolled in this subject</div>
                        <p class="mt-1 text-xs text-gray-400">Contact the administrator to enroll students</p>
                    </div>
                @endif
            </x-card>
        </div>

        <!-- Right column (1/4) - Summary and Info -->
        <div class="space-y-6">
            <!-- Attendance Summary -->
            <x-card title="Attendance Summary">
                <div class="space-y-4">
                    <!-- Summary Stats -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="p-3 text-center rounded-lg bg-green-50">
                            <div class="text-lg font-bold text-green-600">{{ $attendanceSummary['present'] }}</div>
                            <div class="text-xs text-green-600">Present</div>
                        </div>

                        <div class="p-3 text-center rounded-lg bg-yellow-50">
                            <div class="text-lg font-bold text-yellow-600">{{ $attendanceSummary['late'] }}</div>
                            <div class="text-xs text-yellow-600">Late</div>
                        </div>

                        <div class="p-3 text-center rounded-lg bg-red-50">
                            <div class="text-lg font-bold text-red-600">{{ $attendanceSummary['absent'] }}</div>
                            <div class="text-xs text-red-600">Absent</div>
                        </div>

                        <div class="p-3 text-center rounded-lg bg-blue-50">
                            <div class="text-lg font-bold text-blue-600">{{ $attendanceSummary['excused'] }}</div>
                            <div class="text-xs text-blue-600">Excused</div>
                        </div>
                    </div>

                    <!-- Attendance Rate -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-gray-700">Attendance Rate</span>
                            <span class="text-sm font-bold">{{ $attendanceSummary['attendance_rate'] }}%</span>
                        </div>
                        <div class="w-full h-2 bg-gray-200 rounded-full">
                            <div
                                class="h-2 rounded-full {{ $attendanceSummary['attendance_rate'] >= 80 ? 'bg-green-600' : ($attendanceSummary['attendance_rate'] >= 60 ? 'bg-yellow-600' : 'bg-red-600') }}"
                                style="width: {{ $attendanceSummary['attendance_rate'] }}%"
                            ></div>
                        </div>
                    </div>

                    <!-- Total Count -->
                    <div class="pt-3 border-t">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-gray-900">{{ $attendanceSummary['total'] }}</div>
                            <div class="text-sm text-gray-500">Total Students</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Status Legend -->
            <x-card title="Status Guide">
                <div class="space-y-3 text-sm">
                    @foreach($statusOptions as $status => $config)
                        <div class="flex items-center">
                            <x-icon name="{{ $config['icon'] }}" class="w-4 h-4 mr-2" />
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $config['color'] }} mr-2">
                                {{ $config['label'] }}
                            </span>
                            <span class="text-gray-600">
                                {{ match($status) {
                                    'present' => 'Student attended on time',
                                    'late' => 'Student arrived after start time',
                                    'absent' => 'Student did not attend',
                                    'excused' => 'Approved absence'
                                } }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-2">
                    <x-button
                        label="View Session Details"
                        icon="o-eye"
                        link="{{ route('teacher.sessions.show', $session->id) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Edit Session"
                        icon="o-pencil"
                        link="{{ route('teacher.sessions.edit', $session->id) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="All Sessions"
                        icon="o-presentation-chart-line"
                        link="{{ route('teacher.sessions.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Attendance Overview"
                        icon="o-clipboard-document-check"
                        link="{{ route('teacher.attendance.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>

            <!-- Tips -->
            <x-card title="Tips">
                <div class="space-y-3 text-xs text-gray-600">
                    <div>
                        <div class="font-semibold">Quick Marking</div>
                        <p>Use bulk actions to quickly mark all students with the same status, then adjust individual cases.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Check-in Times</div>
                        <p>Adjust check-in times for late students to record when they actually arrived.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Notes</div>
                        <p>Add notes for absences, late arrivals, or any special circumstances.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Updates</div>
                        <p>You can update attendance records after saving if needed. All changes are logged.</p>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
