<?php

use App\Models\Session;
use App\Models\Attendance;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\ChildProfile;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\ProgramEnrollment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Attendance Report')] class extends Component {
    use Toast;

    // Filter properties
    public string $selectedAcademicYear = '';
    public string $selectedCurriculum = '';
    public string $selectedSubject = '';
    public string $selectedTeacher = '';
    public string $selectedStudent = '';
    public string $attendanceStatus = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $reportFormat = 'table';
    public string $reportType = 'overview'; // overview, teacher, student

    public array $attendanceStatuses = [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'excused' => 'Excused'
    ];

    public array $reportTypes = [
        'overview' => 'Overview Report',
        'teacher' => 'Teacher Report',
        'student' => 'Student Report'
    ];

    public function mount(): void
    {
        $this->selectedAcademicYear = AcademicYear::where('is_current', true)->first()?->id ?? '';
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->endOfMonth()->format('Y-m-d');
    }

    public function getAcademicYearsProperty(): Collection
    {
        return AcademicYear::orderBy('start_date', 'desc')->get();
    }

    public function getCurriculaProperty(): Collection
    {
        return Curriculum::orderBy('name')->get();
    }

    public function getSubjectsProperty(): Collection
    {
        $query = Subject::orderBy('name');

        if ($this->selectedCurriculum) {
            $query->where('curriculum_id', $this->selectedCurriculum);
        }

        return $query->get();
    }

    public function getTeachersProperty(): Collection
    {
        return TeacherProfile::with('user')->orderBy('id')->get();
    }

    public function getStudentsProperty(): Collection
    {
        $query = ChildProfile::with(['programEnrollments.academicYear', 'programEnrollments.curriculum'])
            ->orderBy('first_name');

        // Filter students by academic year
        if ($this->selectedAcademicYear) {
            $query->whereHas('programEnrollments', function($q) {
                $q->where('academic_year_id', $this->selectedAcademicYear);
            });
        }

        // Filter students by curriculum
        if ($this->selectedCurriculum) {
            $query->whereHas('programEnrollments', function($q) {
                $q->where('curriculum_id', $this->selectedCurriculum);
            });
        }

        return $query->get();
    }

    public function getAttendanceDataProperty(): Collection
    {
        $query = Attendance::with([
            'session.subject.curriculum',
            'session.teacherProfile.user',
            'childProfile.programEnrollments.academicYear',
            'childProfile.programEnrollments.curriculum'
        ]);

        // Filter by academic year
        if ($this->selectedAcademicYear) {
            $query->whereHas('childProfile.programEnrollments', function($q) {
                $q->where('academic_year_id', $this->selectedAcademicYear);
            });
        }

        // Filter by curriculum
        if ($this->selectedCurriculum) {
            $query->where(function($q) {
                $q->whereHas('session.subject', function($subQuery) {
                    $subQuery->where('curriculum_id', $this->selectedCurriculum);
                })
                ->orWhereHas('childProfile.programEnrollments', function($subQuery) {
                    $subQuery->where('curriculum_id', $this->selectedCurriculum);
                });
            });
        }

        // Filter by subject
        if ($this->selectedSubject) {
            $query->whereHas('session', function($q) {
                $q->where('subject_id', $this->selectedSubject);
            });
        }

        // Filter by teacher
        if ($this->selectedTeacher) {
            $query->whereHas('session', function($q) {
                $q->where('teacher_profile_id', $this->selectedTeacher);
            });
        }

        // Filter by student
        if ($this->selectedStudent) {
            $query->where('child_profile_id', $this->selectedStudent);
        }

        // Filter by attendance status
        if ($this->attendanceStatus) {
            $query->where('status', $this->attendanceStatus);
        }

        // Filter by date range
        if ($this->dateFrom && $this->dateTo) {
            $query->whereHas('session', function($q) {
                $q->whereBetween('start_time', [$this->dateFrom . ' 00:00:00', $this->dateTo . ' 23:59:59']);
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getStatisticsProperty(): array
    {
        $attendances = $this->attendanceData;

        $stats = [
            'total_attendances' => $attendances->count(),
            'present_count' => $attendances->where('status', 'present')->count(),
            'absent_count' => $attendances->where('status', 'absent')->count(),
            'late_count' => $attendances->where('status', 'late')->count(),
            'excused_count' => $attendances->where('status', 'excused')->count(),
            'attendance_rate' => 0,
            'subject_breakdown' => [],
            'teacher_breakdown' => [],
            'student_breakdown' => [],
            'daily_breakdown' => [],
        ];

        // Calculate attendance rate
        $totalPresent = $stats['present_count'] + $stats['late_count'];
        $stats['attendance_rate'] = $stats['total_attendances'] > 0
            ? round(($totalPresent / $stats['total_attendances']) * 100, 2)
            : 0;

        // Subject breakdown
        foreach ($attendances as $attendance) {
            $subjectName = $attendance->session->subject->name ?? 'Unknown';
            if (!isset($stats['subject_breakdown'][$subjectName])) {
                $stats['subject_breakdown'][$subjectName] = [
                    'total' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0
                ];
            }

            $stats['subject_breakdown'][$subjectName]['total']++;
            $stats['subject_breakdown'][$subjectName][$attendance->status]++;
        }

        // Teacher breakdown
        foreach ($attendances as $attendance) {
            $teacherName = $attendance->session->teacherProfile->user->name ?? 'Unknown';
            if (!isset($stats['teacher_breakdown'][$teacherName])) {
                $stats['teacher_breakdown'][$teacherName] = [
                    'total' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0,
                    'teacher_id' => $attendance->session->teacherProfile->id ?? null
                ];
            }

            $stats['teacher_breakdown'][$teacherName]['total']++;
            $stats['teacher_breakdown'][$teacherName][$attendance->status]++;
        }

        // Student breakdown
        foreach ($attendances as $attendance) {
            $studentName = $attendance->childProfile->full_name ?? 'Unknown';
            if (!isset($stats['student_breakdown'][$studentName])) {
                $stats['student_breakdown'][$studentName] = [
                    'total' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0,
                    'student_id' => $attendance->childProfile->id ?? null
                ];
            }

            $stats['student_breakdown'][$studentName]['total']++;
            $stats['student_breakdown'][$studentName][$attendance->status]++;
        }

        // Daily breakdown
        foreach ($attendances as $attendance) {
            $date = $attendance->session->start_time->format('Y-m-d');
            if (!isset($stats['daily_breakdown'][$date])) {
                $stats['daily_breakdown'][$date] = [
                    'total' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0
                ];
            }

            $stats['daily_breakdown'][$date]['total']++;
            $stats['daily_breakdown'][$date][$attendance->status]++;
        }

        return $stats;
    }

    public function getTeacherReportProperty(): array
    {
        if (!$this->selectedTeacher) {
            return [];
        }

        $attendances = $this->attendanceData->where('session.teacher_profile_id', $this->selectedTeacher);

        $report = [
            'teacher_info' => TeacherProfile::with('user')->find($this->selectedTeacher),
            'total_sessions' => $attendances->pluck('session.id')->unique()->count(),
            'total_attendance_records' => $attendances->count(),
            'subjects_taught' => [],
            'student_performance' => [],
            'daily_summary' => []
        ];

        // Subjects taught breakdown
        foreach ($attendances as $attendance) {
            $subjectName = $attendance->session->subject->name ?? 'Unknown';
            if (!isset($report['subjects_taught'][$subjectName])) {
                $report['subjects_taught'][$subjectName] = [
                    'total' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0
                ];
            }

            $report['subjects_taught'][$subjectName]['total']++;
            $report['subjects_taught'][$subjectName][$attendance->status]++;
        }

        // Student performance in teacher's classes
        $studentAttendances = $attendances->groupBy('child_profile_id');
        foreach ($studentAttendances as $studentId => $studentRecords) {
            $studentName = $studentRecords->first()->childProfile->full_name ?? 'Unknown';
            $totalSessions = $studentRecords->count();
            $presentSessions = $studentRecords->whereIn('status', ['present', 'late'])->count();
            $rate = $totalSessions > 0 ? round(($presentSessions / $totalSessions) * 100, 2) : 0;

            $report['student_performance'][] = [
                'student_name' => $studentName,
                'total_sessions' => $totalSessions,
                'present_sessions' => $presentSessions,
                'attendance_rate' => $rate
            ];
        }

        // Sort students by attendance rate
        usort($report['student_performance'], function($a, $b) {
            return $b['attendance_rate'] <=> $a['attendance_rate'];
        });

        return $report;
    }

    public function getStudentReportProperty(): array
    {
        if (!$this->selectedStudent) {
            return [];
        }

        $attendances = $this->attendanceData->where('child_profile_id', $this->selectedStudent);

        $report = [
            'student_info' => ChildProfile::with(['programEnrollments.academicYear', 'programEnrollments.curriculum'])->find($this->selectedStudent),
            'total_sessions_attended' => $attendances->count(),
            'subjects_performance' => [],
            'teachers_performance' => [],
            'monthly_summary' => []
        ];

        // Subject performance
        foreach ($attendances as $attendance) {
            $subjectName = $attendance->session->subject->name ?? 'Unknown';
            if (!isset($report['subjects_performance'][$subjectName])) {
                $report['subjects_performance'][$subjectName] = [
                    'total' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0
                ];
            }

            $report['subjects_performance'][$subjectName]['total']++;
            $report['subjects_performance'][$subjectName][$attendance->status]++;
        }

        // Teacher performance
        foreach ($attendances as $attendance) {
            $teacherName = $attendance->session->teacherProfile->user->name ?? 'Unknown';
            if (!isset($report['teachers_performance'][$teacherName])) {
                $report['teachers_performance'][$teacherName] = [
                    'total' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'excused' => 0
                ];
            }

            $report['teachers_performance'][$teacherName]['total']++;
            $report['teachers_performance'][$teacherName][$attendance->status]++;
        }

        return $report;
    }

    public function exportReport(): void
    {
        $this->success('Attendance report export functionality will be implemented soon.');
    }

    public function resetFilters(): void
    {
        $this->selectedAcademicYear = AcademicYear::where('is_current', true)->first()?->id ?? '';
        $this->selectedCurriculum = '';
        $this->selectedSubject = '';
        $this->selectedTeacher = '';
        $this->selectedStudent = '';
        $this->attendanceStatus = '';
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->endOfMonth()->format('Y-m-d');
        $this->reportType = 'overview';
        $this->success('Filters reset successfully.');
    }

    // Update handlers
    public function updatedReportType(): void
    {
        // Reset teacher and student selections when report type changes
        if ($this->reportType !== 'teacher') {
            $this->selectedTeacher = '';
        }
        if ($this->reportType !== 'student') {
            $this->selectedStudent = '';
        }
    }

    public function updatedSelectedCurriculum(): void
    {
        // Reset subject and student when curriculum changes
        $this->selectedSubject = '';
        $this->selectedStudent = '';
    }

    public function with(): array
    {
        return [
            'attendanceData' => $this->attendanceData,
            'statistics' => $this->statistics,
            'teacherReport' => $this->teacherReport,
            'studentReport' => $this->studentReport,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Attendance Report" separator>
        <x-slot:subtitle>
            {{ $reportTypes[$reportType] }}
        </x-slot:subtitle>

        <x-slot:middle>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                <x-badge
                    label="{{ $attendanceData->count() }} Records"
                    color="primary"
                    class="text-xs badge-lg sm:text-sm"
                />
                <x-badge
                    label="{{ $statistics['attendance_rate'] }}% Attendance Rate"
                    color="{{ $statistics['attendance_rate'] >= 80 ? 'success' : ($statistics['attendance_rate'] >= 60 ? 'warning' : 'error') }}"
                    class="text-xs badge-lg sm:text-sm"
                />
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <div class="flex flex-col gap-2 sm:flex-row">
                <x-button
                    label="Export"
                    icon="o-arrow-down-tray"
                    wire:click="exportReport"
                    color="success"
                    responsive
                />
                <x-button
                    label="Reset Filters"
                    icon="o-arrow-path"
                    wire:click="resetFilters"
                    class="btn-ghost"
                    responsive
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Report Type Selection -->
    <x-card title="Report Configuration" class="mb-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Report Type</label>
                <select wire:model.live="reportType" class="w-full select select-bordered">
                    @foreach($reportTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if($reportType === 'teacher')
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-700">Select Teacher <span class="text-red-500">*</span></label>
                    <select wire:model.live="selectedTeacher" class="w-full select select-bordered">
                        <option value="">Choose a Teacher</option>
                        @foreach($this->teachers as $teacher)
                            <option value="{{ $teacher->id }}">{{ $teacher->user->name ?? 'Unknown' }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if($reportType === 'student')
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-700">Select Student <span class="text-red-500">*</span></label>
                    <select wire:model.live="selectedStudent" class="w-full select select-bordered">
                        <option value="">Choose a Student</option>
                        @foreach($this->students as $student)
                            <option value="{{ $student->id }}">{{ $student->full_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </x-card>

    <!-- Filters -->
    <x-card title="Report Filters" class="mb-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Academic Year</label>
                <select wire:model.live="selectedAcademicYear" class="w-full select select-bordered">
                    <option value="">All Academic Years</option>
                    @foreach($this->academicYears as $year)
                        <option value="{{ $year->id }}">{{ $year->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Curriculum</label>
                <select wire:model.live="selectedCurriculum" class="w-full select select-bordered">
                    <option value="">All Curricula</option>
                    @foreach($this->curricula as $curriculum)
                        <option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Subject</label>
                <select wire:model.live="selectedSubject" class="w-full select select-bordered">
                    <option value="">All Subjects</option>
                    @foreach($this->subjects as $subject)
                        <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                    @endforeach
                </select>
            </div>

            @if($reportType === 'overview')
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-700">Teacher</label>
                    <select wire:model.live="selectedTeacher" class="w-full select select-bordered">
                        <option value="">All Teachers</option>
                        @foreach($this->teachers as $teacher)
                            <option value="{{ $teacher->id }}">{{ $teacher->user->name ?? 'Unknown' }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-700">Student</label>
                    <select wire:model.live="selectedStudent" class="w-full select select-bordered">
                        <option value="">All Students</option>
                        @foreach($this->students as $student)
                            <option value="{{ $student->id }}">{{ $student->full_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Status</label>
                <select wire:model.live="attendanceStatus" class="w-full select select-bordered">
                    <option value="">All Statuses</option>
                    @foreach($attendanceStatuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 mt-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-input
                label="Date From"
                wire:model.live="dateFrom"
                type="date"
            />
            <x-input
                label="Date To"
                wire:model.live="dateTo"
                type="date"
            />
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Report Format</label>
                <select wire:model.live="reportFormat" class="w-full select select-bordered">
                    <option value="table">Table View</option>
                    <option value="summary">Summary View</option>
                    <option value="charts">Charts View</option>
                </select>
            </div>
        </div>
    </x-card>

    <!-- Statistics -->
    <div class="grid grid-cols-2 gap-4 mb-6 sm:grid-cols-3 lg:grid-cols-5">
        <x-card>
            <div class="flex items-center p-4">
                <div class="flex items-center justify-center w-10 h-10 bg-blue-500 rounded-lg sm:w-12 sm:h-12">
                    <x-icon name="o-clipboard-document-list" class="w-5 h-5 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="flex-1 min-w-0 ml-3 sm:ml-4">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Total Records</p>
                    <p class="text-lg font-bold text-gray-900 truncate sm:text-2xl">{{ $statistics['total_attendances'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center p-4">
                <div class="flex items-center justify-center w-10 h-10 bg-green-500 rounded-lg sm:w-12 sm:h-12">
                    <x-icon name="o-check-circle" class="w-5 h-5 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="flex-1 min-w-0 ml-3 sm:ml-4">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Present</p>
                    <p class="text-lg font-bold text-gray-900 truncate sm:text-2xl">{{ $statistics['present_count'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center p-4">
                <div class="flex items-center justify-center w-10 h-10 bg-red-500 rounded-lg sm:w-12 sm:h-12">
                    <x-icon name="o-x-circle" class="w-5 h-5 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="flex-1 min-w-0 ml-3 sm:ml-4">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Absent</p>
                    <p class="text-lg font-bold text-gray-900 truncate sm:text-2xl">{{ $statistics['absent_count'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center p-4">
                <div class="flex items-center justify-center w-10 h-10 bg-yellow-500 rounded-lg sm:w-12 sm:h-12">
                    <x-icon name="o-clock" class="w-5 h-5 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="flex-1 min-w-0 ml-3 sm:ml-4">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Late</p>
                    <p class="text-lg font-bold text-gray-900 truncate sm:text-2xl">{{ $statistics['late_count'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center p-4">
                <div class="flex items-center justify-center w-10 h-10 bg-purple-500 rounded-lg sm:w-12 sm:h-12">
                    <x-icon name="o-document-check" class="w-5 h-5 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="flex-1 min-w-0 ml-3 sm:ml-4">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Excused</p>
                    <p class="text-lg font-bold text-gray-900 truncate sm:text-2xl">{{ $statistics['excused_count'] }}</p>
                </div>
            </div>
        </x-card>
    </div>

    @if($reportType === 'teacher' && $selectedTeacher && !empty($teacherReport))
        <!-- Teacher Report -->
        <x-card title="Teacher Report: {{ $teacherReport['teacher_info']->user->name ?? 'Unknown' }}" class="mb-6">
            <div class="grid grid-cols-1 gap-4 mb-6 sm:grid-cols-2 lg:grid-cols-3">
                <div class="p-4 border border-blue-200 rounded-lg bg-blue-50">
                    <h3 class="font-semibold text-blue-600">Total Sessions Conducted</h3>
                    <p class="text-2xl font-bold">{{ $teacherReport['total_sessions'] }}</p>
                </div>
                <div class="p-4 border border-green-200 rounded-lg bg-green-50">
                    <h3 class="font-semibold text-green-600">Total Attendance Records</h3>
                    <p class="text-2xl font-bold">{{ $teacherReport['total_attendance_records'] }}</p>
                </div>
                <div class="p-4 border border-purple-200 rounded-lg bg-purple-50">
                    <h3 class="font-semibold text-purple-600">Subjects Taught</h3>
                    <p class="text-2xl font-bold">{{ count($teacherReport['subjects_taught']) }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Subjects Taught -->
                <div>
                    <h3 class="mb-3 text-lg font-semibold">Subjects Performance</h3>
                    <div class="space-y-3 overflow-y-auto max-h-96">
                        @foreach($teacherReport['subjects_taught'] as $subject => $data)
                            <div class="p-3 border rounded-lg bg-gray-50">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-semibold truncate">{{ $subject }}</span>
                                    <span class="ml-2 text-sm text-gray-600">{{ $data['total'] }} records</span>
                                </div>
                                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                                    <div class="text-center">
                                        <div class="font-semibold text-green-600">{{ $data['present'] }}</div>
                                        <div class="text-xs text-gray-500">Present</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-red-600">{{ $data['absent'] }}</div>
                                        <div class="text-xs text-gray-500">Absent</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-yellow-600">{{ $data['late'] }}</div>
                                        <div class="text-xs text-gray-500">Late</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-purple-600">{{ $data['excused'] }}</div>
                                        <div class="text-xs text-gray-500">Excused</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Student Performance in Teacher's Classes -->
                <div>
                    <h3 class="mb-3 text-lg font-semibold">Student Performance</h3>
                    <div class="space-y-2 overflow-y-auto max-h-96">
                        @foreach(array_slice($teacherReport['student_performance'], 0, 20) as $student)
                            <div class="flex items-center justify-between p-3 border rounded-lg bg-gray-50">
                                <div class="flex-1 min-w-0">
                                    <div class="font-semibold truncate">{{ $student['student_name'] }}</div>
                                    <div class="text-sm text-gray-600">
                                        {{ $student['present_sessions'] }}/{{ $student['total_sessions'] }} sessions
                                    </div>
                                </div>
                                <div class="ml-3 text-right">
                                    <div class="text-lg font-bold {{ $student['attendance_rate'] >= 80 ? 'text-green-600' : ($student['attendance_rate'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $student['attendance_rate'] }}%
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-card>
    @endif

    @if($reportType === 'student' && $selectedStudent && !empty($studentReport))
        <!-- Student Report -->
        <x-card title="Student Report: {{ $studentReport['student_info']->full_name ?? 'Unknown' }}" class="mb-6">
            <div class="grid grid-cols-1 gap-4 mb-6 sm:grid-cols-2 lg:grid-cols-3">
                <div class="p-4 border border-blue-200 rounded-lg bg-blue-50">
                    <h3 class="font-semibold text-blue-600">Total Sessions Attended</h3>
                    <p class="text-2xl font-bold">{{ $studentReport['total_sessions_attended'] }}</p>
                </div>
                <div class="p-4 border border-green-200 rounded-lg bg-green-50">
                    <h3 class="font-semibold text-green-600">Subjects Enrolled</h3>
                    <p class="text-2xl font-bold">{{ count($studentReport['subjects_performance']) }}</p>
                </div>
                <div class="p-4 border border-purple-200 rounded-lg bg-purple-50">
                    <h3 class="font-semibold text-purple-600">Teachers</h3>
                    <p class="text-2xl font-bold">{{ count($studentReport['teachers_performance']) }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Subject Performance -->
                <div>
                    <h3 class="mb-3 text-lg font-semibold">Performance by Subject</h3>
                    <div class="space-y-3 overflow-y-auto max-h-96">
                        @foreach($studentReport['subjects_performance'] as $subject => $data)
                            @php
                                $attendanceRate = $data['total'] > 0 ? round((($data['present'] + $data['late']) / $data['total']) * 100, 1) : 0;
                            @endphp
                            <div class="p-3 border rounded-lg bg-gray-50">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-semibold truncate">{{ $subject }}</span>
                                    <span class="font-bold ml-2 {{ $attendanceRate >= 80 ? 'text-green-600' : ($attendanceRate >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $attendanceRate }}%
                                    </span>
                                </div>
                                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                                    <div class="text-center">
                                        <div class="font-semibold text-green-600">{{ $data['present'] }}</div>
                                        <div class="text-xs text-gray-500">Present</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-red-600">{{ $data['absent'] }}</div>
                                        <div class="text-xs text-gray-500">Absent</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-yellow-600">{{ $data['late'] }}</div>
                                        <div class="text-xs text-gray-500">Late</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-purple-600">{{ $data['excused'] }}</div>
                                        <div class="text-xs text-gray-500">Excused</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Teacher Performance -->
                <div>
                    <h3 class="mb-3 text-lg font-semibold">Performance by Teacher</h3>
                    <div class="space-y-3 overflow-y-auto max-h-96">
                        @foreach($studentReport['teachers_performance'] as $teacher => $data)
                            @php
                                $attendanceRate = $data['total'] > 0 ? round((($data['present'] + $data['late']) / $data['total']) * 100, 1) : 0;
                            @endphp
                            <div class="p-3 border rounded-lg bg-gray-50">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-semibold truncate">{{ $teacher }}</span>
                                    <span class="font-bold ml-2 {{ $attendanceRate >= 80 ? 'text-green-600' : ($attendanceRate >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $attendanceRate }}%
                                    </span>
                                </div>
                                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                                    <div class="text-center">
                                        <div class="font-semibold text-green-600">{{ $data['present'] }}</div>
                                        <div class="text-xs text-gray-500">Present</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-red-600">{{ $data['absent'] }}</div>
                                        <div class="text-xs text-gray-500">Absent</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-yellow-600">{{ $data['late'] }}</div>
                                        <div class="text-xs text-gray-500">Late</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-purple-600">{{ $data['excused'] }}</div>
                                        <div class="text-xs text-gray-500">Excused</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-card>
    @endif

    @if($reportFormat === 'summary' && $reportType === 'overview')
        <!-- Summary View -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Subject Breakdown -->
            <x-card title="Attendance by Subject">
                <div class="space-y-3 overflow-y-auto max-h-96">
                    @foreach($statistics['subject_breakdown'] as $subject => $data)
                        @php
                            $attendanceRate = $data['total'] > 0 ? round((($data['present'] + $data['late']) / $data['total']) * 100, 1) : 0;
                        @endphp
                        <div class="p-3 border rounded-lg bg-gray-50">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-semibold truncate">{{ $subject }}</span>
                                <div class="flex items-center ml-2 space-x-2">
                                    <span class="text-sm text-gray-600">{{ $data['total'] }} sessions</span>
                                    <span class="font-bold {{ $attendanceRate >= 80 ? 'text-green-600' : ($attendanceRate >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $attendanceRate }}%
                                    </span>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                                <div class="text-center">
                                    <div class="font-semibold text-green-600">{{ $data['present'] }}</div>
                                    <div class="text-xs text-gray-500">Present</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold text-red-600">{{ $data['absent'] }}</div>
                                    <div class="text-xs text-gray-500">Absent</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold text-yellow-600">{{ $data['late'] }}</div>
                                    <div class="text-xs text-gray-500">Late</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold text-purple-600">{{ $data['excused'] }}</div>
                                    <div class="text-xs text-gray-500">Excused</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <!-- Teacher Breakdown -->
            <x-card title="Attendance by Teacher">
                <div class="space-y-3 overflow-y-auto max-h-96">
                    @foreach($statistics['teacher_breakdown'] as $teacher => $data)
                        @php
                            $attendanceRate = $data['total'] > 0 ? round((($data['present'] + $data['late']) / $data['total']) * 100, 1) : 0;
                        @endphp
                        <div class="p-3 border rounded-lg bg-gray-50">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-semibold truncate">{{ $teacher }}</span>
                                <div class="flex items-center ml-2 space-x-2">
                                    <span class="text-sm text-gray-600">{{ $data['total'] }} records</span>
                                    <span class="font-bold {{ $attendanceRate >= 80 ? 'text-green-600' : ($attendanceRate >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $attendanceRate }}%
                                    </span>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                                <div class="text-center">
                                    <div class="font-semibold text-green-600">{{ $data['present'] }}</div>
                                    <div class="text-xs text-gray-500">Present</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold text-red-600">{{ $data['absent'] }}</div>
                                    <div class="text-xs text-gray-500">Absent</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold text-yellow-600">{{ $data['late'] }}</div>
                                    <div class="text-xs text-gray-500">Late</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold text-purple-600">{{ $data['excused'] }}</div>
                                    <div class="text-xs text-gray-500">Excused</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>
    @elseif($reportFormat === 'charts')
        <!-- Charts View -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Attendance Status Distribution -->
            <x-card title="Attendance Status Distribution">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Present</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-24 h-3 bg-gray-200 rounded-full sm:w-32">
                                @php
                                    $percentage = $statistics['total_attendances'] > 0 ? ($statistics['present_count'] / $statistics['total_attendances']) * 100 : 0;
                                @endphp
                                <div class="h-3 bg-yellow-500 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>
                            <span class="text-sm font-medium">{{ round($percentage, 1) }}%</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Excused</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-24 h-3 bg-gray-200 rounded-full sm:w-32">
                                @php
                                    $percentage = $statistics['total_attendances'] > 0 ? ($statistics['excused_count'] / $statistics['total_attendances']) * 100 : 0;
                                @endphp
                                <div class="h-3 bg-purple-500 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>
                            <span class="text-sm font-medium">{{ round($percentage, 1) }}%</span>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Daily Attendance Trend -->
            <x-card title="Daily Attendance Trend">
                <div class="space-y-2 overflow-y-auto max-h-80">
                    @foreach(array_slice($statistics['daily_breakdown'], -7, 7, true) as $date => $data)
                        <div class="flex items-center justify-between p-2 border rounded">
                            <span class="text-sm font-medium truncate">{{ \Carbon\Carbon::parse($date)->format('M d, Y') }}</span>
                            <div class="flex items-center ml-2 space-x-2">
                                <span class="text-xs text-gray-600">{{ $data['total'] }} sessions</span>
                                @php
                                    $presentCount = $data['present'] + $data['late'];
                                    $rate = $data['total'] > 0 ? round(($presentCount / $data['total']) * 100, 1) : 0;
                                @endphp
                                <span class="text-sm font-semibold {{ $rate >= 80 ? 'text-green-600' : ($rate >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                    {{ $rate }}%
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>
    @else
        <!-- Table View -->
        <x-card title="Attendance Details">
            @if($attendanceData->count() > 0)
                <!-- Mobile/Tablet Card View (hidden on desktop) -->
                <div class="block lg:hidden">
                    <div class="space-y-4">
                        @foreach($attendanceData as $attendance)
                            <div class="p-4 border rounded-lg bg-gray-50">
                                <!-- Student Header -->
                                <div class="flex items-center mb-3">
                                    <div class="flex items-center justify-center w-10 h-10 bg-blue-500 rounded-full">
                                        <span class="text-xs font-semibold text-white">
                                            {{ strtoupper(substr($attendance->childProfile->full_name ?? 'UK', 0, 2)) }}
                                        </span>
                                    </div>
                                    <div class="flex-1 min-w-0 ml-3">
                                        <div class="font-medium text-gray-900 truncate">
                                            {{ $attendance->childProfile->full_name ?? 'Unknown' }}
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            ID: {{ $attendance->childProfile->id ?? 'N/A' }}
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <x-badge
                                            label="{{ ucfirst($attendance->status) }}"
                                            color="{{ match($attendance->status) {
                                                'present' => 'success',
                                                'late' => 'warning',
                                                'absent' => 'error',
                                                'excused' => 'info',
                                                default => 'ghost'
                                            } }}"
                                            class="badge-sm"
                                        />
                                    </div>
                                </div>

                                <!-- Details Grid -->
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Subject</label>
                                        <div class="text-sm text-gray-900">{{ $attendance->session->subject->name ?? 'Unknown' }}</div>
                                        <div class="text-xs text-gray-500">{{ $attendance->session->subject->code ?? '' }}</div>
                                    </div>
                                    <div>
                                        <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Teacher</label>
                                        <div class="text-sm text-gray-900">{{ $attendance->session->teacherProfile->user->name ?? 'Unknown' }}</div>
                                    </div>
                                    <div>
                                        <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Date & Time</label>
                                        <div class="text-sm text-gray-900">{{ $attendance->session->start_time->format('M d, Y') }}</div>
                                        <div class="text-xs text-gray-500">{{ $attendance->session->start_time->format('H:i A') }}</div>
                                    </div>
                                    <div>
                                        <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Remarks</label>
                                        <div class="text-sm text-gray-900">{{ $attendance->remarks ?? '-' }}</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Desktop Table View (hidden on mobile/tablet) -->
                <div class="hidden lg:block">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Student</th>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Subject</th>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Teacher</th>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Date & Time</th>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Remarks</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($attendanceData as $attendance)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                                                    <span class="text-xs font-semibold text-white">
                                                        {{ strtoupper(substr($attendance->childProfile->full_name ?? 'UK', 0, 2)) }}
                                                    </span>
                                                </div>
                                                <div class="ml-3">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        {{ $attendance->childProfile->full_name ?? 'Unknown' }}
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        ID: {{ $attendance->childProfile->id ?? 'N/A' }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">{{ $attendance->session->subject->name ?? 'Unknown' }}</div>
                                            <div class="text-sm text-gray-500">{{ $attendance->session->subject->code ?? '' }}</div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 whitespace-nowrap">
                                            {{ $attendance->session->teacherProfile->user->name ?? 'Unknown' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 whitespace-nowrap">
                                            <div>{{ $attendance->session->start_time->format('M d, Y') }}</div>
                                            <div class="text-xs text-gray-500">{{ $attendance->session->start_time->format('H:i A') }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <x-badge
                                                label="{{ ucfirst($attendance->status) }}"
                                                color="{{ match($attendance->status) {
                                                    'present' => 'success',
                                                    'late' => 'warning',
                                                    'absent' => 'error',
                                                    'excused' => 'info',
                                                    default => 'ghost'
                                                } }}"
                                                class="badge-sm"
                                            />
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            {{ $attendance->remarks ?? '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($attendanceData->count() >= 50)
                    <div class="p-4 mt-4 text-center rounded-lg bg-yellow-50">
                        <p class="text-sm text-yellow-700">
                            <x-icon name="o-information-circle" class="inline w-4 h-4 mr-1" />
                            Showing first 50 records. Use filters to narrow down results for better performance.
                        </p>
                    </div>
                @endif
            @else
                <div class="py-12 text-center">
                    <x-icon name="o-document-magnifying-glass" class="w-12 h-12 mx-auto text-gray-400" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900">No attendance records found</h3>
                    <p class="mt-1 text-sm text-gray-500">Try adjusting your filters to find attendance records.</p>
                </div>
            @endif
        </x-card>
    @endif
</div>
