<?php

use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\Session;
use App\Models\Exam;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Subject Details')] class extends Component {
    use Toast;

    // Model instances
    public Subject $subject;
    public ?TeacherProfile $teacherProfile = null;

    // Data collections
    public $upcomingSessions = [];
    public $recentSessions = [];
    public $upcomingExams = [];
    public $recentExams = [];

    // Stats
    public array $stats = [];

    // Mount the component
    public function mount(Subject $subject): void
    {
        $this->subject = $subject->load(['curriculum', 'sessions', 'exams', 'teachers']);
        $this->teacherProfile = Auth::user()->teacherProfile;

        if (!$this->teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            $this->redirect(route('teacher.profile.edit'));
            return;
        }

        // Check if teacher is assigned to this subject
        if (!$this->subject->teachers->contains($this->teacherProfile)) {
            $this->error('You are not assigned to teach this subject.');
            $this->redirect(route('teacher.subjects.index'));
            return;
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed subject details: {$subject->name} ({$subject->code})",
            Subject::class,
            $subject->id,
            [
                'subject_name' => $subject->name,
                'subject_code' => $subject->code,
                'ip' => request()->ip()
            ]
        );

        $this->loadData();
        $this->loadStats();
    }

    protected function loadData(): void
    {
        try {
            // Load upcoming sessions
            $this->upcomingSessions = Session::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->where('start_time', '>', now())
                ->orderBy('start_time', 'asc')
                ->limit(5)
                ->get();

            // Load recent sessions
            $this->recentSessions = Session::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->where('start_time', '<=', now())
                ->orderBy('start_time', 'desc')
                ->limit(5)
                ->get();

            // Load upcoming exams
            $this->upcomingExams = Exam::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->where('exam_date', '>', now())
                ->orderBy('exam_date', 'asc')
                ->limit(5)
                ->get();

            // Load recent exams
            $this->recentExams = Exam::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->where('exam_date', '<=', now())
                ->orderBy('exam_date', 'desc')
                ->limit(5)
                ->get();

        } catch (\Exception $e) {
            Log::error('Failed to load subject data', [
                'subject_id' => $this->subject->id,
                'teacher_profile_id' => $this->teacherProfile->id,
                'error' => $e->getMessage()
            ]);

            $this->upcomingSessions = collect();
            $this->recentSessions = collect();
            $this->upcomingExams = collect();
            $this->recentExams = collect();
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalSessions = Session::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->count();

            $completedSessions = Session::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->where('end_time', '<', now())
                ->count();

            $upcomingSessionsCount = Session::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->where('start_time', '>', now())
                ->count();

            $totalExams = Exam::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->count();

            $completedExams = Exam::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->where('exam_date', '<', now())
                ->count();

            $upcomingExamsCount = Exam::where('subject_id', $this->subject->id)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->where('exam_date', '>', now())
                ->count();

            $this->stats = [
                'total_sessions' => $totalSessions,
                'completed_sessions' => $completedSessions,
                'upcoming_sessions' => $upcomingSessionsCount,
                'total_exams' => $totalExams,
                'completed_exams' => $completedExams,
                'upcoming_exams' => $upcomingExamsCount,
            ];

        } catch (\Exception $e) {
            $this->stats = [
                'total_sessions' => 0,
                'completed_sessions' => 0,
                'upcoming_sessions' => 0,
                'total_exams' => 0,
                'completed_exams' => 0,
                'upcoming_exams' => 0,
            ];
        }
    }

    // Navigation methods
    public function redirectToSessions(): void
    {
        $this->redirect(route('teacher.sessions.index', ['subject' => $this->subject->id]));
    }

    public function redirectToCreateSession(): void
    {
        $this->redirect(route('teacher.sessions.create', ['subject' => $this->subject->id]));
    }

    public function redirectToExams(): void
    {
        $this->redirect(route('teacher.exams.index', ['subject' => $this->subject->id]));
    }

    public function redirectToCreateExam(): void
    {
        $this->redirect(route('teacher.exams.create', ['subject' => $this->subject->id]));
    }

    public function redirectToSessionShow(int $sessionId): void
    {
        $this->redirect(route('teacher.sessions.show', $sessionId));
    }

    public function redirectToExamShow(int $examId): void
    {
        $this->redirect(route('teacher.exams.show', $examId));
    }

    // Format session type
    public function getSessionTypeColor(string $type): string
    {
        return match(strtolower($type)) {
            'lecture' => 'bg-blue-100 text-blue-800',
            'practical' => 'bg-green-100 text-green-800',
            'tutorial' => 'bg-purple-100 text-purple-800',
            'lab' => 'bg-orange-100 text-orange-800',
            'seminar' => 'bg-indigo-100 text-indigo-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Format exam type
    public function getExamTypeColor(string $type): string
    {
        return match(strtolower($type)) {
            'midterm' => 'bg-yellow-100 text-yellow-800',
            'final' => 'bg-red-100 text-red-800',
            'quiz' => 'bg-green-100 text-green-800',
            'assignment' => 'bg-blue-100 text-blue-800',
            'project' => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function with(): array
    {
        return [
            // Empty array - we use computed properties instead
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Subject: {{ $subject->name }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Subject Info -->
            <div class="text-right">
                <div class="text-sm font-medium">{{ $subject->code }}</div>
                @if($subject->level)
                    <div class="text-xs text-gray-500">Level {{ $subject->level }}</div>
                @endif
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Create Session"
                icon="o-plus"
                wire:click="redirectToCreateSession"
                class="btn-primary"
            />
            <x-button
                label="Create Exam"
                icon="o-document-plus"
                wire:click="redirectToCreateExam"
                class="btn-secondary"
            />
            <x-button
                label="Back to Subjects"
                icon="o-arrow-left"
                link="{{ route('teacher.subjects.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Main Content -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Subject Information -->
            <x-card title="Subject Information">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject Name</div>
                        <div class="text-lg font-semibold">{{ $subject->name }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject Code</div>
                        <div class="font-mono">{{ $subject->code }}</div>
                    </div>

                    @if($subject->level)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Level</div>
                            <div>Level {{ $subject->level }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="text-sm font-medium text-gray-500">Curriculum</div>
                        <div>{{ $subject->curriculum ? $subject->curriculum->name : 'Not assigned' }}</div>
                    </div>
                </div>

                @if($subject->curriculum && $subject->curriculum->description)
                    <div class="pt-4 mt-4 border-t">
                        <div class="mb-2 text-sm font-medium text-gray-500">Curriculum Description</div>
                        <div class="p-3 text-sm text-gray-600 rounded-md bg-gray-50">
                            {{ $subject->curriculum->description }}
                        </div>
                    </div>
                @endif
            </x-card>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-card>
                    <div class="p-4 text-center">
                        <div class="text-2xl font-bold text-blue-600">{{ $stats['total_sessions'] }}</div>
                        <div class="text-sm text-gray-500">Total Sessions</div>
                        <div class="mt-2 text-xs text-gray-400">
                            {{ $stats['completed_sessions'] }} completed, {{ $stats['upcoming_sessions'] }} upcoming
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <div class="p-4 text-center">
                        <div class="text-2xl font-bold text-purple-600">{{ $stats['total_exams'] }}</div>
                        <div class="text-sm text-gray-500">Total Exams</div>
                        <div class="mt-2 text-xs text-gray-400">
                            {{ $stats['completed_exams'] }} completed, {{ $stats['upcoming_exams'] }} upcoming
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <div class="p-4 text-center">
                        <div class="text-2xl font-bold text-green-600">{{ $subject->teachers->count() }}</div>
                        <div class="text-sm text-gray-500">Assigned Teachers</div>
                        <div class="mt-2 text-xs text-gray-400">
                            Including yourself
                        </div>
                    </div>
                </x-card>
            </div>

            <!-- Upcoming Sessions -->
            <x-card title="Upcoming Sessions">
                @if($upcomingSessions->count() > 0)
                    <div class="space-y-4">
                        @foreach($upcomingSessions as $session)
                            <div class="flex items-center justify-between p-4 border rounded-lg hover:bg-gray-50">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <div>
                                            <button
                                                wire:click="redirectToSessionShow({{ $session->id }})"
                                                class="font-medium text-blue-600 hover:text-blue-800 hover:underline"
                                            >
                                                Session #{{ $session->id }}
                                            </button>
                                            <div class="text-sm text-gray-500">
                                                {{ $session->start_time->format('M d, Y \a\t g:i A') }}
                                                @if($session->end_time)
                                                    - {{ $session->end_time->format('g:i A') }}
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3">
                                    @if($session->type)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getSessionTypeColor($session->type) }}">
                                            {{ ucfirst($session->type) }}
                                        </span>
                                    @endif
                                    <div class="text-xs text-gray-400">
                                        {{ $session->start_time->diffForHumans() }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 text-center">
                        <x-button
                            label="View All Sessions"
                            icon="o-eye"
                            wire:click="redirectToSessions"
                            class="btn-outline btn-sm"
                        />
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-calendar" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No upcoming sessions</div>
                        <x-button
                            label="Create Session"
                            icon="o-plus"
                            wire:click="redirectToCreateSession"
                            class="mt-2 btn-primary btn-sm"
                        />
                    </div>
                @endif
            </x-card>

            <!-- Upcoming Exams -->
            <x-card title="Upcoming Exams">
                @if($upcomingExams->count() > 0)
                    <div class="space-y-4">
                        @foreach($upcomingExams as $exam)
                            <div class="flex items-center justify-between p-4 border rounded-lg hover:bg-gray-50">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <div>
                                            <button
                                                wire:click="redirectToExamShow({{ $exam->id }})"
                                                class="font-medium text-blue-600 hover:text-blue-800 hover:underline"
                                            >
                                                {{ $exam->title }}
                                            </button>
                                            <div class="text-sm text-gray-500">
                                                {{ $exam->exam_date->format('M d, Y') }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3">
                                    @if($exam->type)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getExamTypeColor($exam->type) }}">
                                            {{ ucfirst($exam->type) }}
                                        </span>
                                    @endif
                                    <div class="text-xs text-gray-400">
                                        {{ $exam->exam_date->diffForHumans() }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 text-center">
                        <x-button
                            label="View All Exams"
                            icon="o-eye"
                            wire:click="redirectToExams"
                            class="btn-outline btn-sm"
                        />
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-document-text" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No upcoming exams</div>
                        <x-button
                            label="Create Exam"
                            icon="o-plus"
                            wire:click="redirectToCreateExam"
                            class="mt-2 btn-secondary btn-sm"
                        />
                    </div>
                @endif
            </x-card>
        </div>

        <!-- Right column (1/3) - Sidebar -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-3">
                    <x-button
                        label="Create Session"
                        icon="o-plus"
                        wire:click="redirectToCreateSession"
                        class="w-full btn-primary"
                    />
                    <x-button
                        label="Create Exam"
                        icon="o-document-plus"
                        wire:click="redirectToCreateExam"
                        class="w-full btn-secondary"
                    />
                    <x-button
                        label="View All Sessions"
                        icon="o-presentation-chart-line"
                        wire:click="redirectToSessions"
                        class="w-full btn-outline"
                    />
                    <x-button
                        label="View All Exams"
                        icon="o-document-text"
                        wire:click="redirectToExams"
                        class="w-full btn-outline"
                    />
                </div>
            </x-card>

            <!-- Recent Activity -->
            <x-card title="Recent Sessions">
                @if($recentSessions->count() > 0)
                    <div class="space-y-3">
                        @foreach($recentSessions as $session)
                            <div class="flex items-center justify-between p-3 border rounded-md bg-gray-50">
                                <div>
                                    <button
                                        wire:click="redirectToSessionShow({{ $session->id }})"
                                        class="text-sm font-medium text-blue-600 hover:text-blue-800 hover:underline"
                                    >
                                        Session #{{ $session->id }}
                                    </button>
                                    <div class="text-xs text-gray-500">
                                        {{ $session->start_time->format('M d, Y') }}
                                    </div>
                                </div>
                                @if($session->type)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getSessionTypeColor($session->type) }}">
                                        {{ ucfirst($session->type) }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-4 text-center">
                        <div class="text-sm text-gray-500">No recent sessions</div>
                    </div>
                @endif
            </x-card>

            <!-- Recent Exams -->
            <x-card title="Recent Exams">
                @if($recentExams->count() > 0)
                    <div class="space-y-3">
                        @foreach($recentExams as $exam)
                            <div class="flex items-center justify-between p-3 border rounded-md bg-gray-50">
                                <div>
                                    <button
                                        wire:click="redirectToExamShow({{ $exam->id }})"
                                        class="text-sm font-medium text-blue-600 hover:text-blue-800 hover:underline"
                                    >
                                        {{ Str::limit($exam->title, 20) }}
                                    </button>
                                    <div class="text-xs text-gray-500">
                                        {{ $exam->exam_date->format('M d, Y') }}
                                    </div>
                                </div>
                                @if($exam->type)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getExamTypeColor($exam->type) }}">
                                        {{ ucfirst($exam->type) }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-4 text-center">
                        <div class="text-sm text-gray-500">No recent exams</div>
                    </div>
                @endif
            </x-card>

            <!-- Other Teachers -->
            @if($subject->teachers->count() > 1)
                <x-card title="Other Teachers">
                    <div class="space-y-3">
                        @foreach($subject->teachers as $teacher)
                            @if($teacher->id !== $teacherProfile->id)
                                <div class="flex items-center space-x-3">
                                    <div class="avatar">
                                        <div class="w-8 h-8 rounded-full">
                                            <img src="{{ $teacher->user->profile_photo_url }}" alt="{{ $teacher->user->name }}" />
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium">{{ $teacher->user->name }}</div>
                                        @if($teacher->specialization)
                                            <div class="text-xs text-gray-500">{{ $teacher->specialization }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </x-card>
            @endif

            <!-- Subject Resources -->
            <x-card title="Resources & Links">
                <div class="space-y-2">
                    <x-button
                        label="Attendance Records"
                        icon="o-clipboard-document-check"
                        link="{{ route('teacher.attendance.index', ['subject' => $subject->id]) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Exam Results"
                        icon="o-chart-bar"
                        link="{{ route('teacher.exams.index', ['subject' => $subject->id]) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Subject Timetable"
                        icon="o-calendar-days"
                        href="#"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>

            <!-- Subject Statistics -->
            <x-card title="Statistics">
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Total Sessions:</span>
                        <span class="font-medium">{{ $stats['total_sessions'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Completed Sessions:</span>
                        <span class="font-medium text-green-600">{{ $stats['completed_sessions'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Upcoming Sessions:</span>
                        <span class="font-medium text-blue-600">{{ $stats['upcoming_sessions'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Total Exams:</span>
                        <span class="font-medium">{{ $stats['total_exams'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Completed Exams:</span>
                        <span class="font-medium text-green-600">{{ $stats['completed_exams'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Upcoming Exams:</span>
                        <span class="font-medium text-purple-600">{{ $stats['upcoming_exams'] }}</span>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
