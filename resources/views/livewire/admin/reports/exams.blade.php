       <?php

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Subject;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Exam Report')] class extends Component {
    use Toast;

    public string $selectedAcademicYear = '';
    public string $selectedCurriculum = '';
    public string $selectedSubject = '';
    public string $selectedExam = '';
    public string $gradeFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $reportFormat = 'table';

    public array $gradeRanges = [
        'A' => 'A (90-100%)',
        'B' => 'B (80-89%)',
        'C' => 'C (70-79%)',
        'D' => 'D (60-69%)',
        'F' => 'F (Below 60%)'
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
        $query = Exam::orderBy('exam_date', 'desc');

        if ($this->selectedAcademicYear) {
            $query->where('academic_year_id', $this->selectedAcademicYear);
        }

        if ($this->selectedSubject) {
            $query->where('subject_id', $this->selectedSubject);
        }

        return $query->get();
    }

    public function getExamResultsProperty(): Collection
    {
        $query = ExamResult::with([
            'exam.subject.curriculum',
            'exam.academicYear',
            'programEnrollment.childProfile'
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

        return $query->get();
    }

    public function getStatisticsProperty(): array
    {
        $results = $this->examResults;

        $stats = [
            'total_results' => $results->count(),
            'total_exams' => $results->pluck('exam_id')->unique()->count(),
            'total_students' => $results->pluck('program_enrollment_id')->unique()->count(),
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
        $stats['pass_rate'] = $stats
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
        $studentGroups = $results->groupBy('program_enrollment_id');
        foreach ($studentGroups as $studentId => $studentResults) {
            $studentName = $studentResults->first()->programEnrollment->childProfile->full_name ?? 'Unknown';
            $stats['student_performance'][] = [
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

    private function calculateImprovementTrend($studentResults): string
    {
        if ($studentResults->count() < 2) {
            return 'Insufficient data';
        }

        $sortedResults = $studentResults->sortBy(function($result) {
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
        $this->gradeFilter = '';
        $this->dateFrom = now()->startOfYear()->format('Y-m-d');
        $this->dateTo = now()->endOfYear()->format('Y-m-d');
        $this->success('Filters reset successfully.');
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Exam Report" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <x-badge
                    label="{{ $this->examResults->count() }} Results"
                    color="primary"
                    class="badge-lg"
                />
                <x-badge
                    label="{{ $this->statistics['average_score'] }}% Avg Score"
                    color="{{ $this->statistics['average_score'] >= 80 ? 'success' : ($this->statistics['average_score'] >= 60 ? 'warning' : 'error') }}"
                    class="badge-lg"
                />
                <x-badge
                    label="{{ $this->statistics['pass_rate'] }}% Pass Rate"
                    color="{{ $this->statistics['pass_rate'] >= 80 ? 'success' : ($this->statistics['pass_rate'] >= 60 ? 'warning' : 'error') }}"
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
                <label class="block mb-2 text-sm font-medium text-gray-700">Specific Exam</label>
                <select wire:model.live="selectedExam" class="w-full select select-bordered">
                    <option value="">All Exams</option>
                    @foreach($this->exams as $exam)
                        <option value="{{ $exam->id }}">{{ $exam->title }} ({{ $exam->exam_date?->format('M d, Y') }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block mb-2 text-sm font-medium text-gray-700">Grade Filter</label>
                <select wire:model.live="gradeFilter" class="w-full select select-bordered">
                    <option value="">All Grades</option>
                    @foreach($gradeRanges as $grade => $label)
                        <option value="{{ $grade }}">{{ $label }}</option>
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
                    <option value="analysis">Analysis View</option>
                </select>
            </div>
        </div>
    </x-card>

    <!-- Statistics -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-2 lg:grid-cols-6">
        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-blue-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total Results</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['total_results'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-green-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Average Score</p>
                    <p class="text-2xl font-bold text-gray-900">{{ round($this->statistics['average_score'], 1) }}%</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-purple-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Highest Score</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['highest_score'] }}%</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-red-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Lowest Score</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['lowest_score'] }}%</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-yellow-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Pass Rate</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['pass_rate'] }}%</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex items-center justify-center w-12 h-12 bg-indigo-500 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total Students</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->statistics['total_students'] }}</p>
                </div>
            </div>
        </x-card>
    </div>

    @if($reportFormat === 'summary')
        <!-- Summary View -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Grade Distribution -->
            <x-card title="Grade Distribution">
                <div class="space-y-4">
                    @foreach($this->statistics['grade_distribution'] as $grade => $count)
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium">Grade {{ $grade }}</span>
                            <div class="flex items-center space-x-2">
                                <div class="w-32 h-3 bg-gray-200 rounded-full">
                                    @php
                                        $percentage = $this->statistics['total_results'] > 0 ? ($count / $this->statistics['total_results']) * 100 : 0;
                                        $color = match($grade) {
                                            'A' => 'bg-green-500',
                                            'B' => 'bg-blue-500',
                                            'C' => 'bg-yellow-500',
                                            'D' => 'bg-orange-500',
                                            'F' => 'bg-red-500',
                                            default => 'bg-gray-500'
                                        };
                                    @endphp
                                    <div class="{{ $color }} h-3 rounded-full" style="width: {{ $percentage }}%"></div>
                                </div>
                                <span class="text-sm text-gray-600">{{ $count }} ({{ round($percentage, 1) }}%)</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <!-- Subject Performance -->
            <x-card title="Subject Performance">
                <div class="space-y-3">
                    @foreach($this->statistics['subject_performance'] as $subject => $performance)
                        <div class="p-3 border rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-semibold">{{ $subject }}</span>
                                <span class="text-sm text-gray-600">{{ $performance['total_results'] }} results</span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-sm">
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
        <div class="grid grid-cols-1 gap-6">
            <!-- Top Performers -->
            <x-card title="Top Performing Students">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                    @foreach(array_slice($this->statistics['student_performance'], 0, 9) as $student)
                        <div class="p-3 border rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-semibold">{{ $student['student_name'] }}</span>
                                <x-badge
                                    label="{{ $student['improvement_trend'] }}"
                                    color="{{ match($student['improvement_trend']) {
                                        'Improving' => 'success',
                                        'Stable' => 'info',
                                        'Declining' => 'warning',
                                        default => 'ghost'
                                    } }}"
                                    class="badge-xs"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div class="text-center">
                                    <div class="font-semibold text-lg {{ $student['average_score'] >= 80 ? 'text-green-600' : ($student['average_score'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $student['average_score'] }}%
                                    </div>
                                    <div class="text-gray-500">Avg Score</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold">{{ $student['total_exams'] }}</div>
                                    <div class="text-gray-500">Exams</div>
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
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Exam</th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Subject</th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Students</th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Avg Score</th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Pass Rate</th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Difficulty</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($this->statistics['exam_difficulty'] as $exam)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $exam['exam_title'] }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $exam['subject'] }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        {{ $exam['exam_date'] ? \Carbon\Carbon::parse($exam['exam_date'])->format('M d, Y') : 'N/A' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">{{ $exam['total_students'] }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">{{ $exam['average_score'] }}%</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">{{ $exam['pass_rate'] }}%</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <x-badge
                                            label="{{ $exam['difficulty'] }}"
                                            color="{{ match($exam['difficulty']) {
                                                'Easy' => 'success',
                                                'Medium' => 'warning',
                                                'Hard' => 'error',
                                                default => 'ghost'
                                            } }}"
                                            class="badge-sm"
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
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Student</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Exam</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Subject</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Score</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Grade</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($this->examResults as $result)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                                            <span class="text-xs font-semibold text-white">
                                                {{ strtoupper(substr($result->programEnrollment->childProfile->full_name ?? 'UK', 0, 2)) }}
                                            </span>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $result->programEnrollment->childProfile->full_name ?? 'Unknown' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ $result->exam->title ?? 'Unknown Exam' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ $result->exam->subject->name ?? 'Unknown' }}</div>
                                    <div class="text-sm text-gray-500">{{ $result->exam->subject->code ?? '' }}</div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 whitespace-nowrap">
                                    {{ $result->exam->exam_date?->format('M d, Y') ?? 'N/A' }}
                                </td>
                                <td class="px-6 py-4 text-sm font-medium whitespace-nowrap">
                                    <span class="{{ $result->score >= 80 ? 'text-green-600' : ($result->score >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $result->score }}%
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $grade = 'F';
                                        if ($result->score >= 90) $grade = 'A';
                                        elseif ($result->score >= 80) $grade = 'B';
                                        elseif ($result->score >= 70) $grade = 'C';
                                        elseif ($result->score >= 60) $grade = 'D';

                                        $gradeColor = match($grade) {
                                            'A' => 'success',
                                            'B' => 'info',
                                            'C' => 'warning',
                                            'D' => 'warning',
                                            'F' => 'error',
                                            default => 'ghost'
                                        };
                                    @endphp
                                    <x-badge
                                        label="{{ $grade }}"
                                        color="{{ $gradeColor }}"
                                        class="badge-sm"
                                    />
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
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
        </x-card>
    @endif
</div>
