<?php

use App\Models\Exam;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Exam Details')] class extends Component {
    use Toast;

    // Model instance
    public Exam $exam;

    // Activity logs
    public $activityLogs = [];

    // Children's results for this exam
    public $childrenResults = [];

    public function mount(Exam $exam): void
    {
        // Ensure the authenticated parent has children who took this exam
        $hasAccess = $exam->examResults()
            ->whereHas('childProfile', function ($query) {
                $query->where('parent_id', Auth::id());
            })
            ->exists();

        if (!$hasAccess) {
            abort(403, 'You do not have permission to view this exam.');
        }

        $this->exam = $exam->load([
            'subject',
            'teacherProfile.user',
            'academicYear',
            'examResults' => function ($query) {
                $query->whereHas('childProfile', function ($q) {
                    $q->where('parent_id', Auth::id());
                })->with('childProfile');
            }
        ]);

        // Load children's results
        $this->loadChildrenResults();

        // Load activity logs
        $this->loadActivityLogs();

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'view',
            "Viewed exam details: {$exam->title}",
            $exam,
            [
                'exam_title' => $exam->title,
                'exam_id' => $exam->id,
                'subject_name' => $exam->subject->name ?? 'Unknown',
                'exam_date' => $exam->exam_date?->format('Y-m-d'),
            ]
        );
    }

    // Load children's results for this exam
    protected function loadChildrenResults(): void
    {
        $this->childrenResults = $this->exam->examResults
            ->filter(function ($result) {
                return $result->childProfile && $result->childProfile->parent_id === Auth::id();
            })
            ->sortBy('childProfile.first_name');
    }

    // Load activity logs for this exam
    protected function loadActivityLogs(): void
    {
        try {
            $this->activityLogs = ActivityLog::with('user')
                ->where(function ($query) {
                    $query->where('subject_type', Exam::class)
                          ->where('subject_id', $this->exam->id);
                })
                ->orWhere(function ($query) {
                    $query->where('loggable_type', Exam::class)
                          ->where('loggable_id', $this->exam->id);
                })
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {
            $this->activityLogs = collect();
        }
    }

    // Helper function to get exam type color
    private function getExamTypeColor(string $type): string
    {
        return match($type) {
            'quiz' => 'bg-blue-100 text-blue-800',
            'test' => 'bg-green-100 text-green-800',
            'midterm' => 'bg-yellow-100 text-yellow-800',
            'final' => 'bg-red-100 text-red-800',
            'assignment' => 'bg-purple-100 text-purple-800',
            'project' => 'bg-indigo-100 text-indigo-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get score color
    private function getScoreColor(float $score): string
    {
        return match(true) {
            $score >= 90 => 'text-green-600',
            $score >= 80 => 'text-blue-600',
            $score >= 70 => 'text-yellow-600',
            default => 'text-red-600'
        };
    }

    // Helper function to get score grade
    private function getScoreGrade(float $score): string
    {
        return match(true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F'
        };
    }

    // Helper function to get performance level
    private function getPerformanceLevel(float $score): array
    {
        return match(true) {
            $score >= 90 => ['level' => 'Excellent', 'color' => 'text-green-600', 'bg' => 'bg-green-100'],
            $score >= 80 => ['level' => 'Good', 'color' => 'text-blue-600', 'bg' => 'bg-blue-100'],
            $score >= 70 => ['level' => 'Satisfactory', 'color' => 'text-yellow-600', 'bg' => 'bg-yellow-100'],
            default => ['level' => 'Needs Improvement', 'color' => 'text-red-600', 'bg' => 'bg-red-100']
        };
    }

    // Get activity icon
    public function getActivityIcon(string $action): string
    {
        return match($action) {
            'create' => 'o-plus',
            'update' => 'o-pencil',
            'view' => 'o-eye',
            'access' => 'o-arrow-right-on-rectangle',
            'grade' => 'o-star',
            'exam' => 'o-academic-cap',
            default => 'o-information-circle'
        };
    }

    // Get activity color
    public function getActivityColor(string $action): string
    {
        return match($action) {
            'create' => 'text-green-600',
            'update' => 'text-blue-600',
            'view' => 'text-gray-600',
            'access' => 'text-purple-600',
            'grade' => 'text-yellow-600',
            'exam' => 'text-indigo-600',
            default => 'text-gray-600'
        };
    }

    // Format date for display
    public function formatDate($date): string
    {
        if (!$date) {
            return 'Not set';
        }

        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }

        return $date->format('M d, Y');
    }

    // Calculate statistics
    public function getStatistics(): array
    {
        if ($this->childrenResults->isEmpty()) {
            return [
                'total_children' => 0,
                'average_score' => 0,
                'highest_score' => 0,
                'lowest_score' => 0,
                'passing_rate' => 0,
            ];
        }

        $scores = $this->childrenResults->pluck('score');
        $passingScores = $scores->where('>=', 70);

        return [
            'total_children' => $this->childrenResults->count(),
            'average_score' => round($scores->avg(), 1),
            'highest_score' => $scores->max(),
            'lowest_score' => $scores->min(),
            'passing_rate' => $scores->count() > 0 ? round(($passingScores->count() / $scores->count()) * 100, 1) : 0,
        ];
    }

    public function with(): array
    {
        return [
            'statistics' => $this->getStatistics(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Exam: {{ $exam->title }}" separator>
        <x-slot:middle class="!justify-end">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getExamTypeColor($exam->type) }}">
                {{ ucfirst($exam->type) }}
            </span>
            @if($exam->exam_date && $exam->exam_date > now())
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium text-orange-800 bg-orange-100 rounded-full">
                    Upcoming
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Back to Exams"
                icon="o-arrow-left"
                link="{{ route('parent.exams.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Exam Details -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Exam Information -->
            <x-card title="Exam Information">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Exam Title</div>
                        <div class="text-lg font-semibold">{{ $exam->title }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject</div>
                        <div class="text-lg">{{ $exam->subject->name ?? 'Unknown Subject' }}</div>
                        @if($exam->subject && $exam->subject->code)
                            <div class="text-sm text-gray-500">{{ $exam->subject->code }}</div>
                        @endif
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Exam Date</div>
                        <div class="text-lg">{{ $this->formatDate($exam->exam_date) }}</div>
                        @if($exam->exam_date)
                            <div class="text-sm text-gray-500">{{ $exam->exam_date->format('l') }}</div>
                        @endif
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Exam Type</div>
                        <div class="mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium {{ $this->getExamTypeColor($exam->type) }}">
                                {{ ucfirst($exam->type) }}
                            </span>
                        </div>
                    </div>

                    @if($exam->teacherProfile && $exam->teacherProfile->user)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Teacher</div>
                            <div class="text-lg">{{ $exam->teacherProfile->user->name }}</div>
                        </div>
                    @endif

                    @if($exam->academicYear)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Academic Year</div>
                            <div class="text-lg">{{ $exam->academicYear->name }}</div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Children's Results -->
            @if($childrenResults->count() > 0)
                <x-card title="Your Children's Results">
                    <div class="space-y-4">
                        @foreach($childrenResults as $result)
                            @php
                                $performance = $this->getPerformanceLevel($result->score);
                            @endphp
                            <div class="p-4 border rounded-lg {{ $performance['bg'] }} border-opacity-20">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center">
                                        <div class="mr-4 avatar placeholder">
                                            <div class="w-12 h-12 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                                <span class="text-sm font-bold">{{ $result->childProfile->initials ?? '??' }}</span>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-lg font-semibold">{{ $result->childProfile->full_name ?? 'Unknown Child' }}</div>
                                            @if($result->childProfile && $result->childProfile->age)
                                                <div class="text-sm text-gray-500">{{ $result->childProfile->age }} years old</div>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="text-right">
                                        <div class="text-2xl font-bold {{ $this->getScoreColor($result->score) }}">
                                            {{ number_format($result->score, 1) }}%
                                        </div>
                                        <div class="text-sm {{ $performance['color'] }}">
                                            Grade {{ $this->getScoreGrade($result->score) }} - {{ $performance['level'] }}
                                        </div>
                                    </div>
                                </div>

                                @if($result->remarks)
                                    <div class="pt-4 mt-4 border-t border-gray-200">
                                        <div class="mb-1 text-sm font-medium text-gray-700">Teacher's Remarks:</div>
                                        <div class="p-3 text-sm text-gray-600 bg-white border rounded-md">
                                            {{ $result->remarks }}
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @else
                <x-card title="Results">
                    <div class="py-8 text-center">
                        <x-icon name="o-document-text" class="w-12 h-12 mx-auto mb-4 text-gray-300" />
                        <div class="text-lg font-medium text-gray-600">No results available</div>
                        <div class="text-sm text-gray-500">
                            @if($exam->exam_date && $exam->exam_date > now())
                                This exam hasn't been taken yet.
                            @else
                                Results haven't been published for your children yet.
                            @endif
                        </div>
                    </div>
                </x-card>
            @endif

            <!-- Subject Information -->
            @if($exam->subject && $exam->subject->description)
                <x-card title="Subject Information">
                    <div class="p-4 rounded-md bg-gray-50">
                        <div class="mb-2 font-medium">{{ $exam->subject->name }}</div>
                        <p class="text-sm text-gray-600">{{ $exam->subject->description }}</p>
                        @if($exam->subject->code)
                            <div class="mt-2 text-xs text-gray-500">Subject Code: {{ $exam->subject->code }}</div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Activity Log -->
            @if($activityLogs->count() > 0)
                <x-card title="Recent Activity">
                    <div class="space-y-4 overflow-y-auto max-h-96">
                        @foreach($activityLogs as $log)
                            <div class="flex items-start pb-4 space-x-4 border-b border-gray-100 last:border-b-0">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center w-8 h-8 bg-gray-100 rounded-full">
                                        <x-icon name="{{ $this->getActivityIcon($log->action ?? $log->activity_type) }}" class="w-4 h-4 {{ $this->getActivityColor($log->action ?? $log->activity_type) }}" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm">
                                        <span class="font-medium text-gray-900">
                                            {{ $log->user ? $log->user->name : 'System' }}
                                        </span>
                                        <span class="text-gray-600">{{ $log->description ?? $log->activity_description }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $log->created_at->diffForHumans() }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Right column (1/3) - Statistics and Actions -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-3">
                    <x-button
                        label="View All Exams"
                        icon="o-academic-cap"
                        link="{{ route('parent.exams.index') }}"
                        class="w-full btn-outline"
                    />

                    @if($exam->subject)
                        <x-button
                            label="Other {{ $exam->subject->name }} Exams"
                            icon="o-book-open"
                            link="{{ route('parent.exams.index', ['subject' => $exam->subject->id]) }}"
                            class="w-full btn-outline"
                        />
                    @endif

                    @if($childrenResults->isNotEmpty())
                        @php
                            $firstChild = $childrenResults->first()->childProfile;
                        @endphp
                        <x-button
                            label="View Child's Profile"
                            icon="o-user"
                            link="{{ route('parent.children.show', $firstChild->id) }}"
                            class="w-full btn-outline"
                        />

                        <x-button
                            label="View Child's Attendance"
                            icon="o-calendar"
                            link="{{ route('parent.attendance.index', ['child' => $firstChild->id]) }}"
                            class="w-full btn-outline"
                        />
                    @endif
                </div>
            </x-card>

            <!-- Statistics -->
            @if($statistics['total_children'] > 0)
                <x-card title="Performance Statistics">
                    <div class="space-y-4">
                        <div class="p-4 text-center rounded-lg bg-gray-50">
                            <div class="text-2xl font-bold {{ $this->getScoreColor($statistics['average_score']) }}">
                                {{ $statistics['average_score'] }}%
                            </div>
                            <div class="text-sm text-gray-500">Average Score</div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="text-center">
                                <div class="text-lg font-bold text-green-600">{{ $statistics['highest_score'] }}%</div>
                                <div class="text-xs text-gray-500">Highest</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-red-600">{{ $statistics['lowest_score'] }}%</div>
                                <div class="text-xs text-gray-500">Lowest</div>
                            </div>
                        </div>

                        <div class="pt-4 border-t">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm text-gray-600">Children Tested</span>
                                <span class="font-semibold">{{ $statistics['total_children'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Passing Rate (70%+)</span>
                                <span class="font-semibold {{ $statistics['passing_rate'] >= 80 ? 'text-green-600' : ($statistics['passing_rate'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                    {{ $statistics['passing_rate'] }}%
                                </span>
                            </div>
                        </div>
                    </div>
                </x-card>
            @endif

            <!-- Exam Status -->
            <x-card title="Exam Status">
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Status</span>
                        <span class="font-semibold">
                            @if($exam->exam_date && $exam->exam_date > now())
                                <span class="text-orange-600">Upcoming</span>
                            @elseif($childrenResults->count() > 0)
                                <span class="text-green-600">Results Available</span>
                            @else
                                <span class="text-blue-600">Completed</span>
                            @endif
                        </span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Exam Date</span>
                        <span class="font-semibold">{{ $this->formatDate($exam->exam_date) }}</span>
                    </div>

                    @if($exam->exam_date)
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">
                                @if($exam->exam_date > now())
                                    Time Until Exam
                                @else
                                    Time Since Exam
                                @endif
                            </span>
                            <span class="font-semibold">{{ $exam->exam_date->diffForHumans() }}</span>
                        </div>
                    @endif

                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Results Published</span>
                        <span class="font-semibold">
                            @if($childrenResults->count() > 0)
                                <span class="text-green-600">Yes</span>
                            @else
                                <span class="text-gray-600">Not yet</span>
                            @endif
                        </span>
                    </div>
                </div>
            </x-card>

            <!-- System Information -->
            <x-card title="System Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Exam ID</div>
                        <div class="font-mono text-xs">{{ $exam->id }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $exam->created_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $exam->updated_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>

                    @if($exam->teacherProfile && $exam->teacherProfile->user)
                        <div>
                            <div class="font-medium text-gray-500">Created By</div>
                            <div>{{ $exam->teacherProfile->user->name }}</div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Performance Tips -->
            @if($childrenResults->count() > 0)
                @php
                    $averageScore = $statistics['average_score'];
                @endphp
                <x-card title="Performance Tips" class="border-blue-200 bg-blue-50">
                    <div class="space-y-3 text-sm">
                        @if($averageScore >= 90)
                            <div class="flex items-start">
                                <x-icon name="o-star" class="w-5 h-5 text-yellow-500 mr-2 mt-0.5" />
                                <div>
                                    <div class="font-semibold text-blue-800">Excellent Performance!</div>
                                    <p class="text-blue-700">Your child is performing exceptionally well. Keep up the great work and continue to challenge them.</p>
                                </div>
                            </div>
                        @elseif($averageScore >= 80)
                            <div class="flex items-start">
                                <x-icon name="o-thumbs-up" class="w-5 h-5 text-green-500 mr-2 mt-0.5" />
                                <div>
                                    <div class="font-semibold text-blue-800">Good Performance</div>
                                    <p class="text-blue-700">Your child is doing well. Consider reviewing challenging topics to reach excellence.</p>
                                </div>
                            </div>
                        @elseif($averageScore >= 70)
                            <div class="flex items-start">
                                <x-icon name="o-light-bulb" class="w-5 h-5 text-yellow-500 mr-2 mt-0.5" />
                                <div>
                                    <div class="font-semibold text-blue-800">Room for Improvement</div>
                                    <p class="text-blue-700">Consider additional study time and practice in areas where your child struggles.</p>
                                </div>
                            </div>
                        @else
                            <div class="flex items-start">
                                <x-icon name="o-academic-cap" class="w-5 h-5 text-red-500 mr-2 mt-0.5" />
                                <div>
                                    <div class="font-semibold text-blue-800">Needs Support</div>
                                    <p class="text-blue-700">Consider meeting with the teacher to discuss additional support and study strategies.</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>
