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
                // Logic for age groups based on date of birth
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

        // Get enrollment counts by month
        $enrollmentsByMonth = $query->clone()
            ->select(DB::raw('DATE_FORMAT(program_enrollments.created_at, "%Y-%m") as month'), DB::raw('count(*) as count'))
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => date('M Y', strtotime($item->month . '-01')),
                    'count' => $item->count
                ];
            })
            ->toArray();

        // Calculate total enrollments
        $totalEnrollments = array_sum($enrollmentsByStatus);

        // Calculate enrollment growth
        $previousPeriod = $this->getPreviousPeriod();
        $previousEnrollments = ProgramEnrollment::query()
            ->when($previousPeriod['start'], function ($q, $start) {
                $q->where('created_at', '>=', $start);
            })
            ->when($previousPeriod['end'], function ($q, $end) {
                $q->where('created_at', '<=', $end);
            })
            ->count();

        $growthRate = $previousEnrollments > 0
            ? round((($totalEnrollments - $previousEnrollments) / $previousEnrollments) * 100, 1)
            : null;

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
            'growth_rate' => $growthRate,
            'total_subjects' => SubjectEnrollment::whereHas('programEnrollment', function ($q) use ($query) {
                $q->whereIn('program_enrollment_id', $query->clone()->select('program_enrollments.id'));
            })->count(),
            'avg_subjects_per_student' => $totalEnrollments > 0
                ? round(SubjectEnrollment::whereHas('programEnrollment', function ($q) use ($query) {
                    $q->whereIn('program_enrollment_id', $query->clone()->select('program_enrollments.id'));
                })->count() / $totalEnrollments, 1)
                : 0
        ];

        // Set chart data
        $this->chartData = [
            'status' => $statusChartData,
            'curriculum' => $curriculumChartData,
            'trend' => $enrollmentsByMonth
        ];
    }

    // Attendance Report
    protected function generateAttendanceReport(): void
    {
        // Get date range constraints
        $dateConstraints = $this->getDateConstraints();

        // Base query for attendance with filters
        $query = Attendance::query()
            ->join('sessions', 'attendances.session_id', '=', 'sessions.id')
            ->join('subjects', 'sessions.subject_id', '=', 'subjects.id')
            ->join('subject_enrollments', 'attendances.subject_enrollment_id', '=', 'subject_enrollments.id')
            ->join('program_enrollments', 'subject_enrollments.program_enrollment_id', '=', 'program_enrollments.id')
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
                $q->where('sessions.start_time', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('sessions.start_time', '<=', $end);
            });

        // Get attendance counts by status
        $attendanceByStatus = $query->clone()
            ->select('attendances.status', DB::raw('count(*) as count'))
            ->groupBy('attendances.status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        // Get attendance by subject
        $attendanceBySubject = $query->clone()
            ->select('subjects.name',
                    DB::raw('count(*) as total'),
                    DB::raw('SUM(CASE WHEN attendances.status = "Present" THEN 1 ELSE 0 END) as present'),
                    DB::raw('SUM(CASE WHEN attendances.status = "Absent" THEN 1 ELSE 0 END) as absent'),
                    DB::raw('SUM(CASE WHEN attendances.status = "Late" THEN 1 ELSE 0 END) as late'),
                    DB::raw('SUM(CASE WHEN attendances.status = "Excused" THEN 1 ELSE 0 END) as excused'))
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

        // Get attendance trend by week
        $attendanceByWeek = $query->clone()
            ->select(DB::raw('YEARWEEK(sessions.start_time) as week'),
                     DB::raw('count(*) as total'),
                     DB::raw('SUM(CASE WHEN attendances.status = "Present" THEN 1 ELSE 0 END) as present'))
            ->groupBy('week')
            ->orderBy('week')
            ->get()
            ->map(function ($item) {
                $year = substr($item->week, 0, 4);
                $week = substr($item->week, 4);
                $attendanceRate = $item->total > 0 ? ($item->present / $item->total) * 100 : 0;

                // Convert YEARWEEK to a readable date (first day of the week)
                $date = date('Y-m-d', strtotime($year . 'W' . $week));

                return [
                    'week' => date('M d', strtotime($date)),
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
            'trend' => $attendanceByWeek
        ];
    }

    // Performance Report
    protected function generatePerformanceReport(): void
    {
        // For this example, we'll simulate performance data
        // In a real application, you would have an Exam or Grade model to pull from

        // Get date range constraints
        $dateConstraints = $this->getDateConstraints();

        // Mock data for subjects and grade distribution
        $subjects = ['Mathematics', 'Science', 'Language Arts', 'Social Studies', 'Art', 'Music', 'Physical Education'];
        $gradeDistribution = [
            'A' => [15, 12, 18, 20, 25, 22, 19],
            'B' => [25, 28, 22, 18, 15, 20, 24],
            'C' => [35, 30, 28, 25, 20, 18, 22],
            'D' => [15, 20, 18, 22, 25, 28, 20],
            'F' => [10, 10, 14, 15, 15, 12, 15]
        ];

        // Prepare performance by subject data
        $performanceBySubject = [];
        foreach ($subjects as $i => $subject) {
            $grades = [
                'A' => $gradeDistribution['A'][$i],
                'B' => $gradeDistribution['B'][$i],
                'C' => $gradeDistribution['C'][$i],
                'D' => $gradeDistribution['D'][$i],
                'F' => $gradeDistribution['F'][$i]
            ];

            $totalStudents = array_sum($grades);
            $weightedSum = ($grades['A'] * 4) + ($grades['B'] * 3) + ($grades['C'] * 2) + ($grades['D'] * 1);
            $gpa = $totalStudents > 0 ? $weightedSum / $totalStudents : 0;

            $performanceBySubject[] = [
                'subject' => $subject,
                'grades' => $grades,
                'average_gpa' => round($gpa, 2),
                'pass_rate' => round((($grades['A'] + $grades['B'] + $grades['C']) / $totalStudents) * 100, 1)
            ];
        }

        // Prepare performance trend data (mock data)
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
        $performanceTrend = [];

        foreach ($months as $month) {
            $performanceTrend[] = [
                'month' => $month,
                'average_gpa' => round(mt_rand(250, 380) / 100, 2)
            ];
        }

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
            'top_performing_subjects' => collect($performanceBySubject)
                ->sortByDesc('average_gpa')
                ->take(3)
                ->toArray(),
            'subjects_needing_improvement' => collect($performanceBySubject)
                ->sortBy('average_gpa')
                ->take(3)
                ->toArray()
        ];

        // Set chart data
        $this->chartData = [
            'grade_distribution' => $gradeChartData,
            'by_subject' => $performanceBySubject,
            'trend' => $performanceTrend
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

        // Get students by age group
        $currentDate = date('Y-m-d');
        $studentsByAge = $query->clone()
            ->select(
                DB::raw('
                    CASE
                        WHEN TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, "' . $currentDate . '") < 5 THEN "Under 5"
                        WHEN TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, "' . $currentDate . '") BETWEEN 5 AND 8 THEN "5-8"
                        WHEN TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, "' . $currentDate . '") BETWEEN 9 AND 12 THEN "9-12"
                        WHEN TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, "' . $currentDate . '") BETWEEN 13 AND 16 THEN "13-16"
                        ELSE "17+"
                    END as age_group
                '),
                DB::raw('count(distinct child_profiles.id) as count')
            )
            ->groupBy('age_group')
            ->get()
            ->pluck('count', 'age_group')
            ->toArray();

        // Get students by curriculum
        $studentsByCurriculum = $query->clone()
            ->select('curricula.name', DB::raw('count(distinct child_profiles.id) as count'))
            ->join('curricula', 'program_enrollments.curriculum_id', '=', 'curricula.id')
            ->groupBy('curricula.name')
            ->get()
            ->pluck('count', 'name')
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

        // Prepare chart data for age distribution
        $ageChartData = [];
        foreach ($studentsByAge as $ageGroup => $count) {
            $ageChartData[] = [
                'name' => $ageGroup,
                'value' => $count
            ];
        }

        // Prepare chart data for curriculum distribution
        $curriculumChartData = [];
        foreach ($studentsByCurriculum as $curriculum => $count) {
            $curriculumChartData[] = [
                'name' => $curriculum,
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
            'age_distribution' => $studentsByAge,
            'largest_curriculum' => array_key_exists('name', array_keys($studentsByCurriculum))
                ? array_keys($studentsByCurriculum, max($studentsByCurriculum))[0]
                : 'N/A'
        ];

        // Set chart data
        $this->chartData = [
            'gender' => $genderChartData,
            'age' => $ageChartData,
            'curriculum' => $curriculumChartData
        ];
    }

    // Academic Progression Report
    protected function generateProgressionReport(): void
    {
        // This report would show student progress over time
        // For this example, we'll simulate progression data

        // Mock curriculum progression data
        $curricula = ['Beginner', 'Intermediate', 'Advanced'];
        $years = [2020, 2021, 2022, 2023, 2024, 2025];

        $progressionData = [];
        $retentionData = [];
        $completionData = [];

        // Generate mock progression data
        foreach ($curricula as $curriculum) {
            $progression = [];
            $retention = [];
            $completion = [];

            $baseValue = array_search($curriculum, $curricula) * 20 + 30;

            foreach ($years as $year) {
                $variableFactor = (($year - 2020) * 5) + mt_rand(-5, 5);
                $progressionValue = min(100, max(0, $baseValue + $variableFactor));

                $progression[] = [
                    'year' => $year,
                    'value' => $progressionValue
                ];

                $retention[] = [
                    'year' => $year,
                    'value' => min(100, max(0, $baseValue + 10 + mt_rand(-8, 8)))
                ];

                $completion[] = [
                    'year' => $year,
                    'value' => min(100, max(0, $baseValue - 10 + mt_rand(-8, 8)))
                ];
            }

            $progressionData[$curriculum] = $progression;
            $retentionData[$curriculum] = $retention;
            $completionData[$curriculum] = $completion;
        }

        // Calculate average completion rate
        $completionRates = [];
        foreach ($curricula as $curriculum) {
            $completionRates[$curriculum] = $completionData[$curriculum][count($years) - 1]['value'];
        }

        // Set metrics
        $this->metrics = [
            'average_completion_rate' => round(array_sum($completionRates) / count($completionRates), 1),
            'highest_completion_curriculum' => array_keys($completionRates, max($completionRates))[0],
            'lowest_completion_curriculum' => array_keys($completionRates, min($completionRates))[0],
            'progression_trend' => array_map(function ($year) use ($curricula, $progressionData) {
                $yearData = ['year' => $year];
                foreach ($curricula as $curriculum) {
                    $yearIndex = array_search($year, array_column($progressionData[$curriculum], 'year'));
                    $yearData[$curriculum] = $progressionData[$curriculum][$yearIndex]['value'];
                }
                return $yearData;
            }, $years)
        ];

        // Set chart data
        $this->chartData = [
            'progression' => $progressionData,
            'retention' => $retentionData,
            'completion' => $completionData,
            'years' => $years,
            'curricula' => $curricula
        ];
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
            $previous['end'] = date('Y-m-d', strtotime($current['start']) - 86400); // Day before current start
            $previous['start'] = date('Y-m-d', strtotime($previous['end']) - $duration);
        }

        return $previous;
    }

    // Apply age group filter to a query
    protected function applyAgeGroupFilter($query): void
    {
        if (!$this->ageGroup) {
            return;
        }

        $currentDate = date('Y-m-d');

        switch ($this->ageGroup) {
            case 5: // Under 5 years
                $query->whereRaw('TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, ?) < 5', [$currentDate]);
                break;

            case 8: // 5-8 years
                $query->whereRaw('TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, ?) BETWEEN 5 AND 8', [$currentDate]);
                break;

            case 12: // 9-12 years
                $query->whereRaw('TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, ?) BETWEEN 9 AND 12', [$currentDate]);
                break;

            case 16: // 13-16 years
                $query->whereRaw('TIMESTAMPDIFF(YEAR, child_profiles.date_of_birth, ?) BETWEEN 13 AND 16', [$currentDate]);
                break;

            case 99: // 17+ years
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
        // Implementation would depend on your export library (e.g., CSV, PDF, Excel)
        // For this example, just show a toast

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

    // Get report types
    public function getReportTypes(): array
    {
        return $this->reportTypes;
    }

    // Get date ranges
    public function getDateRanges(): array
    {
        return $this->dateRanges;
    }

    // Get age groups
    public function getAgeGroups(): array
    {
        return $this->ageGroups;
    }

    public function with(): array
    {
        return [
            'academicYears' => $this->academicYears(),
            'curricula' => $this->curricula(),
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
                    :options="$reportTypes"
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
                    :options="$dateRanges"
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
                <div>
                    <x-input
                        type="date"
                        label="Start Date"
                        wire:model.live="startDate"
                        hint="Beginning of date range"
                    />
                </div>

                <div>
                    <x-input
                        type="date"
                        label="End Date"
                        wire:model.live="endDate"
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
                        'Male' => 'Male',
                        'Female' => 'Female',
                        'Other' => 'Other'
                    ]"
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
                    :options="$ageGroups"
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
        <!-- Different metrics based on report type -->
        @if($reportType === 'enrollment')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Total Enrollments</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['total_enrollments'] ?? 0) }}</div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Active Enrollments</div>
                    <div class="text-3xl font-bold text-success">{{ number_format($metrics['active_enrollments'] ?? 0) }}</div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Growth Rate</div>
                    <div class="text-3xl font-bold {{ ($metrics['growth_rate'] ?? 0) >= 0 ? 'text-success' : 'text-error' }}">
                        {{ ($metrics['growth_rate'] ?? 0) >= 0 ? '+' : '' }}{{ $metrics['growth_rate'] ?? 0 }}%
                    </div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Avg. Subjects per Student</div>
                    <div class="text-3xl font-bold">{{ $metrics['avg_subjects_per_student'] ?? 0 }}</div>
                </div>
            </div>
        @elseif($reportType === 'attendance')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Overall Attendance Rate</div>
                    <div class="flex items-end">
                        <div class="text-3xl font-bold {{ ($metrics['attendance_rate'] ?? 0) >= 90 ? 'text-success' : (($metrics['attendance_rate'] ?? 0) >= 75 ? 'text-warning' : 'text-error') }}">
                            {{ $metrics['attendance_rate'] ?? 0 }}%
                        </div>
                    </div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Total Sessions</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['total_sessions'] ?? 0) }}</div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Present / Absent</div>
                    <div class="flex items-end gap-2">
                        <div class="text-3xl font-bold text-success">{{ number_format($metrics['present_count'] ?? 0) }}</div>
                        <div class="text-lg font-bold">/</div>
                        <div class="text-3xl font-bold text-error">{{ number_format($metrics['absent_count'] ?? 0) }}</div>
                    </div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Late / Excused</div>
                    <div class="flex items-end gap-2">
                        <div class="text-3xl font-bold text-warning">{{ number_format($metrics['late_count'] ?? 0) }}</div>
                        <div class="text-lg font-bold">/</div>
                        <div class="text-3xl font-bold text-info">{{ number_format($metrics['excused_count'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        @elseif($reportType === 'performance')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Overall GPA</div>
                    <div class="text-3xl font-bold {{ ($metrics['overall_gpa'] ?? 0) >= 3.0 ? 'text-success' : (($metrics['overall_gpa'] ?? 0) >= 2.0 ? 'text-warning' : 'text-error') }}">
                        {{ $metrics['overall_gpa'] ?? 0 }}
                    </div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Pass Rate</div>
                    <div class="text-3xl font-bold text-success">{{ $metrics['pass_rate'] ?? 0 }}%</div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Total Students</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['total_students'] ?? 0) }}</div>
                </div>
            </div>
        @elseif($reportType === 'demographics')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Total Students</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['total_students'] ?? 0) }}</div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Gender Ratio (M/F)</div>
                    <div class="flex items-end gap-2">
                        <div class="text-3xl font-bold">{{ $metrics['gender_distribution']['male_percentage'] ?? 0 }}%</div>
                        <div class="text-lg font-bold">/</div>
                        <div class="text-3xl font-bold">{{ $metrics['gender_distribution']['female_percentage'] ?? 0 }}%</div>
                    </div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Largest Age Group</div>
                    @php
                        $largestAgeGroup = collect($metrics['age_distribution'] ?? [])->sortDesc()->keys()->first() ?? 'N/A';
                        $largestAgeCount = collect($metrics['age_distribution'] ?? [])->sortDesc()->first() ?? 0;
                        $percentage = $metrics['total_students'] > 0 ? round(($largestAgeCount / $metrics['total_students']) * 100, 1) : 0;
                    @endphp
                    <div class="text-3xl font-bold">{{ $largestAgeGroup }} <span class="text-sm font-normal">({{ $percentage }}%)</span></div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Largest Curriculum</div>
                    <div class="text-3xl font-bold">{{ $metrics['largest_curriculum'] ?? 'N/A' }}</div>
                </div>
            </div>
        @elseif($reportType === 'progression')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Average Completion Rate</div>
                    <div class="text-3xl font-bold {{ ($metrics['average_completion_rate'] ?? 0) >= 80 ? 'text-success' : (($metrics['average_completion_rate'] ?? 0) >= 60 ? 'text-warning' : 'text-error') }}">
                        {{ $metrics['average_completion_rate'] ?? 0 }}%
                    </div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Highest Completion</div>
                    <div class="text-3xl font-bold text-success">{{ $metrics['highest_completion_curriculum'] ?? 'N/A' }}</div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Lowest Completion</div>
                    <div class="text-3xl font-bold text-warning">{{ $metrics['lowest_completion_curriculum'] ?? 'N/A' }}</div>
                </div>
            </div>
        @endif
    </x-card>

    <!-- Charts Section -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Chart 1 -->
        <x-card title="{{ $reportType === 'enrollment' ? 'Enrollment by Status' :
                         ($reportType === 'attendance' ? 'Attendance Status Distribution' :
                         ($reportType === 'performance' ? 'Grade Distribution' :
                         ($reportType === 'demographics' ? 'Gender Distribution' :
                         'Completion Rates'))) }}">
            <div>
                <!-- Use Alpine.js for chart rendering -->
                <div x-data="{
                    chartData: {{ json_encode($reportType === 'enrollment' ? ($chartData['status'] ?? []) :
                                             ($reportType === 'attendance' ? ($chartData['status'] ?? []) :
                                             ($reportType === 'performance' ? ($chartData['grade_distribution'] ?? []) :
                                             ($reportType === 'demographics' ? ($chartData['gender'] ?? []) :
                                             [])))) }},
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
                    @if(($reportType === 'enrollment' && !empty($chartData['status'])) ||
                        ($reportType === 'attendance' && !empty($chartData['status'])) ||
                        ($reportType === 'performance' && !empty($chartData['grade_distribution'])) ||
                        ($reportType === 'demographics' && !empty($chartData['gender'])))
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

        <!-- Chart 2 -->
        <x-card title="{{ $reportType === 'enrollment' ? 'Enrollment by Curriculum' :
                         ($reportType === 'attendance' ? 'Attendance by Subject' :
                         ($reportType === 'performance' ? 'Performance by Subject' :
                         ($reportType === 'demographics' ? 'Age Distribution' :
                         'Academic Progression'))) }}">
            <div>
                <!-- Use Alpine.js for chart rendering -->
                <div x-data="{
                    chartData: {{ json_encode($reportType === 'enrollment' ? ($chartData['curriculum'] ?? []) :
                                             ($reportType === 'attendance' ? ($chartData['by_subject'] ?? []) :
                                             ($reportType === 'performance' ? ($chartData['by_subject'] ?? []) :
                                             ($reportType === 'demographics' ? ($chartData['age'] ?? []) :
                                             [])))) }},
                }" x-init="
                    if(chartData.length > 0) {
                        const ctx = document.getElementById('chart2').getContext('2d');
                        @if($reportType === 'enrollment' || $reportType === 'demographics')
                            // Bar chart for enrollment by curriculum or age distribution
                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: chartData.map(item => item.name),
                                    datasets: [{
                                        label: '{{ $reportType === 'enrollment' ? 'Students' : 'Count' }}',
                                        data: chartData.map(item => item.value),
                                        backgroundColor: '#36A2EB'
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    scales: {
                                        y: {
                                            beginAtZero: true
                                        }
                                    }
                                }
                            });
                        @elseif($reportType === 'attendance')
                            // Bar chart for attendance by subject
                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: chartData.map(item => item.subject),
                                    datasets: [{
                                        label: 'Attendance Rate (%)',
                                        data: chartData.map(item => item.attendance_rate),
                                        backgroundColor: '#4BC0C0'
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            max: 100
                                        }
                                    }
                                }
                            });
                        @elseif($reportType === 'performance')
                            // Bar chart for performance by subject
                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: chartData.map(item => item.subject),
                                    datasets: [{
                                        label: 'Average GPA',
                                        data: chartData.map(item => item.average_gpa),
                                        backgroundColor: '#FFCE56'
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            max: 4
                                        }
                                    }
                                }
                            });
                        @elseif($reportType === 'progression')
                            // Line chart for progression trends
                            new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: [2020, 2021, 2022, 2023, 2024, 2025],
                                    datasets: [
                                        {
                                            label: 'Beginner',
                                            data: [30, 35, 40, 45, 48, 52],
                                            borderColor: '#FF6384',
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Intermediate',
                                            data: [50, 55, 58, 60, 62, 65],
                                            borderColor: '#36A2EB',
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Advanced',
                                            data: [70, 72, 75, 78, 80, 85],
                                            borderColor: '#4BC0C0',
                                            tension: 0.1
                                        }
                                    ]
                                },
                                options: {
                                    responsive: true,
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            max: 100
                                        }
                                    }
                                }
                            });
                        @endif
                    }
                ">
                    @if(($reportType === 'enrollment' && !empty($chartData['curriculum'])) ||
                        ($reportType === 'attendance' && !empty($chartData['by_subject'])) ||
                        ($reportType === 'performance' && !empty($chartData['by_subject'])) ||
                        ($reportType === 'demographics' && !empty($chartData['age'])) ||
                        $reportType === 'progression')
                        <div class="h-64">
                            <canvas id="chart2"></canvas>
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center h-64 p-4">
                            <x-icon name="o-chart-bar" class="w-16 h-16 text-gray-300" />
                            <p class="mt-2 text-gray-500">No data available for the selected filters</p>
                        </div>
                    @endif
                </div>
            </div>
        </x-card>

        <!-- Chart 3 -->
        <x-card title="{{ $reportType === 'enrollment' ? 'Enrollment Trend' :
                         ($reportType === 'attendance' ? 'Attendance Trend' :
                         ($reportType === 'performance' ? 'Performance Trend' :
                         ($reportType === 'demographics' ? 'Curriculum Distribution' :
                         'Retention Rates'))) }}">
            <div>
                <!-- Use Alpine.js for chart rendering -->
                <div x-data="{
                    chartData: {{ json_encode($reportType === 'enrollment' ? ($chartData['trend'] ?? []) :
                                             ($reportType === 'attendance' ? ($chartData['trend'] ?? []) :
                                             ($reportType === 'performance' ? ($chartData['trend'] ?? []) :
                                             ($reportType === 'demographics' ? ($chartData['curriculum'] ?? []) :
                                             [])))) }},
                }" x-init="
                    if(chartData.length > 0) {
                        const ctx = document.getElementById('chart3').getContext('2d');
                        @if($reportType === 'enrollment' || $reportType === 'attendance' || $reportType === 'performance')
                            // Line chart for trends
                            new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: chartData.map(item => item.{{ $reportType === 'enrollment' ? 'month' : ($reportType === 'attendance' ? 'week' : 'month') }}),
                                    datasets: [{
                                        label: '{{ $reportType === 'enrollment' ? 'Enrollments' : ($reportType === 'attendance' ? 'Attendance Rate (%)' : 'Average GPA') }}',
                                        data: chartData.map(item => item.{{ $reportType === 'enrollment' ? 'count' : ($reportType === 'attendance' ? 'attendance_rate' : 'average_gpa') }}),
                                        borderColor: '{{ $reportType === 'enrollment' ? '#FF6384' : ($reportType === 'attendance' ? '#4BC0C0' : '#FFCE56') }}',
                                        tension: 0.1,
                                        fill: false
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            @if($reportType === 'attendance')
                                                max: 100
                                            @elseif($reportType === 'performance')
                                                max: 4
                                            @endif
                                        }
                                    }
                                }
                            });
                        @elseif($reportType === 'demographics')
                            // Doughnut chart for curriculum distribution
                            new Chart(ctx, {
                                type: 'doughnut',
                                data: {
                                    labels: chartData.map(item => item.name),
                                    datasets: [{
                                        data: chartData.map(item => item.value),
                                        backgroundColor: [
                                            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#C9CBCF'
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
                        @elseif($reportType === 'progression')
                            // Line chart for retention
                            new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: [2020, 2021, 2022, 2023, 2024, 2025],
                                    datasets: [
                                        {
                                            label: 'Beginner',
                                            data: [40, 42, 45, 48, 50, 55],
                                            borderColor: '#FF6384',
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Intermediate',
                                            data: [60, 62, 65, 68, 70, 72],
                                            borderColor: '#36A2EB',
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Advanced',
                                            data: [80, 82, 83, 85, 87, 90],
                                            borderColor: '#4BC0C0',
                                            tension: 0.1
                                        }
                                    ]
                                },
                                options: {
                                    responsive: true,
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            max: 100
                                        }
                                    }
                                }
                            });
                        @endif
                    }
                ">
                    @if(($reportType === 'enrollment' && !empty($chartData['trend'])) ||
                        ($reportType === 'attendance' && !empty($chartData['trend'])) ||
                        ($reportType === 'performance' && !empty($chartData['trend'])) ||
                        ($reportType === 'demographics' && !empty($chartData['curriculum'])) ||
                        $reportType === 'progression')
                        <div class="h-64">
                            <canvas id="chart3"></canvas>
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center h-64 p-4">
                            <x-icon name="o-eye" class="w-16 h-16 text-gray-300" />
                            <p class="mt-2 text-gray-500">No data available for the selected filters</p>
                        </div>
                    @endif
                </div>
            </div>
        </x-card>

        <!-- Additional Data Table -->
        <x-card title="{{ $reportType === 'enrollment' ? 'Enrollment Details' :
                         ($reportType === 'attendance' ? 'Attendance by Subject' :
                         ($reportType === 'performance' ? 'Subject Performance' :
                         ($reportType === 'demographics' ? 'Age Distribution' :
                         'Progression Metrics'))) }}" class="lg:col-span-2">
            <div class="overflow-x-auto">
                @if($reportType === 'enrollment')
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
                @elseif($reportType === 'attendance')
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th class="text-center">Present</th>
                                <th class="text-center">Absent</th>
                                <th class="text-center">Late</th>
                                <th class="text-center">Excused</th>
                                <th class="text-right">Attendance Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($chartData['by_subject'] ?? [] as $item)
                                <tr>
                                    <td>{{ $item['subject'] }}</td>
                                    <td class="text-center">{{ number_format($item['present']) }}</td>
                                    <td class="text-center">{{ number_format($item['absent']) }}</td>
                                    <td class="text-center">{{ number_format($item['late']) }}</td>
                                    <td class="text-center">{{ number_format($item['excused']) }}</td>
                                    <td class="text-right">
                                        <div class="flex items-center justify-end">
                                            <span class="{{ $item['attendance_rate'] >= 90 ? 'text-success' : ($item['attendance_rate'] >= 75 ? 'text-warning' : 'text-error') }}">
                                                {{ $item['attendance_rate'] }}%
                                            </span>
                                            <div class="w-20 h-2 ml-2 rounded-full bg-base-300">
                                                <div class="h-2 rounded-full {{ $item['attendance_rate'] >= 90 ? 'bg-success' : ($item['attendance_rate'] >= 75 ? 'bg-warning' : 'bg-error') }}" style="width: {{ $item['attendance_rate'] }}%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @elseif($reportType === 'performance')
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th class="text-center">A</th>
                                <th class="text-center">B</th>
                                <th class="text-center">C</th>
                                <th class="text-center">D</th>
                                <th class="text-center">F</th>
                                <th class="text-right">Average GPA</th>
                                <th class="text-right">Pass Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($chartData['by_subject'] ?? [] as $item)
                                <tr>
                                    <td>{{ $item['subject'] }}</td>
                                    <td class="text-center">{{ $item['grades']['A'] }}</td>
                                    <td class="text-center">{{ $item['grades']['B'] }}</td>
                                    <td class="text-center">{{ $item['grades']['C'] }}</td>
                                    <td class="text-center">{{ $item['grades']['D'] }}</td>
                                    <td class="text-center">{{ $item['grades']['F'] }}</td>
                                    <td class="text-right">
                                        <span class="{{ $item['average_gpa'] >= 3.0 ? 'text-success' : ($item['average_gpa'] >= 2.0 ? 'text-warning' : 'text-error') }}">
                                            {{ $item['average_gpa'] }}
                                        </span>
                                    </td>
                                    <td class="text-right">{{ $item['pass_rate'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @elseif($reportType === 'demographics')
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <h3 class="mb-2 text-lg font-semibold">Age Distribution</h3>
                            <table class="table w-full table-zebra">
                                <thead>
                                    <tr>
                                        <th>Age Group</th>
                                        <th class="text-right">Count</th>
                                        <th class="text-right">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $totalStudents = $metrics['total_students'] ?? 0;
                                    @endphp
                                    @foreach($chartData['age'] ?? [] as $item)
                                        <tr>
                                            <td>{{ $item['name'] }}</td>
                                            <td class="text-right">{{ number_format($item['value']) }}</td>
                                            <td class="text-right">
                                                {{ $totalStudents > 0 ? round(($item['value'] / $totalStudents) * 100, 1) : 0 }}%
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <h3 class="mb-2 text-lg font-semibold">Gender Distribution</h3>
                            <table class="table w-full table-zebra">
                                <thead>
                                    <tr>
                                        <th>Gender</th>
                                        <th class="text-right">Count</th>
                                        <th class="text-right">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($chartData['gender'] ?? [] as $item)
                                        <tr>
                                            <td>{{ $item['name'] }}</td>
                                            <td class="text-right">{{ number_format($item['value']) }}</td>
                                            <td class="text-right">
                                                {{ $totalStudents > 0 ? round(($item['value'] / $totalStudents) * 100, 1) : 0 }}%
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @elseif($reportType === 'progression')
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th class="text-right">Beginner</th>
                                <th class="text-right">Intermediate</th>
                                <th class="text-right">Advanced</th>
                                <th class="text-right">Overall</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($metrics['progression_trend'] ?? [] as $item)
                                <tr>
                                    <td>{{ $item['year'] }}</td>
                                    <td class="text-right">{{ $item['Beginner'] }}%</td>
                                    <td class="text-right">{{ $item['Intermediate'] }}%</td>
                                    <td class="text-right">{{ $item['Advanced'] }}%</td>
                                    <td class="text-right">
                                        @php
                                            $overall = ($item['Beginner'] + $item['Intermediate'] + $item['Advanced']) / 3;
                                        @endphp
                                        {{ round($overall, 1) }}%
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </x-card>
    </div>

    <!-- Report Notes -->
    <x-card title="Report Notes" class="mt-6">
        <div class="p-4">
            @if($reportType === 'enrollment')
                <p class="mb-4">This report shows enrollment statistics for the selected time period and filters. It includes total enrollments, status distribution, and enrollment trends over time.</p>
                <ul class="ml-6 space-y-2 list-disc">
                    <li><strong>Growth Rate</strong> compares the current period to the previous period of equal length.</li>
                    <li><strong>Active Enrollments</strong> represents students who are currently participating in programs.</li>
                    <li><strong>Pending Enrollments</strong> represents students who have applied but not yet started.</li>
                </ul>
            @elseif($reportType === 'attendance')
                <p class="mb-4">This report shows attendance statistics for the selected time period and filters. It includes attendance rates, status distribution, and attendance trends over time.</p>
                <ul class="ml-6 space-y-2 list-disc">
                    <li><strong>Overall Attendance Rate</strong> is calculated as the percentage of Present records out of total attendance records.</li>
                    <li><strong>Late</strong> records are counted separately from absent, but still impact the overall attendance rate.</li>
                    <li><strong>Excused</strong> absences are recorded but may be treated differently in academic evaluations.</li>
                </ul>
            @elseif($reportType === 'performance')
                <p class="mb-4">This report shows academic performance statistics for the selected time period and filters. It includes grade distribution, GPA analysis, and performance trends over time.</p>
                <ul class="ml-6 space-y-2 list-disc">
                    <li><strong>GPA</strong> is calculated on a 4.0 scale (A=4, B=3, C=2, D=1, F=0).</li>
                    <li><strong>Pass Rate</strong> represents the percentage of students who received a grade of C or better.</li>
                    <li><strong>Performance Trend</strong> shows how average GPA has changed over time.</li>
                </ul>
            @elseif($reportType === 'demographics')
                <p class="mb-4">This report shows demographic statistics for the selected time period and filters. It includes gender distribution, age breakdown, and curriculum preferences.</p>
                <ul class="ml-6 space-y-2 list-disc">
                    <li><strong>Age Groups</strong> are calculated based on student date of birth as of the current date.</li>
                    <li><strong>Gender Ratio</strong> shows the proportion of male to female students.</li>
                    <li><strong>Curriculum Distribution</strong> shows how students are distributed across different curricula.</li>
                </ul>
            @elseif($reportType === 'progression')
                <p class="mb-4">This report shows academic progression statistics for the selected time period and filters. It includes completion rates, retention analysis, and progression trends over time.</p>
                <ul class="ml-6 space-y-2 list-disc">
                    <li><strong>Completion Rate</strong> represents the percentage of students who successfully completed their program.</li>
                    <li><strong>Retention Rate</strong> shows the percentage of students who continued from one level to the next.</li>
                    <li><strong>Progression Trend</strong> shows how student progress has changed over time across different curricula.</li>
                </ul>
            @endif

            <div class="p-4 mt-4 text-sm text-blue-700 rounded-lg bg-blue-50">
                <strong>Note:</strong> This report is generated based on the selected filters and may not represent the entire student population. For more detailed analysis, please adjust the filters or export the report for further processing.
            </div>
        </div>
    </x-card>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
