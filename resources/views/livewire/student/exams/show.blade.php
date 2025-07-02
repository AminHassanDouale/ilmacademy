<?php

use App\Models\User;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Exam Details')] class extends Component {
    use Toast;

    // Current user and exam
    public User $user;
    public Exam $exam;

    // Exam result for the student
    public ?ExamResult $examResult = null;

    // Tab management
    public string $activeTab = 'overview';

    // Exam data and related information
    public array $examStats = [];
    public array $classStats = [];

    public function mount(Exam $exam): void
    {
        $this->user = Auth::user();
        $this->exam = $exam->load(['subject', 'teacherProfile', 'academicYear', 'examResults.childProfile']);

        // Check if user has access to this exam
        $this->checkAccess();

        // Load student's exam result
        $this->loadExamResult();

        // Load statistics
        $this->loadStats();

        // Log activity
        ActivityLog::log(
            $this->user->id,
            'view',
            "Viewed exam details: {$this->exam->title}",
            Exam::class,
            $this->exam->id,
            [
                'exam_id' => $this->exam->id,
                'exam_title' => $this->exam->title,
                'subject_name' => $this->exam->subject->name ?? 'Unknown',
                'has_result' => $this->examResult !== null,
                'ip' => request()->ip()
            ]
        );
    }

    protected function checkAccess(): void
    {
        $hasAccess = false;

        // Check if user has access through their enrollments
        if ($this->user->hasRole('student')) {
            $hasAccess = $this->exam->subject->subjectEnrollments()
                ->whereHas('programEnrollment.childProfile', function ($query) {
                    $query->where('user_id', $this->user->id);
                })
                ->exists();
        } elseif ($this->user->hasRole('parent')) {
            $hasAccess = $this->exam->subject->subjectEnrollments()
                ->whereHas('programEnrollment.childProfile', function ($query) {
                    $query->where('parent_id', $this->user->id);
                })
                ->exists();
        }

        if (!$hasAccess) {
            abort(403, 'You do not have permission to view this exam.');
        }
    }

    protected function loadExamResult(): void
    {
        $this->examResult = ExamResult::where('exam_id', $this->exam->id)
            ->whereHas('childProfile', function ($query) {
                if ($this->user->hasRole('student')) {
                    $query->where('user_id', $this->user->id);
                } else {
                    $query->where('parent_id', $this->user->id);
                }
            })
            ->with('childProfile')
            ->first();
    }

    protected function loadStats(): void
    {
        try {
            // Individual exam stats
            $this->examStats = [
                'has_result' => $this->examResult !== null,
                'score' => $this->examResult->score ?? null,
                'max_score' => 100, // Assuming 100 as max score
                'percentage' => $this->examResult ? round(($this->examResult->score / 100) * 100, 1) : null,
                'grade' => $this->calculateGrade($this->examResult->score ?? null),
                'remarks' => $this->examResult->remarks ?? null,
                'is_passed' => $this->examResult ? ($this->examResult->score >= 60) : null, // Assuming 60 as passing
            ];

            // Class statistics
            $allResults = $this->exam->examResults()->whereNotNull('score')->get();

            if ($allResults->count() > 0) {
                $scores = $allResults->pluck('score');
                $this->classStats = [
                    'total_students' => $allResults->count(),
                    'average_score' => round($scores->avg(), 1),
                    'highest_score' => $scores->max(),
                    'lowest_score' => $scores->min(),
                    'passing_count' => $scores->where('>=', 60)->count(),
                    'failing_count' => $scores->where('<', 60)->count(),
                    'pass_rate' => round(($scores->where('>=', 60)->count() / $allResults->count()) * 100, 1),
                ];

                // Calculate student's rank if they have a result
                if ($this->examResult && $this->examResult->score) {
                    $higherScores = $scores->where('>', $this->examResult->score)->count();
                    $this->classStats['student_rank'] = $higherScores + 1;
                    $this->classStats['rank_suffix'] = $this->getRankSuffix($this->classStats['student_rank']);
                }
            } else {
                $this->classStats = [
                    'total_students' => 0,
                    'average_score' => 0,
                    'highest_score' => 0,
                    'lowest_score' => 0,
                    'passing_count' => 0,
                    'failing_count' => 0,
                    'pass_rate' => 0,
                ];
            }

        } catch (\Exception $e) {
            $this->examStats = [
                'has_result' => false,
                'score' => null,
                'max_score' => 100,
                'percentage' => null,
                'grade' => null,
                'remarks' => null,
                'is_passed' => null,
            ];

            $this->classStats = [
                'total_students' => 0,
                'average_score' => 0,
                'highest_score' => 0,
                'lowest_score' => 0,
                'passing_count' => 0,
                'failing_count' => 0,
                'pass_rate' => 0,
            ];
        }
    }

    protected function calculateGrade(?float $score): ?string
    {
        if ($score === null) return null;

        return match(true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F'
        };
    }

    protected function getRankSuffix(int $rank): string
    {
        if ($rank % 100 >= 11 && $rank % 100 <= 13) {
            return 'th';
        }

        return match($rank % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th'
        };
    }

    // Set active tab
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    // Navigation methods
    public function redirectToExams(): void
    {
        $this->redirect(route('student.exams.index'));
    }

    public function redirectToSubject(): void
    {
        // Redirect to subject details if available
        $this->redirect(route('student.enrollments.index', ['subject' => $this->exam->subject_id]));
    }

    // Helper functions
    public function getExamStatusColor(): string
    {
        $examDate = $this->exam->exam_date;

        if ($examDate->isFuture()) {
            return 'bg-blue-100 text-blue-800';
        } elseif ($this->examResult) {
            return 'bg-green-100 text-green-800';
        } else {
            return 'bg-yellow-100 text-yellow-800';
        }
    }

    public function getExamStatus(): string
    {
        $examDate = $this->exam->exam_date;

        if ($examDate->isFuture()) {
            return 'Upcoming';
        } elseif ($this->examResult) {
            return 'Graded';
        } else {
            return 'Pending Results';
        }
    }

    public function getScoreColor(?float $score): string
    {
        if ($score === null) return 'text-gray-600';

        return match(true) {
            $score >= 90 => 'text-green-600',
            $score >= 80 => 'text-blue-600',
            $score >= 70 => 'text-yellow-600',
            $score >= 60 => 'text-orange-600',
            default => 'text-red-600'
        };
    }

    public function getGradeColor(?string $grade): string
    {
        if ($grade === null) return 'bg-gray-100 text-gray-600';

        return match($grade) {
            'A' => 'bg-green-100 text-green-800',
            'B' => 'bg-blue-100 text-blue-800',
            'C' => 'bg-yellow-100 text-yellow-800',
            'D' => 'bg-orange-100 text-orange-800',
            'F' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function getTypeColor(string $type): string
    {
        return match($type) {
            'midterm' => 'bg-orange-100 text-orange-800',
            'final' => 'bg-red-100 text-red-800',
            'quiz' => 'bg-blue-100 text-blue-800',
            'assignment' => 'bg-green-100 text-green-800',
            'project' => 'bg-purple-100 text-purple-800',
            'practical' => 'bg-indigo-100 text-indigo-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function formatDate($date): string
    {
        try {
            return \Carbon\Carbon::parse($date)->format('M d, Y');
        } catch (\Exception $e) {
            return 'Date not available';
        }
    }

    public function isExamUpcoming(): bool
    {
        return $this->exam->exam_date->isFuture();
    }

    public function isExamToday(): bool
    {
        return $this->exam->exam_date->isToday();
    }

    public function getPerformanceMessage(): string
    {
        if (!$this->examResult || $this->examResult->score === null) {
            return 'Results not yet available';
        }

        $score = $this->examResult->score;
        $classAverage = $this->classStats['average_score'] ?? 0;

        if ($score >= 90) {
            return 'Excellent performance! 🌟';
        } elseif ($score >= 80) {
            return 'Great job! 👏';
        } elseif ($score >= 70) {
            return 'Good work! 👍';
        } elseif ($score >= 60) {
            return 'Keep improving! 📚';
        } else {
            return 'Need more practice 💪';
        }
    }

    public function with(): array
    {
        return [
            // Empty array - we use computed properties instead
        ];
    }
};?>
<div class="flex items-start">
                                    <x-icon name="o-academic-cap" class="w-4 h-4 text-blue-600 mr-2 mt-0.5" />
                                    <span class="text-blue-800">Ask your instructor for clarification</span>
                                </div>
                            </div>
                        </x-card>
                    @endif
                </div>
            </div>
        @endif

        <!-- Results Tab -->
        @if($activeTab === 'results')
            <div class="space-y-6">
                @if($examStats['has_result'])
                    <!-- Score Overview -->
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                        <x-card>
                            <div class="p-6 text-center">
                                <div class="text-4xl font-bold {{ $this->getScoreColor($examStats['score']) }} mb-2">
                                    {{ $examStats['score'] }}
                                </div>
                                <div class="text-sm text-gray-500">Your Score (out of {{ $examStats['max_score'] }})</div>
                                <div class="w-full h-3 mt-3 bg-gray-200 rounded-full">
                                    <div class="h-3 transition-all duration-500 bg-blue-600 rounded-full" style="width: {{ $examStats['percentage'] }}%"></div>
                                </div>
                                <div class="mt-1 text-xs text-gray-500">{{ $examStats['percentage'] }}%</div>
                            </div>
                        </x-card>

                        <x-card>
                            <div class="p-6 text-center">
                                <div class="mb-3">
                                    <span class="inline-flex items-center px-4 py-2 rounded-full text-2xl font-bold {{ $this->getGradeColor($examStats['grade']) }}">
                                        {{ $examStats['grade'] }}
                                    </span>
                                </div>
                                <div class="text-sm text-gray-500">Letter Grade</div>
                                <div class="text-sm {{ $examStats['is_passed'] ? 'text-green-600' : 'text-red-600' }} mt-2 font-medium">
                                    {{ $examStats['is_passed'] ? 'PASSED' : 'FAILED' }}
                                </div>
                            </div>
                        </x-card>

                        <x-card>
                            <div class="p-6 text-center">
                                @if(isset($classStats['student_rank']))
                                    <div class="mb-2 text-4xl font-bold text-blue-600">
                                        {{ $classStats['student_rank'] }}<sup class="text-xl">{{ $classStats['rank_suffix'] }}</sup>
                                    </div>
                                    <div class="text-sm text-gray-500">Class Rank</div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        out of {{ $classStats['total_students'] }} students
                                    </div>
                                @else
                                    <div class="mb-2 text-2xl font-bold text-gray-400">N/A</div>
                                    <div class="text-sm text-gray-500">Rank not available</div>
                                @endif
                            </div>
                        </x-card>
                    </div>

                    <!-- Performance Analysis -->
                    <x-card title="Performance Analysis">
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <!-- Your Performance -->
                            <div>
                                <h4 class="mb-4 font-medium text-gray-900">Your Performance</h4>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600">Score</span>
                                        <span class="font-medium">{{ $examStats['score'] }}/{{ $examStats['max_score'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600">Percentage</span>
                                        <span class="font-medium">{{ $examStats['percentage'] }}%</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600">Grade</span>
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium {{ $this->getGradeColor($examStats['grade']) }}">
                                            {{ $examStats['grade'] }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600">Status</span>
                                        <span class="font-medium {{ $examStats['is_passed'] ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $examStats['is_passed'] ? 'Passed' : 'Failed' }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Comparison with Class -->
                            <div>
                                <h4 class="mb-4 font-medium text-gray-900">Class Comparison</h4>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600">Your Score</span>
                                        <span class="font-medium text-blue-600">{{ $examStats['score'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600">Class Average</span>
                                        <span class="font-medium">{{ $classStats['average_score'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600">Highest Score</span>
                                        <span class="font-medium text-green-600">{{ $classStats['highest_score'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600">Your Rank</span>
                                        <span class="font-medium text-blue-600">
                                            {{ $classStats['student_rank'] ?? 'N/A' }}{{ isset($classStats['rank_suffix']) ? $classStats['rank_suffix'] : '' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </x-card>

                    <!-- Instructor Comments -->
                    @if($examStats['remarks'])
                        <x-card title="Instructor Comments">
                            <div class="p-4 rounded-lg bg-gray-50">
                                <div class="flex items-start">
                                    <x-icon name="o-chat-bubble-left-right" class="w-5 h-5 mt-1 mr-3 text-gray-500" />
                                    <div>
                                        <p class="text-sm text-gray-700">{{ $examStats['remarks'] }}</p>
                                        <p class="mt-2 text-xs text-gray-500">
                                            — {{ $exam->teacherProfile->user->name ?? 'Instructor' }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </x-card>
                    @endif

                    <!-- Next Steps -->
                    <x-card title="Next Steps" class="{{ $examStats['is_passed'] ? 'border-green-200 bg-green-50' : 'border-orange-200 bg-orange-50' }}">
                        <div class="space-y-3">
                            @if($examStats['is_passed'])
                                <div class="flex items-start">
                                    <x-icon name="o-check-circle" class="w-5 h-5 mt-1 mr-3 text-green-600" />
                                    <div>
                                        <h4 class="font-medium text-green-800">Congratulations!</h4>
                                        <p class="text-sm text-green-700">You've successfully passed this exam. Keep up the great work!</p>
                                    </div>
                                </div>
                                <ul class="ml-8 space-y-1 text-sm text-green-700">
                                    <li>• Continue with the next topics in your curriculum</li>
                                    <li>• Help classmates who might be struggling</li>
                                    <li>• Prepare for upcoming assessments</li>
                                </ul>
                            @else
                                <div class="flex items-start">
                                    <x-icon name="o-exclamation-triangle" class="w-5 h-5 mt-1 mr-3 text-orange-600" />
                                    <div>
                                        <h4 class="font-medium text-orange-800">Areas for Improvement</h4>
                                        <p class="text-sm text-orange-700">Don't worry! This is a learning opportunity to improve.</p>
                                    </div>
                                </div>
                                <ul class="ml-8 space-y-1 text-sm text-orange-700">
                                    <li>• Schedule a meeting with your instructor</li>
                                    <li>• Review the exam topics and study materials</li>
                                    <li>• Consider joining a study group</li>
                                    <li>• Check if retake options are available</li>
                                </ul>
                            @endif
                        </div>
                    </x-card>

                @else
                    <!-- No Results Available -->
                    <x-card>
                        <div class="py-12 text-center">
                            <x-icon name="o-clock" class="w-20 h-20 mx-auto mb-4 text-gray-300" />
                            <h3 class="mb-2 text-lg font-semibold text-gray-600">Results Not Available</h3>
                            <p class="mb-4 text-gray-500">
                                @if($this->isExamUpcoming())
                                    This exam hasn't taken place yet. Results will be available after the exam is completed and graded.
                                @else
                                    Your exam has been completed but results are still being processed. Please check back later.
                                @endif
                            </p>
                            <x-button
                                label="Back to Exams"
                                icon="o-arrow-left"
                                wire:click="redirectToExams"
                                class="btn-outline"
                            />
                        </div>
                    </x-card>
                @endif
            </div>
        @endif

        <!-- Statistics Tab -->
        @if($activeTab === 'statistics')
            <div class="space-y-6">
                <!-- Class Overview -->
                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <x-card>
                        <div class="p-6 text-center">
                            <div class="mb-2 text-3xl font-bold text-blue-600">{{ $classStats['total_students'] }}</div>
                            <div class="text-sm text-gray-500">Total Students</div>
                        </div>
                    </x-card>

                    <x-card>
                        <div class="p-6 text-center">
                            <div class="mb-2 text-3xl font-bold text-green-600">{{ $classStats['average_score'] }}</div>
                            <div class="text-sm text-gray-500">Class Average</div>
                        </div>
                    </x-card>

                    <x-card>
                        <div class="p-6 text-center">
                            <div class="mb-2 text-3xl font-bold text-purple-600">{{ $classStats['pass_rate'] }}%</div>
                            <div class="text-sm text-gray-500">Pass Rate</div>
                        </div>
                    </x-card>
                </div>

                <!-- Detailed Statistics -->
                <x-card title="Class Performance Statistics">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Score Distribution -->
                        <div>
                            <h4 class="mb-4 font-medium text-gray-900">Score Statistics</h4>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600">Highest Score</span>
                                    <span class="font-medium text-green-600">{{ $classStats['highest_score'] }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600">Class Average</span>
                                    <span class="font-medium">{{ $classStats['average_score'] }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600">Lowest Score</span>
                                    <span class="font-medium text-red-600">{{ $classStats['lowest_score'] }}</span>
                                </div>
                                @if($examStats['has_result'])
                                    <div class="flex items-center justify-between pt-2 border-t">
                                        <span class="text-sm text-gray-600">Your Score</span>
                                        <span class="font-medium text-blue-600">{{ $examStats['score'] }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Pass/Fail Distribution -->
                        <div>
                            <h4 class="mb-4 font-medium text-gray-900">Pass/Fail Distribution</h4>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600">Students Passed</span>
                                    <span class="font-medium text-green-600">{{ $classStats['passing_count'] }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600">Students Failed</span>
                                    <span class="font-medium text-red-600">{{ $classStats['failing_count'] }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600">Pass Rate</span>
                                    <span class="font-medium">{{ $classStats['pass_rate'] }}%</span>
                                </div>
                            </div>

                            <!-- Visual Pass Rate -->
                            <div class="mt-4">
                                <div class="flex items-center justify-between mb-2 text-sm text-gray-600">
                                    <span>Pass Rate</span>
                                    <span>{{ $classStats['pass_rate'] }}%</span>
                                </div>
                                <div class="w-full h-4 bg-gray-200 rounded-full">
                                    <div class="h-4 transition-all duration-500 bg-green-500 rounded-full" style="width: {{ $classStats['pass_rate'] }}%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-card>

                <!-- Performance Comparison -->
                @if($examStats['has_result'])
                    <x-card title="Your Performance vs Class">
                        <div class="space-y-6">
                            <!-- Score Comparison Chart -->
                            <div>
                                <h4 class="mb-4 font-medium text-gray-900">Score Comparison</h4>
                                <div class="relative">
                                    <div class="flex items-end justify-between h-32 border-b border-gray-200">
                                        <!-- Lowest Score -->
                                        <div class="flex flex-col items-center">
                                            <div class="bg-red-200 rounded-t" style="height: {{ ($classStats['lowest_score'] / 100) * 100 }}px; width: 40px;"></div>
                                            <div class="mt-2 text-xs text-gray-600">Lowest</div>
                                            <div class="text-sm font-medium">{{ $classStats['lowest_score'] }}</div>
                                        </div>

                                        <!-- Class Average -->
                                        <div class="flex flex-col items-center">
                                            <div class="bg-blue-200 rounded-t" style="height: {{ ($classStats['average_score'] / 100) * 100 }}px; width: 40px;"></div>
                                            <div class="mt-2 text-xs text-gray-600">Average</div>
                                            <div class="text-sm font-medium">{{ $classStats['average_score'] }}</div>
                                        </div>

                                        <!-- Your Score -->
                                        <div class="flex flex-col items-center">
                                            <div class="bg-purple-200 rounded-t" style="height: {{ ($examStats['score'] / 100) * 100 }}px; width: 40px;"></div>
                                            <div class="mt-2 text-xs text-gray-600">Your Score</div>
                                            <div class="text-sm font-medium text-purple-600">{{ $examStats['score'] }}</div>
                                        </div>

                                        <!-- Highest Score -->
                                        <div class="flex flex-col items-center">
                                            <div class="bg-green-200 rounded-t" style="height: {{ ($classStats['highest_score'] / 100) * 100 }}px; width: 40px;"></div>
                                            <div class="mt-2 text-xs text-gray-600">Highest</div>
                                            <div class="text-sm font-medium">{{ $classStats['highest_score'] }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Performance Insights -->
                            <div class="p-4 rounded-lg bg-gray-50">
                                <h5 class="mb-2 font-medium text-gray-900">Performance Insights</h5>
                                <div class="text-sm text-gray-700">
                                    @if($examStats['score'] > $classStats['average_score'])
                                        🎉 Excellent! You scored above the class average by {{ $examStats['score'] - $classStats['average_score'] }} points.
                                    @elseif($examStats['score'] == $classStats['average_score'])
                                        📊 You scored exactly at the class average. Well done!
                                    @else
                                        📈 You scored {{ $classStats['average_score'] - $examStats['score'] }} points below the class average. There's room for improvement!
                                    @endif
                                </div>
                            </div>
                        </div>
                    </x-card>
                @endif
            </div>
        @endif
    </div>
</div>
