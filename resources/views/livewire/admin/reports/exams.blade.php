<?php

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Subject;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\ChildProfile;
use App\Models\ProgramEnrollment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Exam Report')] class extends Component {
    use Toast;

    // Filter properties
    public string $selectedAcademicYear = '';
    public string $selectedCurriculum = '';
    public string $selectedSubject = '';
    public string $selectedExam = '';
    public string $selectedStudent = '';
    public string $gradeFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $reportFormat = 'table';
    public string $reportType = 'overview'; // overview, exam, student

    public array $gradeRanges = [
        'A' => 'A (90-100%)',
        'B' => 'B (80-89%)',
        'C' => 'C (70-79%)',
        'D' => 'D (60-69%)',
        'F' => 'F (Below 60%)'
    ];

    public array $reportTypes = [
        'overview' => 'Overview Report',
        'exam' => 'Exam Analysis',
        'student' => 'Student Performance'
    ];

    public function mount(): void
    {
        $this->selectedAcademicYear = AcademicYear::where('is_current', true)->first()?->id ?? '';
        $this->dateFrom = now()->startOfYear()->format('Y-m-d');
        $this->dateTo = now()->endOfYear()->format('Y-m-d');
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

    public function getExamsProperty(): Collection
    {
        $query = Exam::with(['subject', 'academicYear'])->orderBy('exam_date', 'desc');

        if ($this->selectedAcademicYear) {
            $query->where('academic_year_id', $this->selectedAcademicYear);
        }

        if ($this->selectedSubject) {
            $query->where('subject_id', $this->selectedSubject);
        }

        if ($this->selectedCurriculum) {
            $query->whereHas('subject', function($q) {
                $q->where('curriculum_id', $this->selectedCurriculum);
            });
        }

        return $query->get();
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

    public function getExamResultsProperty(): Collection
    {
        $query = ExamResult::with([
            'exam.subject.curriculum',
            'exam.academicYear',
            'childProfile.programEnrollments.academicYear',
            'childProfile.programEnrollments.curriculum'
        ]);

        // Filter by academic year
        if ($this->selectedAcademicYear) {
            $query->whereHas('exam', function($q) {
                $q->where('academic_year_id', $this->selectedAcademicYear);
            });
        }

        // Filter by curriculum
        if ($this->selectedCurriculum) {
            $query->whereHas('exam.subject', function($q) {
                $q->where('curriculum_id', $this->selectedCurriculum);
            });
        }

        // Filter by subject
        if ($this->selectedSubject) {
            $query->whereHas('exam', function($q) {
                $q->where('subject_id', $this->selectedSubject);
            });
        }

        // Filter by specific exam
        if ($this->selectedExam) {
            $query->where('exam_id', $this->selectedExam);
        }

        // Filter by specific student
        if ($this->selectedStudent) {
            $query->where('child_profile_id', $this->selectedStudent);
        }

        // Filter by grade range
        if ($this->gradeFilter) {
            switch ($this->gradeFilter) {
                case 'A':
                    $query->where('score', '>=', 90);
                    break;
                case 'B':
                    $query->whereBetween('score', [80, 89.99]);
                    break;
                case 'C':
                    $query->whereBetween('score', [70, 79.99]);
                    break;
                case 'D':
                    $query->whereBetween('score', [60, 69.99]);
                    break;
                case 'F':
                    $query->where('score', '<', 60);
                    break;
            }
        }

        // Filter by date range
        if ($this->dateFrom && $this->dateTo) {
            $query->whereHas('exam', function($q) {
                $q->whereBetween('exam_date', [$this->dateFrom, $this->dateTo]);
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getStatisticsProperty(): array
    {
        $results = $this->examResults;

        $stats = [
            'total_results' => $results->count(),
            'total_exams' => $results->pluck('exam_id')->unique()->count(),
            'total_students' => $results->pluck('child_profile_id')->unique()->count(),
            'average_score' => $results->avg('score') ?? 0,
            'highest_score' => $results->max('score') ?? 0,
            'lowest_score' => $results->min('score') ?? 0,
            'grade_distribution' => [
                'A' => $results->where('score', '>=', 90)->count(),
                'B' => $results->whereBetween('score', [80, 89.99])->count(),
                'C' => $results->whereBetween('score', [70, 79.99])->count(),
                'D' => $results->whereBetween('score', [60, 69.99])->count(),
                'F' => $results->where('score', '<', 60)->count(),
            ],
            'subject_performance' => [],
            'student_performance' => [],
            'exam_difficulty' => [],
            'pass_rate' => 0
        ];

        // Calculate pass rate (assuming 60% is passing)
        $passingResults = $results->where('score', '>=', 60)->count();
        $stats['pass_rate'] = $stats['total_results'] > 0
            ? round(($passingResults / $stats['total_results']) * 100, 2)
            : 0;

        // Subject performance analysis
        $subjectGroups = $results->groupBy(function($result) {
            return $result->exam->subject->name ?? 'Unknown';
        });

        foreach ($subjectGroups as $subjectName => $subjectResults) {
            $stats['subject_performance'][$subjectName] = [
                'total_results' => $subjectResults->count(),
                'average_score' => round($subjectResults->avg('score'), 2),
                'highest_score' => $subjectResults->max('score'),
                'lowest_score' => $subjectResults->min('score'),
                'pass_rate' => $subjectResults->count() > 0
                    ? round(($subjectResults->where('score', '>=', 60)->count() / $subjectResults->count()) * 100, 2)
                    : 0
            ];
        }

        // Student performance analysis
        $studentGroups = $results->groupBy('child_profile_id');
        foreach ($studentGroups as $studentId => $studentResults) {
            $studentName = $studentResults->first()->childProfile->full_name ?? 'Unknown';
            $stats['student_performance'][] = [
                'student_id' => $studentResults->first()->childProfile->id ?? null,
                'student_name' => $studentName,
                'total_exams' => $studentResults->count(),
                'average_score' => round($studentResults->avg('score'), 2),
                'highest_score' => $studentResults->max('score'),
                'lowest_score' => $studentResults->min('score'),
                'improvement_trend' => $this->calculateImprovementTrend($studentResults)
            ];
        }

        // Sort students by average score
        usort($stats['student_performance'], function($a, $b) {
            return $b['average_score'] <=> $a['average_score'];
        });

        // Exam difficulty analysis
        $examGroups = $results->groupBy('exam_id');
        foreach ($examGroups as $examId => $examResults) {
            $exam = $examResults->first()->exam;
            $averageScore = $examResults->avg('score');
            $difficulty = 'Medium';

            if ($averageScore >= 80) {
                $difficulty = 'Easy';
            } elseif ($averageScore < 60) {
                $difficulty = 'Hard';
            }

            $stats['exam_difficulty'][] = [
                'exam_id' => $exam->id,
                'exam_title' => $exam->title ?? 'Unknown Exam',
                'exam_date' => $exam->exam_date ?? null,
                'subject' => $exam->subject->name ?? 'Unknown',
                'total_students' => $examResults->count(),
                'average_score' => round($averageScore, 2),
                'difficulty' => $difficulty,
                'pass_rate' => $examResults->count() > 0
                    ? round(($examResults->where('score', '>=', 60)->count() / $examResults->count()) * 100, 2)
                    : 0
            ];
        }

        return $stats;
    }

    public function getExamAnalysisProperty(): array
    {
        if (!$this->selectedExam) {
            return [];
        }

        $exam = Exam::with(['subject', 'academicYear'])->find($this->selectedExam);
        $results = $this->examResults->where('exam_id', $this->selectedExam);

        if (!$exam || $results->isEmpty()) {
            return [];
        }

        $analysis = [
            'exam_info' => $exam,
            'total_participants' => $results->count(),
            'average_score' => round($results->avg('score'), 2),
            'highest_score' => $results->max('score'),
            'lowest_score' => $results->min('score'),
            'pass_rate' => round(($results->where('score', '>=', 60)->count() / $results->count()) * 100, 2),
            'grade_breakdown' => [
                'A' => $results->where('score', '>=', 90)->count(),
                'B' => $results->whereBetween('score', [80, 89.99])->count(),
                'C' => $results->whereBetween('score', [70, 79.99])->count(),
                'D' => $results->whereBetween('score', [60, 69.99])->count(),
                'F' => $results->where('score', '<', 60)->count(),
            ],
            'top_performers' => $results->sortByDesc('score')->take(10)->values(),
            'score_distribution' => []
        ];

        // Score distribution in ranges
        $ranges = [
            '90-100' => $results->whereBetween('score', [90, 100])->count(),
            '80-89' => $results->whereBetween('score', [80, 89])->count(),
            '70-79' => $results->whereBetween('score', [70, 79])->count(),
            '60-69' => $results->whereBetween('score', [60, 69])->count(),
            '50-59' => $results->whereBetween('score', [50, 59])->count(),
            '0-49' => $results->where('score', '<', 50)->count(),
        ];

        $analysis['score_distribution'] = $ranges;

        return $analysis;
    }

    public function getStudentAnalysisProperty(): array
    {
        if (!$this->selectedStudent) {
            return [];
        }

        $student = ChildProfile::with(['programEnrollments.academicYear', 'programEnrollments.curriculum'])->find($this->selectedStudent);
        $results = $this->examResults->filter(function($result) {
            return $result->programEnrollment->childProfile->id == $this->selectedStudent;
        });

        if (!$student || $results->isEmpty()) {
            return [];
        }

        $analysis = [
            'student_info' => $student,
            'total_exams_taken' => $results->count(),
            'average_score' => round($results->avg('score'), 2),
            'highest_score' => $results->max('score'),
            'lowest_score' => $results->min('score'),
            'improvement_trend' => $this->calculateImprovementTrend($results),
            'subject_performance' => [],
            'recent_exams' => $results->sortByDesc(function($result) {
                return $result->exam->exam_date;
            })->take(10)->values(),
            'grade_distribution' => [
                'A' => $results->where('score', '>=', 90)->count(),
                'B' => $results->whereBetween('score', [80, 89.99])->count(),
                'C' => $results->whereBetween('score', [70, 79.99])->count(),
                'D' => $results->whereBetween('score', [60, 69.99])->count(),
                'F' => $results->where('score', '<', 60)->count(),
            ]
        ];

        // Subject performance breakdown
        $subjectGroups = $results->groupBy(function($result) {
            return $result->exam->subject->name ?? 'Unknown';
        });

        foreach ($subjectGroups as $subjectName => $subjectResults) {
            $analysis['subject_performance'][$subjectName] = [
                'total_exams' => $subjectResults->count(),
                'average_score' => round($subjectResults->avg('score'), 2),
                'highest_score' => $subjectResults->max('score'),
                'lowest_score' => $subjectResults->min('score'),
                'improvement_trend' => $this->calculateImprovementTrend($subjectResults)
            ];
        }

        return $analysis;
    }

    private function calculateImprovementTrend($results): string
    {
        if ($results->count() < 2) {
            return 'Insufficient data';
        }

        $sortedResults = $results->sortBy(function($result) {
            return $result->exam->exam_date;
        });

        $firstScore = $sortedResults->first()->score;
        $lastScore = $sortedResults->last()->score;
        $difference = $lastScore - $firstScore;

        if ($difference > 5) {
            return 'Improving';
        } elseif ($difference < -5) {
            return 'Declining';
        } else {
            return 'Stable';
        }
    }

    private function getGrade($score): string
    {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }

    private function getGradeColor($grade): string
    {
        return match($grade) {
            'A' => 'success',
            'B' => 'info',
            'C' => 'warning',
            'D' => 'warning',
            'F' => 'error',
            default => 'ghost'
        };
    }

    public function exportReport(): void
    {
        $this->success('Exam report export functionality will be implemented soon.');
    }

    public function resetFilters(): void
    {
        $this->selectedAcademicYear = AcademicYear::where('is_current', true)->first()?->id ?? '';
        $this->selectedCurriculum = '';
        $this->selectedSubject = '';
        $this->selectedExam = '';
        $this->selectedStudent = '';
        $this->gradeFilter = '';
        $this->dateFrom = now()->startOfYear()->format('Y-m-d');
        $this->dateTo = now()->endOfYear()->format('Y-m-d');
        $this->reportType = 'overview';
        $this->success('Filters reset successfully.');
    }

    // Update handlers
    public function updatedReportType(): void
    {
        if ($this->reportType !== 'exam') {
            $this->selectedExam = '';
        }
        if ($this->reportType !== 'student') {
            $this->selectedStudent = '';
        }
    }

    public function updatedSelectedCurriculum(): void
    {
        $this->selectedSubject = '';
        $this->selectedExam = '';
        $this->selectedStudent = '';
    }

    public function with(): array
    {
        return [
            'examResults' => $this->examResults,
            'statistics' => $this->statistics,
            'examAnalysis' => $this->examAnalysis,
            'studentAnalysis' => $this->studentAnalysis,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Exam Report" separator>
        <x-slot:subtitle>
            {{ $reportTypes[$reportType] }}
        </x-slot:subtitle>

        <x-slot:middle>
            <div class="flex flex-col gap-2 space-y-2 sm:flex-row sm:items-center sm:space-x-4 sm:space-y-0">
                <x-badge
                    label="{{ $examResults->count() }} Results"
                    color="primary"
                    class="badge-sm sm:badge-lg"
                />
                <x-badge
                    label="{{ round($statistics['average_score'], 1) }}% Avg"
                    color="{{ $statistics['average_score'] >= 80 ? 'success' : ($statistics['average_score'] >= 60 ? 'warning' : 'error') }}"
                    class="badge-sm sm:badge-lg"
                />
                <x-badge
                    label="{{ $statistics['pass_rate'] }}% Pass"
                    color="{{ $statistics['pass_rate'] >= 80 ? 'success' : ($statistics['pass_rate'] >= 60 ? 'warning' : 'error') }}"
                    class="badge-sm sm:badge-lg"
                />
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <div class="flex flex-col gap-2 sm:flex-row sm:gap-2">
                <x-button
                    label="Export"
                    icon="o-arrow-down-tray"
                    wire:click="exportReport"
                    color="success"
                    class="btn-sm sm:btn-md"
                />
                <x-button
                    label="Reset"
                    icon="o-arrow-path"
                    wire:click="resetFilters"
                    class="btn-ghost btn-sm sm:btn-md"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Report Type Selection -->
    <x-card title="Report Configuration" class="mb-4 sm:mb-6">
        <div class="grid grid-cols-1 gap-3 sm:gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Report Type</label>
                <select wire:model.live="reportType" class="w-full select select-bordered select-sm sm:select-md">
                    @foreach($reportTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if($reportType === 'exam')
                <div class="sm:col-span-2 lg:col-span-1">
                    <label class="block mb-2 text-sm font-medium text-gray-700">Select Exam <span class="text-red-500">*</span></label>
                    <select wire:model.live="selectedExam" class="w-full select select-bordered select-sm sm:select-md">
                        <option value="">Choose an Exam</option>
                        @foreach($this->exams as $exam)
                            <option value="{{ $exam->id }}">
                                <span class="hidden sm:inline">{{ $exam->title }} - {{ $exam->subject->name ?? 'Unknown' }}</span>
                                <span class="sm:hidden">{{ Str::limit($exam->title, 20) }}</span>
                                ({{ $exam->exam_date?->format('M d, Y') ?? 'No date' }})
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if($reportType === 'student')
                <div class="sm:col-span-2 lg:col-span-1">
                    <label class="block mb-2 text-sm font-medium text-gray-700">Select Student <span class="text-red-500">*</span></label>
                    <select wire:model.live="selectedStudent" class="w-full select select-bordered select-sm sm:select-md">
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
    <x-card title="Report Filters" class="mb-4 sm:mb-6">
        <div class="grid grid-cols-1 gap-3 sm:gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Academic Year</label>
                <select wire:model.live="selectedAcademicYear" class="w-full select select-bordered select-sm sm:select-md">
                    <option value="">All Years</option>
                    @foreach($this->academicYears as $year)
                        <option value="{{ $year->id }}">{{ $year->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Curriculum</label>
                <select wire:model.live="selectedCurriculum" class="w-full select select-bordered select-sm sm:select-md">
                    <option value="">All Curricula</option>
                    @foreach($this->curricula as $curriculum)
                        <option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Subject</label>
                <select wire:model.live="selectedSubject" class="w-full select select-bordered select-sm sm:select-md">
                    <option value="">All Subjects</option>
                    @foreach($this->subjects as $subject)
                        <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                    @endforeach
                </select>
            </div>

            @if($reportType === 'overview')
                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-700">Specific Exam</label>
                    <select wire:model.live="selectedExam" class="w-full select select-bordered select-sm sm:select-md">
                        <option value="">All Exams</option>
                        @foreach($this->exams as $exam)
                            <option value="{{ $exam->id }}">
                                <span class="hidden sm:inline">{{ $exam->title }} ({{ $exam->exam_date?->format('M d, Y') }})</span>
                                <span class="sm:hidden">{{ Str::limit($exam->title, 15) }}</span>
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block mb-2 text-sm font-medium text-gray-700">Student</label>
                    <select wire:model.live="selectedStudent" class="w-full select select-bordered select-sm sm:select-md">
                        <option value="">All Students</option>
                        @foreach($this->students as $student)
                            <option value="{{ $student->id }}">{{ $student->full_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Grade Filter</label>
                <select wire:model.live="gradeFilter" class="w-full select select-bordered select-sm sm:select-md">
                    <option value="">All Grades</option>
                    @foreach($gradeRanges as $grade => $label)
                        <option value="{{ $grade }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 mt-4 sm:gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-input
                label="Date From"
                wire:model.live="dateFrom"
                type="date"
                class="input-sm sm:input-md"
            />
            <x-input
                label="Date To"
                wire:model.live="dateTo"
                type="date"
                class="input-sm sm:input-md"
            />
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Report Format</label>
                <select wire:model.live="reportFormat" class="w-full select select-bordered select-sm sm:select-md">
                    <option value="table">Table View</option>
                    <option value="summary">Summary View</option>
                    <option value="analysis">Analysis View</option>
                </select>
            </div>
        </div>
    </x-card>

    <!-- Statistics -->
    <div class="grid grid-cols-2 gap-2 mb-4 sm:gap-4 sm:mb-6 sm:grid-cols-3 lg:grid-cols-6">
        <x-card class="p-3 sm:p-4">
            <div class="flex flex-col items-center sm:flex-row sm:items-center">
                <div class="flex items-center justify-center w-8 h-8 mb-2 bg-blue-500 rounded-lg sm:w-12 sm:h-12 sm:mb-0">
                    <x-icon name="o-document-text" class="w-4 h-4 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="text-center sm:ml-4 sm:text-left">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Total Results</p>
                    <p class="text-lg font-bold text-gray-900 sm:text-2xl">{{ $statistics['total_results'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card class="p-3 sm:p-4">
            <div class="flex flex-col items-center sm:flex-row sm:items-center">
                <div class="flex items-center justify-center w-8 h-8 mb-2 bg-green-500 rounded-lg sm:w-12 sm:h-12 sm:mb-0">
                    <x-icon name="o-chart-bar" class="w-4 h-4 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="text-center sm:ml-4 sm:text-left">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Average Score</p>
                    <p class="text-lg font-bold text-gray-900 sm:text-2xl">{{ round($statistics['average_score'], 1) }}%</p>
                </div>
            </div>
        </x-card>

        <x-card class="p-3 sm:p-4">
            <div class="flex flex-col items-center sm:flex-row sm:items-center">
                <div class="flex items-center justify-center w-8 h-8 mb-2 bg-purple-500 rounded-lg sm:w-12 sm:h-12 sm:mb-0">
                    <x-icon name="o-star" class="w-4 h-4 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="text-center sm:ml-4 sm:text-left">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Highest Score</p>
                    <p class="text-lg font-bold text-gray-900 sm:text-2xl">{{ $statistics['highest_score'] }}%</p>
                </div>
            </div>
        </x-card>

        <x-card class="p-3 sm:p-4">
            <div class="flex flex-col items-center sm:flex-row sm:items-center">
                <div class="flex items-center justify-center w-8 h-8 mb-2 bg-red-500 rounded-lg sm:w-12 sm:h-12 sm:mb-0">
                    <x-icon name="o-arrow-trending-down" class="w-4 h-4 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="text-center sm:ml-4 sm:text-left">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Lowest Score</p>
                    <p class="text-lg font-bold text-gray-900 sm:text-2xl">{{ $statistics['lowest_score'] }}%</p>
                </div>
            </div>
        </x-card>

        <x-card class="p-3 sm:p-4">
            <div class="flex flex-col items-center sm:flex-row sm:items-center">
                <div class="flex items-center justify-center w-8 h-8 mb-2 bg-yellow-500 rounded-lg sm:w-12 sm:h-12 sm:mb-0">
                    <x-icon name="o-check-circle" class="w-4 h-4 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="text-center sm:ml-4 sm:text-left">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Pass Rate</p>
                    <p class="text-lg font-bold text-gray-900 sm:text-2xl">{{ $statistics['pass_rate'] }}%</p>
                </div>
            </div>
        </x-card>

        <x-card class="p-3 sm:p-4">
            <div class="flex flex-col items-center sm:flex-row sm:items-center">
                <div class="flex items-center justify-center w-8 h-8 mb-2 bg-indigo-500 rounded-lg sm:w-12 sm:h-12 sm:mb-0">
                    <x-icon name="o-users" class="w-4 h-4 text-white sm:w-6 sm:h-6" />
                </div>
                <div class="text-center sm:ml-4 sm:text-left">
                    <p class="text-xs font-medium text-gray-500 sm:text-sm">Total Students</p>
                    <p class="text-lg font-bold text-gray-900 sm:text-2xl">{{ $statistics['total_students'] }}</p>
                </div>
            </div>
        </x-card>
    </div>

    @if($reportType === 'exam' && $selectedExam && !empty($examAnalysis))
        <!-- Exam Analysis -->
        <x-card title="Exam Analysis: {{ $examAnalysis['exam_info']->title }}" class="mb-4 sm:mb-6">
            <div class="grid grid-cols-2 gap-3 mb-4 sm:gap-4 sm:mb-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="p-3 border rounded-lg sm:p-4">
                    <h3 class="text-sm font-semibold text-blue-600 sm:text-base">Total Participants</h3>
                    <p class="text-xl font-bold sm:text-2xl">{{ $examAnalysis['total_participants'] }}</p>
                </div>
                <div class="p-3 border rounded-lg sm:p-4">
                    <h3 class="text-sm font-semibold text-green-600 sm:text-base">Average Score</h3>
                    <p class="text-xl font-bold sm:text-2xl">{{ $examAnalysis['average_score'] }}%</p>
                </div>
                <div class="p-3 border rounded-lg sm:p-4">
                    <h3 class="text-sm font-semibold text-purple-600 sm:text-base">Pass Rate</h3>
                    <p class="text-xl font-bold sm:text-2xl">{{ $examAnalysis['pass_rate'] }}%</p>
                </div>
                <div class="p-3 border rounded-lg sm:p-4">
                    <h3 class="text-sm font-semibold text-yellow-600 sm:text-base">Exam Date</h3>
                    <p class="text-sm font-bold sm:text-lg">{{ $examAnalysis['exam_info']->exam_date?->format('M d, Y') ?? 'Not set' }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:gap-6 lg:grid-cols-2">
                <!-- Grade Breakdown -->
                <div>
                    <h3 class="mb-3 text-base font-semibold sm:text-lg">Grade Distribution</h3>
                    <div class="space-y-2 sm:space-y-3">
                        @foreach($examAnalysis['grade_breakdown'] as $grade => $count)
                            @php
                                $percentage = $examAnalysis['total_participants'] > 0 ? ($count / $examAnalysis['total_participants']) * 100 : 0;
                            @endphp
                            <div class="flex items-center justify-between p-2 border rounded-lg sm:p-3">
                                <div class="flex items-center space-x-2 sm:space-x-3">
                                    <x-badge
                                        label="{{ $grade }}"
                                        color="{{ $this->getGradeColor($grade) }}"
                                        class="badge-sm sm:badge-lg"
                                    />
                                    <span class="text-sm font-medium sm:text-base">Grade {{ $grade }}</span>
                                </div>
                                <div class="flex items-center space-x-1 sm:space-x-2">
                                    <div class="w-16 h-2 bg-gray-200 rounded-full sm:w-24 sm:h-3">
                                        <div class="h-2 rounded-full sm:h-3 {{ match($grade) {
                                            'A' => 'bg-green-500',
                                            'B' => 'bg-blue-500',
                                            'C' => 'bg-yellow-500',
                                            'D' => 'bg-orange-500',
                                            'F' => 'bg-red-500',
                                            default => 'bg-gray-500'
                                        } }}" style="width: {{ $percentage }}%"></div>
                                    </div>
                                    <span class="text-xs font-medium sm:text-sm">{{ $count }} ({{ round($percentage, 1) }}%)</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Top Performers -->
                <div>
                    <h3 class="mb-3 text-base font-semibold sm:text-lg">Top Performers</h3>
                    <div class="space-y-2 overflow-y-auto max-h-64 sm:max-h-80">
                        @foreach($examAnalysis['top_performers'] as $index => $result)
                            <div class="flex items-center justify-between p-2 border rounded-lg sm:p-3">
                                <div class="flex items-center space-x-2 sm:space-x-3">
                                    <div class="flex items-center justify-center w-6 h-6 rounded-full sm:w-8 sm:h-8 {{ $index < 3 ? 'bg-yellow-500' : 'bg-gray-300' }}">
                                        <span class="text-xs font-bold text-white sm:text-sm">{{ $index + 1 }}</span>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium sm:text-base">
                                            <span class="hidden sm:inline">{{ $result->programEnrollment->childProfile->full_name ?? 'Unknown' }}</span>
                                            <span class="sm:hidden">{{ Str::limit($result->programEnrollment->childProfile->full_name ?? 'Unknown', 15) }}</span>
                                        </div>
                                        <div class="text-xs text-gray-500 sm:text-sm">
                                            {{ $this->getGrade($result->score) }} Grade
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-base font-bold sm:text-lg {{ $result->score >= 90 ? 'text-green-600' : ($result->score >= 80 ? 'text-blue-600' : ($result->score >= 70 ? 'text-yellow-600' : 'text-red-600')) }}">
                                        {{ $result->score }}%
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Score Distribution -->
            <div class="mt-4 sm:mt-6">
                <h3 class="mb-3 text-base font-semibold sm:text-lg">Score Distribution</h3>
                <div class="grid grid-cols-3 gap-2 sm:gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                    @foreach($examAnalysis['score_distribution'] as $range => $count)
                        <div class="p-2 text-center border rounded-lg sm:p-3">
                            <div class="text-lg font-bold text-blue-600 sm:text-2xl">{{ $count }}</div>
                            <div class="text-xs text-gray-600 sm:text-sm">{{ $range }}%</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-card>
    @endif

    @if($reportType === 'student' && $selectedStudent && !empty($studentAnalysis))
        <!-- Student Analysis -->
        <x-card title="Student Analysis: {{ $studentAnalysis['student_info']->full_name }}" class="mb-4 sm:mb-6">
            <div class="grid grid-cols-2 gap-3 mb-4 sm:gap-4 sm:mb-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="p-3 border rounded-lg sm:p-4">
                    <h3 class="text-sm font-semibold text-blue-600 sm:text-base">Total Exams</h3>
                    <p class="text-xl font-bold sm:text-2xl">{{ $studentAnalysis['total_exams_taken'] }}</p>
                </div>
                <div class="p-3 border rounded-lg sm:p-4">
                    <h3 class="text-sm font-semibold text-green-600 sm:text-base">Average Score</h3>
                    <p class="text-xl font-bold sm:text-2xl">{{ $studentAnalysis['average_score'] }}%</p>
                </div>
                <div class="p-3 border rounded-lg sm:p-4">
                    <h3 class="text-sm font-semibold text-purple-600 sm:text-base">Highest Score</h3>
                    <p class="text-xl font-bold sm:text-2xl">{{ $studentAnalysis['highest_score'] }}%</p>
                </div>
                <div class="p-3 border rounded-lg sm:p-4">
                    <h3 class="text-sm font-semibold text-yellow-600 sm:text-base">Trend</h3>
                    <x-badge
                        label="{{ $studentAnalysis['improvement_trend'] }}"
                        color="{{ match($studentAnalysis['improvement_trend']) {
                            'Improving' => 'success',
                            'Stable' => 'info',
                            'Declining' => 'warning',
                            default => 'ghost'
                        } }}"
                        class="badge-sm sm:badge-lg"
                    />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:gap-6 lg:grid-cols-2">
                <!-- Subject Performance -->
                <div>
                    <h3 class="mb-3 text-base font-semibold sm:text-lg">Performance by Subject</h3>
                    <div class="space-y-2 sm:space-y-3">
                        @foreach($studentAnalysis['subject_performance'] as $subject => $performance)
                            <div class="p-2 border rounded-lg sm:p-3">
                                <div class="flex flex-col items-start justify-between mb-2 sm:flex-row sm:items-center">
                                    <span class="text-sm font-semibold sm:text-base">{{ $subject }}</span>
                                    <div class="flex items-center mt-1 space-x-2 sm:mt-0">
                                        <span class="text-xs text-gray-600 sm:text-sm">{{ $performance['total_exams'] }} exams</span>
                                        <x-badge
                                            label="{{ $performance['improvement_trend'] }}"
                                            color="{{ match($performance['improvement_trend']) {
                                                'Improving' => 'success',
                                                'Stable' => 'info',
                                                'Declining' => 'warning',
                                                default => 'ghost'
                                            } }}"
                                            class="badge-xs"
                                        />
                                    </div>
                                </div>
                                <div class="grid grid-cols-3 gap-1 text-xs sm:gap-2 sm:text-sm">
                                    <div class="text-center">
                                        <div class="font-semibold {{ $performance['average_score'] >= 80 ? 'text-green-600' : ($performance['average_score'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                            {{ $performance['average_score'] }}%
                                        </div>
                                        <div class="text-xs text-gray-500">Average</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-green-600">{{ $performance['highest_score'] }}%</div>
                                        <div class="text-xs text-gray-500">Best</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-semibold text-red-600">{{ $performance['lowest_score'] }}%</div>
                                        <div class="text-xs text-gray-500">Lowest</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Recent Exams -->
                <div>
                    <h3 class="mb-3 text-base font-semibold sm:text-lg">Recent Exam Results</h3>
                    <div class="space-y-2 overflow-y-auto max-h-64 sm:max-h-80">
                        @foreach($studentAnalysis['recent_exams'] as $result)
                            <div class="flex items-center justify-between p-2 border rounded-lg sm:p-3">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium truncate sm:text-base">{{ $result->exam->title ?? 'Unknown Exam' }}</div>
                                    <div class="text-xs text-gray-500 sm:text-sm">
                                        {{ $result->exam->subject->name ?? 'Unknown Subject' }} •
                                        {{ $result->exam->exam_date?->format('M d') ?? 'No date' }}
                                    </div>
                                </div>
                                <div class="ml-2 text-right">
                                    <div class="text-base font-bold sm:text-lg {{ $result->score >= 90 ? 'text-green-600' : ($result->score >= 80 ? 'text-blue-600' : ($result->score >= 70 ? 'text-yellow-600' : 'text-red-600')) }}">
                                        {{ $result->score }}%
                                    </div>
                                    <x-badge
                                        label="{{ $this->getGrade($result->score) }}"
                                        color="{{ $this->getGradeColor($this->getGrade($result->score)) }}"
                                        class="badge-xs"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Grade Distribution for Student -->
            <div class="mt-4 sm:mt-6">
                <h3 class="mb-3 text-base font-semibold sm:text-lg">Grade Distribution</h3>
                <div class="grid grid-cols-5 gap-2 sm:gap-4">
                    @foreach($studentAnalysis['grade_distribution'] as $grade => $count)
                        <div class="p-2 text-center border rounded-lg sm:p-3">
                            <div class="text-lg font-bold sm:text-2xl {{ match($grade) {
                                'A' => 'text-green-600',
                                'B' => 'text-blue-600',
                                'C' => 'text-yellow-600',
                                'D' => 'text-orange-600',
                                'F' => 'text-red-600',
                                default => 'text-gray-600'
                            } }}">{{ $count }}</div>
                            <div class="text-xs text-gray-600 sm:text-sm">Grade {{ $grade }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-card>
    @endif

    @if($reportFormat === 'summary' && $reportType === 'overview')
        <!-- Summary View -->
        <div class="grid grid-cols-1 gap-4 lg:gap-6 lg:grid-cols-2">
            <!-- Grade Distribution -->
            <x-card title="Grade Distribution">
                <div class="space-y-3 sm:space-y-4">
                    @foreach($statistics['grade_distribution'] as $grade => $count)
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium sm:text-base">Grade {{ $grade }}</span>
                            <div class="flex items-center space-x-2">
                                <div class="w-20 h-2 bg-gray-200 rounded-full sm:w-32 sm:h-3">
                                    @php
                                        $percentage = $statistics['total_results'] > 0 ? ($count / $statistics['total_results']) * 100 : 0;
                                        $color = match($grade) {
                                            'A' => 'bg-green-500',
                                            'B' => 'bg-blue-500',
                                            'C' => 'bg-yellow-500',
                                            'D' => 'bg-orange-500',
                                            'F' => 'bg-red-500',
                                            default => 'bg-gray-500'
                                        };
                                    @endphp
                                    <div class="{{ $color }} h-2 rounded-full sm:h-3" style="width: {{ $percentage }}%"></div>
                                </div>
                                <span class="text-xs text-gray-600 sm:text-sm">{{ $count }} ({{ round($percentage, 1) }}%)</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <!-- Subject Performance -->
            <x-card title="Subject Performance">
                <div class="space-y-2 sm:space-y-3">
                    @foreach($statistics['subject_performance'] as $subject => $performance)
                        <div class="p-2 border rounded-lg sm:p-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-semibold sm:text-base">{{ $subject }}</span>
                                <span class="text-xs text-gray-600 sm:text-sm">{{ $performance['total_results'] }} results</span>
                            </div>
                            <div class="grid grid-cols-3 gap-1 text-xs sm:gap-2 sm:text-sm">
                                <div class="text-center">
                                    <div class="font-semibold {{ $performance['average_score'] >= 80 ? 'text-green-600' : ($performance['average_score'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $performance['average_score'] }}%
                                    </div>
                                    <div class="text-xs text-gray-500">Average</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold text-green-600">{{ $performance['highest_score'] }}%</div>
                                    <div class="text-xs text-gray-500">Highest</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold {{ $performance['pass_rate'] >= 80 ? 'text-green-600' : ($performance['pass_rate'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $performance['pass_rate'] }}%
                                    </div>
                                    <div class="text-xs text-gray-500">Pass Rate</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>
    @elseif($reportFormat === 'analysis')
        <!-- Analysis View -->
        <div class="grid grid-cols-1 gap-4 sm:gap-6">
            <!-- Top Performers -->
            <x-card title="Top Performing Students">
                <div class="grid grid-cols-1 gap-2 sm:gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach(array_slice($statistics['student_performance'], 0, 9) as $student)
                        <div class="p-2 border rounded-lg sm:p-3">
                            <div class="flex flex-col items-start justify-between mb-2 sm:flex-row sm:items-center">
                                <span class="text-sm font-semibold sm:text-base">{{ $student['student_name'] }}</span>
                                <x-badge
                                    label="{{ $student['improvement_trend'] }}"
                                    color="{{ match($student['improvement_trend']) {
                                        'Improving' => 'success',
                                        'Stable' => 'info',
                                        'Declining' => 'warning',
                                        default => 'ghost'
                                    } }}"
                                    class="mt-1 badge-xs sm:mt-0"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-1 text-xs sm:gap-2">
                                <div class="text-center">
                                    <div class="text-base font-semibold sm:text-lg {{ $student['average_score'] >= 80 ? 'text-green-600' : ($student['average_score'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $student['average_score'] }}%
                                    </div>
                                    <div class="text-xs text-gray-500">Avg Score</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-base font-semibold sm:text-lg">{{ $student['total_exams'] }}</div>
                                    <div class="text-xs text-gray-500">Exams</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <!-- Exam Difficulty Analysis -->
            <x-card title="Exam Difficulty Analysis">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:px-6 sm:py-3">Exam</th>
                                <th class="hidden px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:table-cell">Subject</th>
                                <th class="hidden px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase md:table-cell">Date</th>
                                <th class="px-3 py-2 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:px-6 sm:py-3">Students</th>
                                <th class="px-3 py-2 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:px-6 sm:py-3">Avg Score</th>
                                <th class="hidden px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:table-cell">Pass Rate</th>
                                <th class="px-3 py-2 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:px-6 sm:py-3">Difficulty</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($statistics['exam_difficulty'] as $exam)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2 text-xs font-medium text-gray-900 sm:px-6 sm:py-4 sm:text-sm">
                                        <div class="truncate max-w-32 sm:max-w-none">{{ $exam['exam_title'] }}</div>
                                        <div class="text-xs text-gray-500 sm:hidden">{{ $exam['subject'] }}</div>
                                    </td>
                                    <td class="hidden px-6 py-4 text-sm text-gray-500 sm:table-cell">{{ $exam['subject'] }}</td>
                                    <td class="hidden px-6 py-4 text-sm text-gray-500 md:table-cell">
                                        {{ $exam['exam_date'] ? \Carbon\Carbon::parse($exam['exam_date'])->format('M d, Y') : 'N/A' }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-900 sm:px-6 sm:py-4 sm:text-sm">{{ $exam['total_students'] }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-900 sm:px-6 sm:py-4 sm:text-sm">{{ $exam['average_score'] }}%</td>
                                    <td class="hidden px-6 py-4 text-sm text-gray-900 sm:table-cell">{{ $exam['pass_rate'] }}%</td>
                                    <td class="px-3 py-2 sm:px-6 sm:py-4 whitespace-nowrap">
                                        <x-badge
                                            label="{{ $exam['difficulty'] }}"
                                            color="{{ match($exam['difficulty']) {
                                                'Easy' => 'success',
                                                'Medium' => 'warning',
                                                'Hard' => 'error',
                                                default => 'ghost'
                                            } }}"
                                            class="badge-xs sm:badge-sm"
                                        />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    @else
        <!-- Table View -->
        <x-card title="Exam Results Details">
            @if($examResults->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:px-6 sm:py-3">Student</th>
                                <th class="hidden px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase md:table-cell">Exam</th>
                                <th class="hidden px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase lg:table-cell">Subject</th>
                                <th class="hidden px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:table-cell">Date</th>
                                <th class="px-3 py-2 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:px-6 sm:py-3">Score</th>
                                <th class="px-3 py-2 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:px-6 sm:py-3">Grade</th>
                                <th class="hidden px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase sm:table-cell">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($examResults as $result)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2 sm:px-6 sm:py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex items-center justify-center w-6 h-6 bg-blue-500 rounded-full sm:w-8 sm:h-8">
                                                <span class="text-xs font-semibold text-white">
                                                    {{ strtoupper(substr($result->programEnrollment->childProfile->full_name ?? 'UK', 0, 2)) }}
                                                </span>
                                            </div>
                                            <div class="ml-2 sm:ml-3">
                                                <div class="text-xs font-medium text-gray-900 sm:text-sm">
                                                    <span class="hidden sm:inline">{{ $result->programEnrollment->childProfile->full_name ?? 'Unknown' }}</span>
                                                    <span class="sm:hidden">{{ Str::limit($result->programEnrollment->childProfile->full_name ?? 'Unknown', 15) }}</span>
                                                </div>
                                                <div class="text-xs text-gray-500 sm:hidden">
                                                    {{ Str::limit($result->exam->title ?? 'Unknown Exam', 20) }}
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    ID: {{ $result->programEnrollment->childProfile->id ?? 'N/A' }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="hidden px-6 py-4 md:table-cell whitespace-nowrap">
                                        <div class="text-sm text-gray-900">{{ $result->exam->title ?? 'Unknown Exam' }}</div>
                                    </td>
                                    <td class="hidden px-6 py-4 lg:table-cell whitespace-nowrap">
                                        <div class="text-sm text-gray-900">{{ $result->exam->subject->name ?? 'Unknown' }}</div>
                                        <div class="text-sm text-gray-500">{{ $result->exam->subject->code ?? '' }}</div>
                                    </td>
                                    <td class="hidden px-6 py-4 text-sm text-gray-900 sm:table-cell whitespace-nowrap">
                                        <div>{{ $result->exam->exam_date?->format('M d, Y') ?? 'N/A' }}</div>
                                        <div class="text-xs text-gray-500">{{ $result->exam->exam_date?->format('H:i A') ?? '' }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-sm font-medium sm:px-6 sm:py-4 whitespace-nowrap">
                                        <span class="{{ $result->score >= 80 ? 'text-green-600' : ($result->score >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                            {{ $result->score }}%
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 sm:px-6 sm:py-4 whitespace-nowrap">
                                        @php
                                            $grade = $this->getGrade($result->score);
                                            $gradeColor = $this->getGradeColor($grade);
                                        @endphp
                                        <x-badge
                                            label="{{ $grade }}"
                                            color="{{ $gradeColor }}"
                                            class="badge-xs sm:badge-sm"
                                        />
                                    </td>
                                    <td class="hidden px-6 py-4 sm:table-cell whitespace-nowrap">
                                        <x-badge
                                            label="{{ $result->score >= 60 ? 'Pass' : 'Fail' }}"
                                            color="{{ $result->score >= 60 ? 'success' : 'error' }}"
                                            class="badge-sm"
                                        />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($examResults->count() >= 50)
                    <div class="p-3 mt-4 text-center rounded-lg bg-yellow-50 sm:p-4">
                        <p class="text-xs text-yellow-700 sm:text-sm">
                            <x-icon name="o-information-circle" class="inline w-3 h-3 mr-1 sm:w-4 sm:h-4" />
                            Showing results based on current filters. Use more specific filters for better performance.
                        </p>
                    </div>
                @endif
            @else
                <div class="py-8 text-center sm:py-12">
                    <x-icon name="o-document-magnifying-glass" class="w-8 h-8 mx-auto text-gray-400 sm:w-12 sm:h-12" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900">No exam results found</h3>
                    <p class="mt-1 text-sm text-gray-500">Try adjusting your filters to find exam results.</p>
                </div>
            @endif
        </x-card>
    @endif
</div>
