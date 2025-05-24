<?php

use App\Models\Session;
use App\Models\Attendance;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Attendance Report')] class extends Component {
    use Toast;

    public string $selectedAcademicYear = '';
    public string $selectedCurriculum = '';
    public string $selectedSubject = '';
    public string $selectedTeacher = '';
    public string $attendanceStatus = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $reportFormat = 'table';

    public array $attendanceStatuses = [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'excused' => 'Excused'
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
                // Filter by session subject curriculum
                $q->whereHas('session.subject', function($subQuery) {
                    $subQuery->where('curriculum_id', $this->selectedCurriculum);
                })
                // OR filter by student enrollment curriculum
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

        // Filter by attendance status
        if ($this->attendanceStatus) {
            $query->where('status', $this->attendanceStatus);
        }

        // Filter by date range
        if ($this->dateFrom && $this->dateTo) {
            $query->whereHas('session', function($q) {
                $q->whereBetween('start_time', [$this->dateFrom, $this->dateTo]);
            });
        }

        return $query->get();
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
            'daily_breakdown' => [],
            'student_attendance_rates' => []
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

        // Student attendance rates
        $studentAttendances = $attendances->groupBy('child_profile_id');
        foreach ($studentAttendances as $studentId => $studentRecords) {
            $studentName = $studentRecords->first()->childProfile->full_name ?? 'Unknown';
            $totalSessions = $studentRecords->count();
            $presentSessions = $studentRecords->whereIn('status', ['present', 'late'])->count();
            $rate = $totalSessions > 0 ? round(($presentSessions / $totalSessions) * 100, 2) : 0;

            $stats['student_attendance_rates'][] = [
                'student_name' => $studentName,
                'total_sessions' => $totalSessions,
                'present_sessions' => $presentSessions,
                'attendance_rate' => $rate
            ];
        }

        // Sort students by attendance rate
        usort($stats['student_attendance_rates'], function($a, $b) {
            return $b['attendance_rate'] <=> $a['attendance_rate'];
        });

        return $stats;
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
        $this->attendanceStatus = '';
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->endOfMonth()->format('Y-m-d');
        $this->success('Filters reset successfully.');
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Attendance Report" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <x-badge
                    label="{{ $this->attendanceData->count() }} Records"
                    color="primary"
                    class="badge-lg"
                />
                <x-badge
                    label="{{ $this->statistics['attendance_rate'] }}% Attendance Rate"
                    color="{{ $this->statistics['attendance_rate'] >= 80 ? 'success' : ($this->statistics['attendance_rate'] >= 60 ? 'warning' : 'error') }}"
                    class="badge-lg"
                />
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <div class="flex gap-2">
                <x-button
                    label="Export"
                    icon="o-arrow-down-tray"
                    wire:click="exportReport"
                    color="success"
                />
                <x-button
                    label="Reset Filters"
                    icon="o-arrow-path"
                    wire:click="resetFilters"
                    class="btn-ghost"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Filters -->
    <x-card title="Report Filters" class="mb-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3 lg:grid-cols-5">
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
                <label class="block mb-2 text-sm font-medium text-gray-700">Status</label>
                <select wire:model.live="attendanceStatus" class="w-full select select-bordered">
                    <option value="">All Statuses</option>
                    @foreach($attendanceStatuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 mt-4 md:grid-cols-3">
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
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-2 lg:grid-cols-5">
        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-blue-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total Records</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['total_attendances'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-green-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Present</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['present_count'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-red-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Absent</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['absent_count'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-yellow-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Late</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['late_count'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-purple-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Excused</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['excused_count'] }}</p>
                </div>
            </div>
        </x-card>
    </div>

    @if($reportFormat === 'summary')
        <!-- Summary View -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Subject Breakdown -->
            <x-card title="Attendance by Subject">
                <div class="space-y-3">
                    @foreach($this->statistics['subject_breakdown'] as $subject => $data)
                        <div class="p-3 border rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-semibold">{{ $subject }}</span>
                                <span class="text-sm text-gray-600">{{ $data['total'] }} sessions</span>
                            </div>
                            <div class="grid grid-cols-4 gap-2 text-sm">
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

            <!-- Top Students by Attendance -->
            <x-card title="Top Students by Attendance Rate">
                <div class="space-y-3">
                    @foreach(array_slice($this->statistics['student_attendance_rates'], 0, 10) as $student)
                        <div class="flex items-center justify-between p-3 border rounded-lg">
                            <div>
                                <div class="font-semibold">{{ $student['student_name'] }}</div>
                                <div class="text-sm text-gray-600">
                                    {{ $student['present_sessions'] }}/{{ $student['total_sessions'] }} sessions
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-bold {{ $student['attendance_rate'] >= 80 ? 'text-green-600' : ($student['attendance_rate'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                    {{ $student['attendance_rate'] }}%
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
                            <div class="w-32 h-3 bg-gray-200 rounded-full">
                                @php
                                    $percentage = $this->statistics['total_attendances'] > 0 ? ($this->statistics['present_count'] / $this->statistics['total_attendances']) * 100 : 0;
                                @endphp
                                <div class="h-3 bg-green-500 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>
                            <span class="text-sm font-medium">{{ round($percentage, 1) }}%</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Absent</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-32 h-3 bg-gray-200 rounded-full">
                                @php
                                    $percentage = $this->statistics['total_attendances'] > 0 ? ($this->statistics['absent_count'] / $this->statistics['total_attendances']) * 100 : 0;
                                @endphp
                                <div class="h-3 bg-red-500 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>
                            <span class="text-sm font-medium">{{ round($percentage, 1) }}%</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Late</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-32 h-3 bg-gray-200 rounded-full">
                                @php
                                    $percentage = $this->statistics['total_attendances'] > 0 ? ($this->statistics['late_count'] / $this->statistics['total_attendances']) * 100 : 0;
                                @endphp
                                <div class="h-3 bg-yellow-500 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>
                            <span class="text-sm font-medium">{{ round($percentage, 1) }}%</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Excused</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-32 h-3 bg-gray-200 rounded-full">
                                @php
                                    $percentage = $this->statistics['total_attendances'] > 0 ? ($this->statistics['excused_count'] / $this->statistics['total_attendances']) * 100 : 0;
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
                <div class="space-y-2">
                    @foreach(array_slice($this->statistics['daily_breakdown'], -7, 7, true) as $date => $data)
                        <div class="flex items-center justify-between p-2 border rounded">
                            <span class="text-sm font-medium">{{ \Carbon\Carbon::parse($date)->format('M d, Y') }}</span>
                            <div class="flex items-center space-x-2">
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
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Student</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Subject</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Teacher</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($this->attendanceData as $attendance)
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
                                    {{ $attendance->session->start_time->format('M d, Y H:i') }}
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
        </x-card>
    @endif
</div>
