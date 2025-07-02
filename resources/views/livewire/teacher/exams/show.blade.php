<?php

use App\Models\Exam;
use App\Models\TeacherProfile;
use App\Models\ExamResult;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Exam Details')] class extends Component {
    use Toast;

    // Model instances
    public Exam $exam;
    public ?TeacherProfile $teacherProfile = null;

    // Data collections
    public $examResults = [];

    // Stats
    public array $stats = [];

    // Mount the component
    public function mount(Exam $exam): void
    {
        $this->exam = $exam->load(['subject', 'subject.curriculum', 'teacherProfile', 'academicYear', 'examResults', 'examResults.childProfile']);
        $this->teacherProfile = Auth::user()->teacherProfile;

        if (!$this->teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            $this->redirect(route('teacher.profile.edit'));
            return;
        }

        // Check if teacher owns this exam
        if ($this->exam->teacher_profile_id !== $this->teacherProfile->id) {
            $this->error('You are not authorized to view this exam.');
            $this->redirect(route('teacher.exams.index'));
            return;
        }

        Log::info('Exam Show Component Mounted', [
            'teacher_user_id' => Auth::id(),
            'exam_id' => $exam->id,
            'subject_id' => $exam->subject_id,
            'ip' => request()->ip()
        ]);

        $this->loadExamResults();
        $this->loadStats();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed exam details: {$exam->title} for {$exam->subject->name} on {$exam->exam_date->format('M d, Y')}",
            Exam::class,
            $exam->id,
            [
                'exam_id' => $exam->id,
                'exam_title' => $exam->title,
                'subject_name' => $exam->subject->name,
                'exam_date' => $exam->exam_date->toDateString(),
                'ip' => request()->ip()
            ]
        );
    }

    protected function loadExamResults(): void
    {
        try {
            $this->examResults = $this->exam->examResults()
                ->with(['childProfile', 'childProfile.user'])
                ->orderBy('score', 'desc')
                ->get();

            Log::info('Exam Results Loaded', [
                'exam_id' => $this->exam->id,
                'results_count' => $this->examResults->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to load exam results', [
                'exam_id' => $this->exam->id,
                'error' => $e->getMessage()
            ]);

            $this->examResults = collect();
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalResults = $this->examResults->count();

            if ($totalResults === 0) {
                $this->stats = [
                    'total_results' => 0,
                    'average_score' => 0,
                    'highest_score' => 0,
                    'lowest_score' => 0,
                    'pass_rate' => 0,
                    'grade_distribution' => [],
                ];
                return;
            }

            $scores = $this->examResults->pluck('score')->filter();
            $averageScore = $scores->avg();
            $highestScore = $scores->max();
            $lowestScore = $scores->min();

            // Calculate pass rate (assuming 50% is pass)
            $passingScore = $this->exam->total_marks ? ($this->exam->total_marks * 0.5) : 50;
            $passedCount = $scores->filter(fn($score) => $score >= $passingScore)->count();
            $passRate = $totalResults > 0 ? round(($passedCount / $totalResults) * 100, 1) : 0;

            // Grade distribution
            $gradeDistribution = [];
            if ($this->exam->total_marks) {
                $gradeDistribution = [
                    'A' => $scores->filter(fn($score) => $score >= ($this->exam->total_marks * 0.9))->count(),
                    'B' => $scores->filter(fn($score) => $score >= ($this->exam->total_marks * 0.8) && $score < ($this->exam->total_marks * 0.9))->count(),
                    'C' => $scores->filter(fn($score) => $score >= ($this->exam->total_marks * 0.7) && $score < ($this->exam->total_marks * 0.8))->count(),
                    'D' => $scores->filter(fn($score) => $score >= ($this->exam->total_marks * 0.5) && $score < ($this->exam->total_marks * 0.7))->count(),
                    'F' => $scores->filter(fn($score) => $score < ($this->exam->total_marks * 0.5))->count(),
                ];
            }

            $this->stats = [
                'total_results' => $totalResults,
                'average_score' => round($averageScore, 1),
                'highest_score' => $highestScore,
                'lowest_score' => $lowestScore,
                'pass_rate' => $passRate,
                'grade_distribution' => $gradeDistribution,
            ];

        } catch (\Exception $e) {
            $this->stats = [
                'total_results' => 0,
                'average_score' => 0,
                'highest_score' => 0,
                'lowest_score' => 0,
                'pass_rate' => 0,
                'grade_distribution' => [],
            ];
        }
    }

    // Get exam status
    public function getExamStatusProperty(): array
    {
        $now = now();

        if ($this->exam->exam_date > $now) {
            return ['status' => 'upcoming', 'color' => 'bg-blue-100 text-blue-800', 'text' => 'Upcoming'];
        } elseif ($this->examResults->count() > 0) {
            return ['status' => 'graded', 'color' => 'bg-green-100 text-green-800', 'text' => 'Graded'];
        } else {
            return ['status' => 'pending', 'color' => 'bg-yellow-100 text-yellow-800', 'text' => 'Pending Results'];
        }
    }

    // Get exam type color
    public function getExamTypeColor(string $type): string
    {
        return match(strtolower($type)) {
            'quiz' => 'bg-green-100 text-green-800',
            'midterm' => 'bg-yellow-100 text-yellow-800',
            'final' => 'bg-red-100 text-red-800',
            'assignment' => 'bg-blue-100 text-blue-800',
            'project' => 'bg-purple-100 text-purple-800',
            'practical' => 'bg-orange-100 text-orange-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Get grade for score
    public function getGrade(float $score): string
    {
        if (!$this->exam->total_marks) {
            return 'N/A';
        }

        $percentage = ($score / $this->exam->total_marks) * 100;

        if ($percentage >= 90) return 'A';
        if ($percentage >= 80) return 'B';
        if ($percentage >= 70) return 'C';
        if ($percentage >= 50) return 'D';
        return 'F';
    }

    // Get grade color
    public function getGradeColor(string $grade): string
    {
        return match($grade) {
            'A' => 'bg-green-100 text-green-800',
            'B' => 'bg-blue-100 text-blue-800',
            'C' => 'bg-yellow-100 text-yellow-800',
            'D' => 'bg-orange-100 text-orange-800',
            'F' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Navigation methods
    public function redirectToEdit(): void
    {
        $this->redirect(route('teacher.exams.edit', $this->exam->id));
    }

    public function redirectToResults(): void
    {
        $this->redirect(route('teacher.exams.results', $this->exam->id));
    }

    public function redirectToCreateResults(): void
    {
        $this->redirect(route('teacher.exam-results.create', $this->exam->id));
    }

    public function redirectToExamsList(): void
    {
        $this->redirect(route('teacher.exams.index'));
    }

    public function redirectToSubjectShow(): void
    {
        $this->redirect(route('teacher.subjects.show', $this->exam->subject_id));
    }

    // Format duration
    public function getDurationProperty(): string
    {
        if ($this->exam->duration) {
            $minutes = $this->exam->duration;

            if ($minutes >= 60) {
                $hours = floor($minutes / 60);
                $remainingMinutes = $minutes % 60;
                return $remainingMinutes > 0 ? "{$hours}h {$remainingMinutes}m" : "{$hours}h";
            }

            return "{$minutes}m";
        }

        return 'Not specified';
    }

    public function with(): array
    {
        return [
            'examStatus' => $this->examStatus,
            'duration' => $this->duration,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Exam: {{ $exam->title }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Exam Status -->
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $examStatus['color'] }}">
                {{ $examStatus['text'] }}
            </span>
        </x-slot:middle>

        <x-slot:actions>
            @if($examStatus['status'] === 'pending' || $examStatus['status'] === 'graded')
                <x-button
                    label="Manage Results"
                    icon="o-chart-bar"
                    wire:click="redirectToResults"
                    class="btn-primary"
                />
            @endif

            <x-button
                label="Edit Exam"
                icon="o-pencil"
                wire:click="redirectToEdit"
                class="btn-secondary"
            />

            <x-button
                label="Back to Exams"
                icon="o-arrow-left"
                wire:click="redirectToExamsList"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Main Content -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Exam Information -->
            <x-card title="Exam Information">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject</div>
                        <div class="text-lg font-semibold">{{ $exam->subject->name }}</div>
                        <div class="text-sm text-gray-600">{{ $exam->subject->code }}</div>
                        @if($exam->subject->curriculum)
                            <div class="text-xs text-gray-500">{{ $exam->subject->curriculum->name }}</div>
                        @endif
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Exam Type</div>
                        <div>
                            @if($exam->type)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getExamTypeColor($exam->type) }}">
                                    {{ ucfirst($exam->type) }}
                                </span>
                            @else
                                <span class="text-gray-500">Not specified</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Exam Date</div>
                        <div class="font-semibold">{{ $exam->exam_date->format('l, M d, Y') }}</div>
                        <div class="text-sm text-gray-600">
                            @if($examStatus['status'] === 'upcoming')
                                {{ $exam->exam_date->diffForHumans() }}
                            @else
                                {{ $exam->exam_date->diffForHumans() }}
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Duration</div>
                        <div>{{ $duration }}</div>
                    </div>

                    @if($exam->total_marks)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Total Marks</div>
                            <div class="font-semibold">{{ $exam->total_marks }} marks</div>
                        </div>
                    @endif

                    <div>
                        <div class="text-sm font-medium text-gray-500">Academic Year</div>
                        <div>{{ $exam->academicYear ? $exam->academicYear->name : 'Not specified' }}</div>
                    </div>
                </div>

                @if($exam->description)
                    <div class="pt-4 mt-4 border-t">
                        <div class="mb-2 text-sm font-medium text-gray-500">Description</div>
                        <div class="p-3 text-sm text-gray-600 rounded-md bg-gray-50">
                            {{ $exam->description }}
                        </div>
                    </div>
                @endif

                @if($exam->instructions)
                    <div class="pt-4 mt-4 border-t">
                        <div class="mb-2 text-sm font-medium text-gray-500">Instructions</div>
                        <div class="p-3 text-sm text-gray-600 rounded-md bg-gray-50">
                            {{ $exam->instructions }}
                        </div>
                    </div>
                @endif
            </x-card>

            <!-- Results Overview -->
            @if($stats['total_results'] > 0)
                <x-card title="Results Overview">
                    <!-- Statistics Grid -->
                    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
                        <div class="p-4 text-center rounded-lg bg-blue-50">
                            <div class="text-2xl font-bold text-blue-600">{{ $stats['total_results'] }}</div>
                            <div class="text-sm text-blue-600">Total Results</div>
                        </div>

                        <div class="p-4 text-center rounded-lg bg-green-50">
                            <div class="text-2xl font-bold text-green-600">{{ $stats['average_score'] }}</div>
                            <div class="text-sm text-green-600">Average Score</div>
                        </div>

                        <div class="p-4 text-center rounded-lg bg-yellow-50">
                            <div class="text-2xl font-bold text-yellow-600">{{ $stats['highest_score'] }}</div>
                            <div class="text-sm text-yellow-600">Highest Score</div>
                        </div>

                        <div class="p-4 text-center rounded-lg bg-purple-50">
                            <div class="text-2xl font-bold text-purple-600">{{ $stats['pass_rate'] }}%</div>
                            <div class="text-sm text-purple-600">Pass Rate</div>
                        </div>
                    </div>

                    <!-- Grade Distribution -->
                    @if(!empty($stats['grade_distribution']))
                        <div class="mb-6">
                            <h4 class="mb-3 text-sm font-medium text-gray-700">Grade Distribution</h4>
                            <div class="grid grid-cols-5 gap-2">
                                @foreach($stats['grade_distribution'] as $grade => $count)
                                    <div class="text-center p-3 border rounded-lg {{ $this->getGradeColor($grade) }}">
                                        <div class="text-lg font-bold">{{ $count }}</div>
                                        <div class="text-xs">Grade {{ $grade }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Top Results -->
                    <div>
                        <h4 class="mb-3 text-sm font-medium text-gray-700">Top Performers</h4>
                        <div class="space-y-3">
                            @foreach($examResults->take(5) as $index => $result)
                                <div class="flex items-center justify-between p-3 border rounded-lg {{ $index === 0 ? 'border-yellow-300 bg-yellow-50' : 'bg-gray-50' }}">
                                    <div class="flex items-center space-x-3">
                                        @if($index === 0)
                                            <div class="flex items-center justify-center w-6 h-6 text-xs font-bold text-white bg-yellow-500 rounded-full">1</div>
                                        @elseif($index === 1)
                                            <div class="flex items-center justify-center w-6 h-6 text-xs font-bold text-white bg-gray-400 rounded-full">2</div>
                                        @elseif($index === 2)
                                            <div class="flex items-center justify-center w-6 h-6 text-xs font-bold text-white bg-orange-400 rounded-full">3</div>
                                        @else
                                            <div class="flex items-center justify-center w-6 h-6 text-xs font-bold text-gray-600 bg-gray-300 rounded-full">{{ $index + 1 }}</div>
                                        @endif

                                        <div>
                                            <div class="font-medium">{{ $result->childProfile->full_name }}</div>
                                            <div class="text-xs text-gray-500">ID: {{ $result->childProfile->id }}</div>
                                        </div>
                                    </div>

                                    <div class="text-right">
                                        <div class="font-bold">{{ $result->score }}{{ $exam->total_marks ? '/' . $exam->total_marks : '' }}</div>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getGradeColor($this->getGrade($result->score)) }}">
                                            {{ $this->getGrade($result->score) }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if($examResults->count() > 5)
                            <div class="mt-4 text-center">
                                <x-button
                                    label="View All Results"
                                    icon="o-eye"
                                    wire:click="redirectToResults"
                                    class="btn-outline btn-sm"
                                />
                            </div>
                        @endif
                    </div>
                </x-card>
            @else
                <x-card title="Results">
                    <div class="py-8 text-center">
                        <x-icon name="o-chart-bar" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No results available</div>
                        @if($examStatus['status'] === 'pending')
                            <x-button
                                label="Add Results"
                                icon="o-plus"
                                wire:click="redirectToCreateResults"
                                class="mt-2 btn-primary btn-sm"
                            />
                        @else
                            <p class="mt-1 text-xs text-gray-400">Results will appear here after grading</p>
                        @endif
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Right column (1/3) - Sidebar -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-3">
                    @if($examStatus['status'] === 'pending' || $examStatus['status'] === 'graded')
                        <x-button
                            label="Manage Results"
                            icon="o-chart-bar"
                            wire:click="redirectToResults"
                            class="w-full btn-primary"
                        />
                    @endif

                    @if($examStatus['status'] === 'pending')
                        <x-button
                            label="Add Results"
                            icon="o-plus"
                            wire:click="redirectToCreateResults"
                            class="w-full btn-secondary"
                        />
                    @endif

                    <x-button
                        label="Edit Exam"
                        icon="o-pencil"
                        wire:click="redirectToEdit"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="View Subject"
                        icon="o-academic-cap"
                        wire:click="redirectToSubjectShow"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="All Exams"
                        icon="o-document-text"
                        wire:click="redirectToExamsList"
                        class="w-full btn-outline"
                    />
                </div>
            </x-card>

            <!-- Exam Statistics -->
            @if($stats['total_results'] > 0)
                <x-card title="Statistics">
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Total Results:</span>
                            <span class="font-medium">{{ $stats['total_results'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Average Score:</span>
                            <span class="font-medium">{{ $stats['average_score'] }}{{ $exam->total_marks ? '/' . $exam->total_marks : '' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Highest Score:</span>
                            <span class="font-medium text-green-600">{{ $stats['highest_score'] }}{{ $exam->total_marks ? '/' . $exam->total_marks : '' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Lowest Score:</span>
                            <span class="font-medium text-red-600">{{ $stats['lowest_score'] }}{{ $exam->total_marks ? '/' . $exam->total_marks : '' }}</span>
                        </div>
                        <div class="pt-2 border-t">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Pass Rate:</span>
                                <span class="font-bold text-purple-600">{{ $stats['pass_rate'] }}%</span>
                            </div>
                        </div>
                    </div>
                </x-card>
            @endif

            <!-- Exam Details -->
            <x-card title="Exam Details">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $exam->created_at->format('M d, Y \a\t g:i A') }}</div>
                        <div class="text-xs text-gray-400">{{ $exam->created_at->diffForHumans() }}</div>
                    </div>

                    @if($exam->updated_at->ne($exam->created_at))
                        <div>
                            <div class="font-medium text-gray-500">Last Updated</div>
                            <div>{{ $exam->updated_at->format('M d, Y \a\t g:i A') }}</div>
                            <div class="text-xs text-gray-400">{{ $exam->updated_at->diffForHumans() }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="font-medium text-gray-500">Exam ID</div>
                        <div class="font-mono text-xs">#{{ $exam->id }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Related Information -->
            <x-card title="Related">
                <div class="space-y-2">
                    <x-button
                        label="Subject Details"
                        icon="o-academic-cap"
                        wire:click="redirectToSubjectShow"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Subject Sessions"
                        icon="o-presentation-chart-line"
                        link="{{ route('teacher.sessions.index', ['subject' => $exam->subject_id]) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Other Exams"
                        icon="o-document-text"
                        link="{{ route('teacher.exams.index', ['subject' => $exam->subject_id]) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
