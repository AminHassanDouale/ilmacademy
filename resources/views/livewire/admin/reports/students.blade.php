<?php

use App\Models\ChildProfile;
use App\Models\ProgramEnrollment;
use App\Models\SubjectEnrollment;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use App\Models\Attendance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Student Reports')] class extends Component {
    use Toast;

    // Filters
    #[Url]
    public ?int $academicYearId = null;

    #[Url]
    public ?int $curriculumId = null;

    #[Url]
    public string $reportType = 'enrollment';

    #[Url]
    public ?string $dateRange = 'current_term';

    #[Url]
    public ?string $gender = null;

    #[Url]
    public ?int $ageGroup = null;

    // Chart data and metrics
    public array $chartData = [];
    public array $metrics = [];

    // Report options
    protected array $reportTypes = [
        'enrollment' => 'Enrollment Statistics',
        'attendance' => 'Attendance Analysis',
        'performance' => 'Academic Performance',
        'demographics' => 'Student Demographics',
        'progression' => 'Academic Progression'
    ];

    protected array $dateRanges = [
        'current_term' => 'Current Term',
        'previous_term' => 'Previous Term',
        'current_year' => 'Current Academic Year',
        'previous_year' => 'Previous Academic Year',
        'last_30_days' => 'Last 30 Days',
        'last_90_days' => 'Last 90 Days',
        'custom' => 'Custom Range'
    ];

    protected array $ageGroups = [
        5 => 'Under 5 years',
        8 => '5-8 years',
        12 => '9-12 years',
        16 => '13-16 years',
        99 => '17+ years'
    ];

    // Custom date range (when dateRange = 'custom')
    public ?string $startDate = null;
    public ?string $endDate = null;

    // Mount the component
    public function mount(): void
    {
        // Set default academic year to current one
        if (!$this->academicYearId) {
            $currentAcademicYear = AcademicYear::where('is_current', true)->first();
            if ($currentAcademicYear) {
                $this->academicYearId = $currentAcademicYear->id;
            }
        }

        // Generate the report data
        $this->generateReport();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed student reports page - {$this->reportTypes[$this->reportType]}",
            ChildProfile::class,
            null,
            [
                'report_type' => $this->reportType,
                'filters' => [
                    'academic_year_id' => $this->academicYearId,
                    'curriculum_id' => $this->curriculumId,
                    'date_range' => $this->dateRange,
                    'gender' => $this->gender,
                    'age_group' => $this->ageGroup
                ],
                'ip' => request()->ip()
            ]
        );
    }

    // Generate the report based on the selected type and filters
    public function generateReport(): void
    {
        // Reset data
        $this->chartData = [];
        $this->metrics = [];

        // Based on report type, call the specific report generator
        switch ($this->reportType) {
            case 'enrollment':
                $this->generateEnrollmentReport();
                break;
            case 'attendance':
                $this->generateAttendanceReport();
                break;
            case 'performance':
                $this->generatePerformanceReport();
                break;
            case 'demographics':
                $this->generateDemographicsReport();
                break;
            case 'progression':
                $this->generateProgressionReport();
                break;
        }
    }

    // [Previous methods remain the same - generateEnrollmentReport, generateAttendanceReport, etc.]
    // ... (keeping all the existing report generation methods as they are)

    // Enrollment Report
    protected function generateEnrollmentReport(): void
    {
        // Get date range constraints
        $dateConstraints = $this->getDateConstraints();

        // Base query for enrollments with filters
        $query = ProgramEnrollment::query()
            ->join('child_profiles', 'program_enrollments.child_profile_id', '=', 'child_profiles.id')
            ->when($this->academicYearId, function ($q) {
                $q->where('program_enrollments.academic_year_id', $this->academicYearId);
            })
            ->when($this->curriculumId, function ($q) {
                $q->where('program_enrollments.curriculum_id', $this->curriculumId);
            })
            ->when($this->gender, function ($q) {
                $q->where('child_profiles.gender', $this->gender);
            })
            ->when($this->ageGroup, function ($q) {
                $this->applyAgeGroupFilter($q);
            })
            ->when($dateConstraints['start'], function ($q, $start) {
                $q->where('program_enrollments.created_at', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('program_enrollments.created_at', '<=', $end);
            });

        // Get enrollment counts by status
        $enrollmentsByStatus = $query->clone()
            ->select('program_enrollments.status', DB::raw('count(*) as count'))
            ->groupBy('program_enrollments.status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        // Get enrollment counts by curriculum
        $enrollmentsByCurriculum = $query->clone()
            ->select('curricula.name', DB::raw('count(*) as count'))
            ->join('curricula', 'program_enrollments.curriculum_id', '=', 'curricula.id')
            ->groupBy('curricula.name')
            ->get()
            ->pluck('count', 'name')
            ->toArray();

        // Calculate total enrollments
        $totalEnrollments = array_sum($enrollmentsByStatus);

        // Prepare chart data for enrollments by status
        $statusChartData = [];
        foreach ($enrollmentsByStatus as $status => $count) {
            $statusChartData[] = [
                'name' => $status,
                'value' => $count
            ];
        }

        // Prepare chart data for enrollments by curriculum
        $curriculumChartData = [];
        foreach ($enrollmentsByCurriculum as $curriculum => $count) {
            $curriculumChartData[] = [
                'name' => $curriculum,
                'value' => $count
            ];
        }

        // Set metrics
        $this->metrics = [
            'total_enrollments' => $totalEnrollments,
            'active_enrollments' => $enrollmentsByStatus['Active'] ?? 0,
            'pending_enrollments' => $enrollmentsByStatus['Pending'] ?? 0,
        ];

        // Set chart data
        $this->chartData = [
            'status' => $statusChartData,
            'curriculum' => $curriculumChartData,
        ];
    }

    // Attendance Report
    protected function generateAttendanceReport(): void
    {
        // Get date range constraints
        $dateConstraints = $this->getDateConstraints();

        // Base query for attendance with filters
        // Using child_profile_id from attendances table to join with child_profiles
        $query = Attendance::query()
            ->join('sessions', 'attendances.session_id', '=', 'sessions.id')
            ->join('subjects', 'sessions.subject_id', '=', 'subjects.id')
            ->join('child_profiles', 'attendances.child_profile_id', '=', 'child_profiles.id')
            ->join('program_enrollments', 'child_profiles.id', '=', 'program_enrollments.child_profile_id')
            ->when($this->academicYearId, function ($q) {
                $q->where('program_enrollments.academic_year_id', $this->academicYearId);
            })
            ->when($this->curriculumId, function ($q) {
                $q->where('program_enrollments.curriculum_id', $this->curriculumId);
            })
            ->when($this->gender, function ($q) {
                $q->where('child_profiles.gender', $this->gender);
            })
            ->when($this->ageGroup, function ($q) {
                $this->applyAgeGroupFilter($q);
            })
            ->when($dateConstraints['start'], function ($q, $start) {
                $q->where('sessions.start_time', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('sessions.start_time', '<=', $end);
            });

        // Get attendance counts by status (normalize case since seeder uses lowercase)
        $attendanceByStatus = $query->clone()
            ->select(
                DB::raw('UPPER(LEFT(attendances.status, 1)) as normalized_status'),
                DB::raw('count(*) as count')
            )
            ->groupBy('normalized_status')
            ->get()
            ->mapWithKeys(function ($item) {
                // Convert to proper case
                $status = match(strtolower($item->normalized_status)) {
                    'p' => 'Present',
                    'a' => 'Absent',
                    'l' => 'Late',
                    'e' => 'Excused',
                    default => ucfirst($item->normalized_status)
                };
                return [$status => $item->count];
            })
            ->toArray();

        // Get attendance by subject
        $attendanceBySubject = $query->clone()
            ->select('subjects.name',
                    DB::raw('count(*) as total'),
                    DB::raw('SUM(CASE WHEN LOWER(attendances.status) = "present" THEN 1 ELSE 0 END) as present'),
                    DB::raw('SUM(CASE WHEN LOWER(attendances.status) = "absent" THEN 1 ELSE 0 END) as absent'),
                    DB::raw('SUM(CASE WHEN LOWER(attendances.status) = "late" THEN 1 ELSE 0 END) as late'),
                    DB::raw('SUM(CASE WHEN LOWER(attendances.status) = "excused" THEN 1 ELSE 0 END) as excused'))
            ->groupBy('subjects.name')
            ->get()
            ->map(function ($item) {
                $attendanceRate = $item->total > 0 ? ($item->present / $item->total) * 100 : 0;
                return [
                    'subject' => $item->name,
                    'present' => $item->present,
                    'absent' => $item->absent,
                    'late' => $item->late,
                    'excused' => $item->excused,
                    'attendance_rate' => round($attendanceRate, 1)
                ];
            })
            ->toArray();

        // Calculate overall attendance rate
        $totalAttendance = array_sum($attendanceByStatus);
        $presentCount = $attendanceByStatus['Present'] ?? 0;
        $attendanceRate = $totalAttendance > 0 ? ($presentCount / $totalAttendance) * 100 : 0;

        // Prepare chart data for attendance by status
        $statusChartData = [];
        foreach ($attendanceByStatus as $status => $count) {
            $statusChartData[] = [
                'name' => $status,
                'value' => $count
            ];
        }

        // Set metrics
        $this->metrics = [
            'total_sessions' => $query->clone()->select('sessions.id')->distinct()->count('sessions.id'),
            'total_attendance_records' => $totalAttendance,
            'attendance_rate' => round($attendanceRate, 1),
            'present_count' => $presentCount,
            'absent_count' => $attendanceByStatus['Absent'] ?? 0,
            'late_count' => $attendanceByStatus['Late'] ?? 0,
            'excused_count' => $attendanceByStatus['Excused'] ?? 0,
            'subjects_with_highest_attendance' => collect($attendanceBySubject)
                ->sortByDesc('attendance_rate')
                ->take(3)
                ->toArray(),
            'subjects_with_lowest_attendance' => collect($attendanceBySubject)
                ->sortBy('attendance_rate')
                ->take(3)
                ->toArray()
        ];

        // Set chart data
        $this->chartData = [
            'status' => $statusChartData,
            'by_subject' => $attendanceBySubject,
        ];
    }

    // Performance Report
    protected function generatePerformanceReport(): void
    {
        // Mock data for subjects and grade distribution
        $subjects = ['Mathematics', 'Science', 'Language Arts', 'Social Studies', 'Art', 'Music', 'Physical Education'];
        $gradeDistribution = [
            'A' => [15, 12, 18, 20, 25, 22, 19],
            'B' => [25, 28, 22, 18, 15, 20, 24],
            'C' => [35, 30, 28, 25, 20, 18, 22],
            'D' => [15, 20, 18, 22, 25, 28, 20],
            'F' => [10, 10, 14, 15, 15, 12, 15]
        ];

        // Calculate overall metrics
        $totalStudents = array_sum($gradeDistribution['A']) +
                        array_sum($gradeDistribution['B']) +
                        array_sum($gradeDistribution['C']) +
                        array_sum($gradeDistribution['D']) +
                        array_sum($gradeDistribution['F']);

        $passCount = array_sum($gradeDistribution['A']) +
                    array_sum($gradeDistribution['B']) +
                    array_sum($gradeDistribution['C']);

        $weightedSum = (array_sum($gradeDistribution['A']) * 4) +
                       (array_sum($gradeDistribution['B']) * 3) +
                       (array_sum($gradeDistribution['C']) * 2) +
                       (array_sum($gradeDistribution['D']) * 1);

        $overallGpa = $totalStudents > 0 ? $weightedSum / $totalStudents : 0;
        $passRate = $totalStudents > 0 ? ($passCount / $totalStudents) * 100 : 0;

        // Prepare chart data for grade distribution
        $gradeChartData = [];
        foreach ($gradeDistribution as $grade => $counts) {
            $gradeChartData[] = [
                'name' => $grade,
                'value' => array_sum($counts)
            ];
        }

        // Set metrics
        $this->metrics = [
            'total_students' => $totalStudents,
            'overall_gpa' => round($overallGpa, 2),
            'pass_rate' => round($passRate, 1),
        ];

        // Set chart data
        $this->chartData = [
            'status' => $gradeChartData,
        ];
    }

    // Demographics Report
    protected function generateDemographicsReport(): void
    {
        // Get date range constraints
        $dateConstraints = $this->getDateConstraints();

        // Base query for students with filters
        $query = ChildProfile::query()
            ->join('program_enrollments', 'child_profiles.id', '=', 'program_enrollments.child_profile_id')
            ->when($this->academicYearId, function ($q) {
                $q->where('program_enrollments.academic_year_id', $this->academicYearId);
            })
            ->when($this->curriculumId, function ($q) {
                $q->where('program_enrollments.curriculum_id', $this->curriculumId);
            })
            ->when($this->gender, function ($q) {
                $q->where('child_profiles.gender', $this->gender);
            })
            ->when($this->ageGroup, function ($q) {
                $this->applyAgeGroupFilter($q);
            })
            ->when($dateConstraints['start'], function ($q, $start) {
                $q->where('program_enrollments.created_at', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('program_enrollments.created_at', '<=', $end);
            })
            ->distinct('child_profiles.id');

        // Get students by gender
        $studentsByGender = $query->clone()
            ->select('child_profiles.gender', DB::raw('count(distinct child_profiles.id) as count'))
            ->groupBy('child_profiles.gender')
            ->get()
            ->pluck('count', 'gender')
            ->toArray();

        // Calculate total students
        $totalStudents = $query->clone()->distinct('child_profiles.id')->count('child_profiles.id');

        // Prepare chart data for gender distribution
        $genderChartData = [];
        foreach ($studentsByGender as $gender => $count) {
            $genderChartData[] = [
                'name' => $gender ?: 'Unspecified',
                'value' => $count
            ];
        }

        // Set metrics
        $this->metrics = [
            'total_students' => $totalStudents,
            'gender_distribution' => [
                'male' => $studentsByGender['Male'] ?? 0,
                'female' => $studentsByGender['Female'] ?? 0,
                'other' => $studentsByGender['Other'] ?? 0,
                'male_percentage' => $totalStudents > 0 ? round((($studentsByGender['Male'] ?? 0) / $totalStudents) * 100, 1) : 0,
                'female_percentage' => $totalStudents > 0 ? round((($studentsByGender['Female'] ?? 0) / $totalStudents) * 100, 1) : 0
            ],
        ];

        // Set chart data
        $this->chartData = [
            'status' => $genderChartData,
        ];
    }

    // Academic Progression Report
    protected function generateProgressionReport(): void
    {
        // Mock progression data
        $curricula = ['Beginner', 'Intermediate', 'Advanced'];
        $completionRates = [65, 75, 85];

        // Prepare chart data
        $progressionChartData = [];
        foreach ($curricula as $index => $curriculum) {
            $progressionChartData[] = [
                'name' => $curriculum,
                'value' => $completionRates[$index]
            ];
        }

        // Set metrics
        $this->metrics = [
            'average_completion_rate' => round(array_sum($completionRates) / count($completionRates), 1),
            'highest_completion_curriculum' => $curricula[array_keys($completionRates, max($completionRates))[0]],
            'lowest_completion_curriculum' => $curricula[array_keys($completionRates, min($completionRates))[0]],
        ];

        // Set chart data
        $this->chartData = [
            'status' => $progressionChartData,
        ];
    }

    // Helper to get previous period for growth comparison
    protected function getPreviousPeriod(): array
    {
        $current = $this->getDateConstraints();
        $previous = [
            'start' => null,
            'end' => null
        ];

        if ($current['start'] && $current['end']) {
            $duration = strtotime($current['end']) - strtotime($current['start']);
            $previous['end'] = date('Y-m-d', strtotime($current['start']) - 86400);
            $previous['start'] = date('Y-m-d', strtotime($previous['end']) - $duration);
        }

        return $previous;
    }

    // Helper to get date constraints based on date range selection
    protected function getDateConstraints(): array
    {
        $constraints = [
            'start' => null,
            'end' => null
        ];

        switch ($this->dateRange) {
            case 'current_term':
                $currentYear = AcademicYear::where('is_current', true)->first();
                if ($currentYear) {
                    $constraints['start'] = $currentYear->start_date;
                    $constraints['end'] = $currentYear->end_date;
                }
                break;

            case 'previous_term':
                $previousYear = AcademicYear::where('is_current', false)
                    ->orderByDesc('end_date')
                    ->first();
                if ($previousYear) {
                    $constraints['start'] = $previousYear->start_date;
                    $constraints['end'] = $previousYear->end_date;
                }
                break;

            case 'current_year':
                $constraints['start'] = date('Y-01-01');
                $constraints['end'] = date('Y-12-31');
                break;

            case 'previous_year':
                $year = date('Y') - 1;
                $constraints['start'] = "{$year}-01-01";
                $constraints['end'] = "{$year}-12-31";
                break;

            case 'last_30_days':
                $constraints['start'] = date('Y-m-d', strtotime('-30 days'));
                $constraints['end'] = date('Y-m-d');
                break;

            case 'last_90_days':
                $constraints['start'] = date('Y-m-d', strtotime('-90 days'));
                $constraints['end'] = date('Y-m-d');
                break;

            case 'custom':
                if ($this->startDate && $this->endDate) {
                    $constraints['start'] = $this->startDate;
                    $constraints['end'] = $this->endDate;
                }
                break;
        }

        return $constraints;
    }

    // Apply age group filter to a query
    protected function applyAgeGroupFilter($query): void
    {
        if (!$this->ageGroup) {
            return;
        }

        $currentDate = date('Y-m-d');

        switch ($this->ageGroup) {
            case 5:
                $query->whereRaw('TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, ?) < 5', [$currentDate]);
                break;
            case 8:
                $query->whereRaw('TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, ?) BETWEEN 5 AND 8', [$currentDate]);
                break;
            case 12:
                $query->whereRaw('TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, ?) BETWEEN 9 AND 12', [$currentDate]);
                break;
            case 16:
                $query->whereRaw('TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, ?) BETWEEN 13 AND 16', [$currentDate]);
                break;
            case 99:
                $query->whereRaw('TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, ?) >= 17', [$currentDate]);
                break;
        }
    }

    // Handle change in report type
    public function updatedReportType(): void
    {
        $this->generateReport();
    }

    // Handle change in academic year
    public function updatedAcademicYearId(): void
    {
        $this->generateReport();
    }

    // Handle change in curriculum
    public function updatedCurriculumId(): void
    {
        $this->generateReport();
    }

    // Handle change in date range
    public function updatedDateRange(): void
    {
        $this->generateReport();
    }

    // Handle change in gender filter
    public function updatedGender(): void
    {
        $this->generateReport();
    }

    // Handle change in age group filter
    public function updatedAgeGroup(): void
    {
        $this->generateReport();
    }

    // Handle change in custom date range
    public function updatedStartDate(): void
    {
        if ($this->dateRange === 'custom' && $this->startDate && $this->endDate) {
            $this->generateReport();
        }
    }

    public function updatedEndDate(): void
    {
        if ($this->dateRange === 'custom' && $this->startDate && $this->endDate) {
            $this->generateReport();
        }
    }

    // Reset all filters
    public function resetFilters(): void
    {
        $this->academicYearId = AcademicYear::where('is_current', true)->first()?->id;
        $this->curriculumId = null;
        $this->dateRange = 'current_term';
        $this->gender = null;
        $this->ageGroup = null;
        $this->startDate = null;
        $this->endDate = null;

        $this->generateReport();
    }

    // Export report data
    public function exportReport(): void
    {
        ActivityLog::log(
            Auth::id(),
            'export',
            "Exported {$this->reportTypes[$this->reportType]} report",
            ChildProfile::class,
            null,
            [
                'report_type' => $this->reportType,
                'filters' => [
                    'academic_year_id' => $this->academicYearId,
                    'curriculum_id' => $this->curriculumId,
                    'date_range' => $this->dateRange,
                    'gender' => $this->gender,
                    'age_group' => $this->ageGroup
                ],
                'ip' => request()->ip()
            ]
        );

        $this->success("Report has been exported successfully.");
    }

    // Get academic years for dropdown
    public function academicYears(): Collection
    {
        return AcademicYear::orderByDesc('start_date')->get();
    }

    // Get curricula for dropdown
    public function curricula(): Collection
    {
        return Curriculum::orderBy('name')->get();
    }

    // Get report types as key-value array for select component
    public function getReportTypesForSelect(): array
    {
        return collect($this->reportTypes)->map(function ($label, $value) {
            return ['value' => $value, 'label' => $label];
        })->values()->toArray();
    }

    // Get date ranges as key-value array for select component
    public function getDateRangesForSelect(): array
    {
        return collect($this->dateRanges)->map(function ($label, $value) {
            return ['value' => $value, 'label' => $label];
        })->values()->toArray();
    }

    // Get age groups as key-value array for select component
    public function getAgeGroupsForSelect(): array
    {
        return collect($this->ageGroups)->map(function ($label, $value) {
            return ['value' => $value, 'label' => $label];
        })->values()->toArray();
    }

    // Get report types (public method to access in view)
    public function getReportTypes(): array
    {
        return $this->reportTypes;
    }

    // Get date ranges (public method to access in view)
    public function getDateRanges(): array
    {
        return $this->dateRanges;
    }

    // Get age groups (public method to access in view)
    public function getAgeGroups(): array
    {
        return $this->ageGroups;
    }

    public function with(): array
    {
        return [
            'academicYears' => $this->academicYears(),
            'curricula' => $this->curricula(),
            'reportTypesForSelect' => $this->getReportTypesForSelect(),
            'dateRangesForSelect' => $this->getDateRangesForSelect(),
            'ageGroupsForSelect' => $this->getAgeGroupsForSelect(),
            'reportTypes' => $this->getReportTypes(),
            'dateRanges' => $this->getDateRanges(),
            'ageGroups' => $this->getAgeGroups(),
            'chartData' => $this->chartData,
            'metrics' => $this->metrics,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Student Reports" separator>
        <x-slot:subtitle>
            {{ $reportTypes[$reportType] }}
            @if($academicYearId)
                for {{ collect($academicYears)->firstWhere('id', $academicYearId)?->name }}
            @endif
        </x-slot:subtitle>

        <x-slot:actions>
            <div class="flex space-x-2">
                <x-button
                    label="Export Report"
                    icon="o-arrow-down-tray"
                    wire:click="exportReport"
                    color="primary"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Filters section -->
    <x-card class="mb-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <!-- Report Type -->
            <div>
                <x-select
                    label="Report Type"
                    :options="$reportTypesForSelect"
                    option-label="label"
                    option-value="value"
                    wire:model.live="reportType"
                    hint="Select the type of report to generate"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-chart-bar" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>

            <!-- Academic Year -->
            <div>
                <x-select
                    label="Academic Year"
                    placeholder="Select academic year"
                    :options="$academicYears"
                    wire:model.live="academicYearId"
                    option-label="name"
                    option-value="id"
                    option-description="description"
                    hint="Filter by academic year"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-academic-cap" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>

            <!-- Curriculum -->
            <div>
                <x-select
                    label="Curriculum"
                    placeholder="All curricula"
                    :options="$curricula"
                    wire:model.live="curriculumId"
                    option-label="name"
                    option-value="id"
                    option-description="description"
                    hint="Filter by curriculum"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-book-open" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>

            <!-- Date Range -->
            <div>
                <x-select
                    label="Date Range"
                    :options="$dateRangesForSelect"
                    option-label="label"
                    option-value="value"
                    wire:model.live="dateRange"
                    hint="Select time period for the report"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-calendar" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>

            <!-- Custom Date Range (conditionally shown) -->
            @if($dateRange === 'custom')
                @php
                    $config = ['altFormat' => 'm/d/Y', 'dateFormat' => 'Y-m-d'];
                @endphp

                <div>
                    <x-datepicker
                        label="Start Date"
                        wire:model.live="startDate"
                        icon="o-calendar"
                        :config="$config"
                        hint="Beginning of date range"
                    />
                </div>

                <div>
                    <x-datepicker
                        label="End Date"
                        wire:model.live="endDate"
                        icon="o-calendar"
                        :config="$config"
                        hint="End of date range"
                    />
                </div>
            @endif

            <!-- Gender Filter -->
            <div>
                <x-select
                    label="Gender"
                    placeholder="All genders"
                    :options="[
                        ['value' => 'Male', 'label' => 'Male'],
                        ['value' => 'Female', 'label' => 'Female'],
                        ['value' => 'Other', 'label' => 'Other']
                    ]"
                    option-label="label"
                    option-value="value"
                    wire:model.live="gender"
                    hint="Filter by gender"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-user" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>

            <!-- Age Group Filter -->
            <div>
                <x-select
                    label="Age Group"
                    placeholder="All ages"
                    :options="$ageGroupsForSelect"
                    option-label="label"
                    option-value="value"
                    wire:model.live="ageGroup"
                    hint="Filter by age group"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-cake" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>
        </div>

        <div class="flex justify-end mt-4">
            <x-button
                label="Reset Filters"
                icon="o-x-mark"
                wire:click="resetFilters"
                class="btn-ghost"
            />
        </div>
    </x-card>

    <!-- Key Metrics -->
    <x-card title="Key Metrics" class="mb-6">
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <div class="p-4 rounded-lg bg-base-200">
                <div class="text-sm font-medium text-gray-500">Total Enrollments</div>
                <div class="text-3xl font-bold">{{ number_format($metrics['total_enrollments'] ?? 0) }}</div>
            </div>

            <div class="p-4 rounded-lg bg-base-200">
                <div class="text-sm font-medium text-gray-500">Active Enrollments</div>
                <div class="text-3xl font-bold text-success">{{ number_format($metrics['active_enrollments'] ?? 0) }}</div>
            </div>

            <div class="p-4 rounded-lg bg-base-200">
                <div class="text-sm font-medium text-gray-500">Pending Enrollments</div>
                <div class="text-3xl font-bold text-warning">{{ number_format($metrics['pending_enrollments'] ?? 0) }}</div>
            </div>
        </div>
    </x-card>

    <!-- Charts Section -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Chart 1 -->
        <x-card title="Enrollment by Status">
            <div>
                <div x-data="{
                    chartData: {{ json_encode($chartData['status'] ?? []) }},
                }" x-init="
                    if(chartData.length > 0) {
                        const ctx = document.getElementById('chart1').getContext('2d');
                        new Chart(ctx, {
                            type: 'pie',
                            data: {
                                labels: chartData.map(item => item.name),
                                datasets: [{
                                    data: chartData.map(item => item.value),
                                    backgroundColor: [
                                        '#36A2EB', '#FF6384', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#C9CBCF'
                                    ]
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: {
                                        position: 'bottom'
                                    }
                                }
                            }
                        });
                    }
                ">
                    @if(!empty($chartData['status']))
                        <div class="h-64">
                            <canvas id="chart1"></canvas>
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center h-64 p-4">
                            <x-icon name="o-chart-pie" class="w-16 h-16 text-gray-300" />
                            <p class="mt-2 text-gray-500">No data available for the selected filters</p>
                        </div>
                    @endif
                </div>
            </div>
        </x-card>

        <!-- Additional charts would go here -->
        <x-card title="Data Table">
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $totalEnrollments = $metrics['total_enrollments'] ?? 0;
                        @endphp
                        @foreach($chartData['status'] ?? [] as $item)
                            <tr>
                                <td>{{ $item['name'] }}</td>
                                <td class="text-right">{{ number_format($item['value']) }}</td>
                                <td class="text-right">
                                    {{ $totalEnrollments > 0 ? round(($item['value'] / $totalEnrollments) * 100, 1) : 0 }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th class="text-right">{{ number_format($totalEnrollments) }}</th>
                            <th class="text-right">100%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-card>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
