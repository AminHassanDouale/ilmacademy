<?php

use App\Models\Exam;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Exam Details')] class extends Component {
    use Toast;

    // Exam model
    public Exam $exam;

    // Stats and counters
    public $totalStudents = 0;
    public $attendedStudents = 0;
    public $passedStudents = 0;
    public $failedStudents = 0;
    public $averageScore = 0;

    // Exam status
    public $isUpcoming = false;
    public $isToday = false;
    public $isCompleted = false;
    public $canEdit = false;
    public $needsGrading = false;

    public function mount(Exam $exam): void
    {
        $this->exam = $exam;

        // Load the exam with relationships
        $this->exam->load(['subject', 'teacherProfile.user']);

        // Check if current teacher is the owner of this exam
        $teacherProfile = Auth::user()->teacherProfile;
        $isOwner = $teacherProfile && $teacherProfile->id === $this->exam->teacher_profile_id;

        if (!$isOwner) {
            $this->error('You do not have permission to view this exam.');
            redirect()->route('teacher.exams.index');
            return;
        }

        // Calculate exam status
        $this->calculateExamStatus();

        // Set permissions based on ownership and status
        $this->canEdit = $isOwner && $this->isUpcoming;

        // Load exam statistics
        $this->loadExamStats();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher viewed exam details: ' . $this->exam->title,
            Exam::class,
            $this->exam->id,
            ['ip' => request()->ip()]
        );
    }

    // Calculate if exam is upcoming, today, or completed
    private function calculateExamStatus(): void
    {
        $now = Carbon::now();
        $examDate = Carbon::parse($this->exam->date);

        $this->isUpcoming = $examDate->isAfter($now->startOfDay());
        $this->isToday = $examDate->isSameDay($now);
        $this->isCompleted = $examDate->isBefore($now->startOfDay());
        $this->needsGrading = $this->isCompleted && !$this->exam->is_graded;
    }

    // Load exam statistics
    private function loadExamStats(): void
    {
        try {
            // Get enrollment count for the subject
            if (method_exists($this->exam->subject, 'enrolledStudents')) {
                $this->totalStudents = $this->exam->subject->enrolledStudents()->count();
            }

            // If exam is completed and has results
            if ($this->isCompleted && method_exists($this->exam, 'results')) {
                $examResults = $this->exam->results()->get();

                $this->attendedStudents = $examResults->count();
                $this->passedStudents = $examResults->where('score', '>=', $this->exam->passing_mark)->count();
                $this->failedStudents = $examResults->where('score', '<', $this->exam->passing_mark)->count();

                // Calculate average score
                if ($this->attendedStudents > 0) {
                    $this->averageScore = round($examResults->avg('score'));
                }
            }
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading exam statistics: ' . $e->getMessage(),
                Exam::class,
                $this->exam->id,
                ['ip' => request()->ip()]
            );
        }
    }

    // Edit exam
    public function editExam(): void
    {
        if ($this->canEdit) {
            redirect()->route('teacher.exams.edit', $this->exam->id);
        } else {
            $this->error('You cannot edit this exam as it has already started or completed.');
        }
    }

    // Grade exam
    public function gradeExam(): void
    {
        if ($this->isCompleted) {
            redirect()->route('teacher.exams.grade', $this->exam->id);
        } else {
            $this->error('You cannot grade this exam as it has not been completed yet.');
        }
    }

    // View results
    public function viewResults(): void
    {
        if ($this->isCompleted && $this->exam->is_graded) {
            redirect()->route('teacher.exams.results', $this->exam->id);
        } else {
            $this->error('Exam results are not available yet.');
        }
    }

    // Download attachment
    public function downloadAttachment(): void
    {
        if ($this->exam->attachment) {
            // Redirect to download URL
            redirect(Storage::url($this->exam->attachment));
        } else {
            $this->error('No attachment available for this exam.');
        }
    }

    // Format date in d/m/Y format
    public function formatDate($date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    // Get exam students
    public function examStudents()
    {
        if (!method_exists($this->exam->subject, 'enrolledStudents')) {
            return collect();
        }

        try {
            $students = $this->exam->subject->enrolledStudents()
                ->with(['childProfile.user', 'program'])
                ->take(10)
                ->get();

            // Add result data if available
            if (method_exists($this->exam, 'results') && $this->exam->is_graded) {
                $results = $this->exam->results()->get()->keyBy('child_profile_id');

                foreach ($students as $student) {
                    $profileId = $student->childProfile->id;
                    $student->result = isset($results[$profileId]) ? $results[$profileId] : null;
                }
            }

            return $students;
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading exam students: ' . $e->getMessage(),
                Exam::class,
                $this->exam->id,
                ['ip' => request()->ip()]
            );

            return collect();
        }
    }
};
?>

<div>
    <!-- Page header -->
    <x-header :title="$exam->title" separator progress-indicator>
        <x-slot:subtitle>
            {{ $exam->subject->name ?? 'Unknown Subject' }} | {{ formatDate($exam->date) }}
        </x-slot:subtitle>

        <x-slot:actions>
            <div class="flex gap-2">
                @if ($canEdit)
                    <x-button
                        label="Edit Exam"
                        icon="o-pencil-square"
                        wire:click="editExam"
                        class="btn-primary"
                    />
                @endif

                @if ($needsGrading)
                    <x-button
                        label="Grade Exam"
                        icon="o-academic-cap"
                        wire:click="gradeExam"
                        class="btn-error"
                    />
                @endif

                @if ($isCompleted && $exam->is_graded)
                    <x-button
                        label="View Results"
                        icon="o-chart-bar"
                        wire:click="viewResults"
                        class="btn-secondary"
                    />
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Exam Status -->
    <div class="mb-6">
        @if ($needsGrading)
            <div class="p-4 shadow-lg alert bg-error text-error-content">
                <div>
                    <x-icon name="o-exclamation-triangle" class="w-6 h-6" />
                    <span>This exam needs to be graded. Please review and enter student scores.</span>
                </div>
            </div>
        @elseif ($isToday)
            <div class="p-4 shadow-lg alert bg-warning text-warning-content">
                <div>
                    <x-icon name="o-clock" class="w-6 h-6" />
                    <span>This exam is scheduled for today{{ $exam->time ? ' at ' . $exam->time : '' }}.</span>
                </div>
            </div>
        @elseif ($isUpcoming)
            <div class="p-4 shadow-lg alert bg-info text-info-content">
                <div>
                    <x-icon name="o-calendar" class="w-6 h-6" />
                    <span>This exam is scheduled for {{ formatDate($exam->date) }}{{ $exam->time ? ' at ' . $exam->time : '' }}.</span>
                </div>
            </div>
        @elseif ($isCompleted && $exam->is_graded)
            <div class="p-4 shadow-lg alert bg-success text-success-content">
                <div>
                    <x-icon name="o-check-circle" class="w-6 h-6" />
                    <span>This exam has been completed and graded.</span>
                </div>
            </div>
        @elseif ($isCompleted)
            <div class="p-4 shadow-lg alert bg-secondary text-secondary-content">
                <div>
                    <x-icon name="o-document-check" class="w-6 h-6" />
                    <span>This exam has been completed but not yet graded.</span>
                </div>
            </div>
        @endif
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-5">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-users" class="w-8 h-8" />
            </div>
            <div class="stat-title">Students</div>
            <div class="stat-value">{{ $totalStudents }}</div>
            <div class="stat-desc">Enrolled</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-clipboard-document-check" class="w-8 h-8" />
            </div>
            <div class="stat-title">Attended</div>
            <div class="stat-value text-info">{{ $attendedStudents }}</div>
            <div class="stat-desc">{{ $totalStudents > 0 ? round(($attendedStudents / $totalStudents) * 100) : 0 }}%</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Passed</div>
            <div class="stat-value text-success">{{ $passedStudents }}</div>
            <div class="stat-desc">{{ $attendedStudents > 0 ? round(($passedStudents / $attendedStudents) * 100) : 0 }}%</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-error">
                <x-icon name="o-x-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Failed</div>
            <div class="stat-value text-error">{{ $failedStudents }}</div>
            <div class="stat-desc">{{ $attendedStudents > 0 ? round(($failedStudents / $attendedStudents) * 100) : 0 }}%</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-secondary">
                <x-icon name="o-chart-bar" class="w-8 h-8" />
            </div>
            <div class="stat-title">Average</div>
            <div class="stat-value text-secondary">{{ $averageScore }}</div>
            <div class="stat-desc">Out of {{ $exam->total_marks }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
        <!-- Exam Details -->
        <div class="md:col-span-2">
            <x-card title="Exam Details">
                <div class="divide-y divide-base-300">
                    <div class="grid grid-cols-1 gap-4 py-3 md:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Type</h3>
                            <p class="mt-1">
                                <x-badge
                                    label="{{ ucfirst($exam->type) }}"
                                    color="{{ match($exam->type) {
                                        'quiz' => 'info',
                                        'midterm' => 'warning',
                                        'final' => 'error',
                                        'assignment' => 'success',
                                        'project' => 'secondary',
                                        default => 'ghost'
                                    } }}"
                                />
                            </p>
                        </div>

                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Duration</h3>
                            <p class="mt-1">{{ $exam->duration }} minutes</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 py-3 md:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Total Marks</h3>
                            <p class="mt-1">{{ $exam->total_marks }}</p>
                        </div>

                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Passing Mark</h3>
                            <p class="mt-1">{{ $exam->passing_mark }} ({{ round(($exam->passing_mark / $exam->total_marks) * 100) }}%)</p>
                        </div>
                    </div>

                    <div class="py-3">
                        <h3 class="text-sm font-medium text-gray-500">Description</h3>
                        <div class="mt-1 prose-sm prose max-w-none">
                            @if ($exam->description)
                                {!! nl2br(e($exam->description)) !!}
                            @else
                                <p class="text-gray-500">No description provided.</p>
                            @endif
                        </div>
                    </div>

                    <div class="py-3">
                        <h3 class="text-sm font-medium text-gray-500">Instructions</h3>
                        <div class="mt-1 prose-sm prose max-w-none">
                            @if ($exam->instructions)
                                {!! nl2br(e($exam->instructions)) !!}
                            @else
                                <p class="text-gray-500">No instructions provided.</p>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 py-3 md:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Location</h3>
                            <p class="mt-1">
                                @if ($exam->is_online)
                                    <span class="flex items-center gap-1">
                                        <x-icon name="o-computer-desktop" class="w-4 h-4" />
                                        <span>Online Exam</span>
                                    </span>
                                @elseif ($exam->location)
                                    <span class="flex items-center gap-1">
                                        <x-icon name="o-building-office" class="w-4 h-4" />
                                        <span>{{ $exam->location }}</span>
                                    </span>
                                @else
                                    <span class="text-gray-500">No location specified</span>
                                @endif
                            </p>
                        </div>

                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Created By</h3>
                            <p class="mt-1">{{ $exam->teacherProfile->user->name ?? 'Unknown' }}</p>
                        </div>
                    </div>

                    @if ($exam->attachment)
                        <div class="py-3">
                            <h3 class="text-sm font-medium text-gray-500">Attachment</h3>
                            <div class="mt-2">
                                <x-button
                                    label="Download Attachment"
                                    icon="o-arrow-down-tray"
                                    wire:click="downloadAttachment"
                                    class="btn-sm btn-outline"
                                />
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Student Results Table (if exam is graded) -->
            @if ($isCompleted && $exam->is_graded)
                <x-card title="Student Results" class="mt-6">
                    <div class="overflow-x-auto">
                        <table class="table w-full table-zebra">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Score</th>
                                    <th>Status</th>
                                    <th>Comments</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($examStudents as $student)
                                    <tr class="hover">
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <div class="avatar">
                                                    <div class="w-10 h-10 mask mask-squircle">
                                                        @if ($student->childProfile->photo)
                                                            <img src="{{ asset('storage/' . $student->childProfile->photo) }}" alt="{{ $student->childProfile->user->name ?? 'Student' }}">
                                                        @else
                                                            <img src="{{ $student->childProfile->user->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Student&color=7F9CF5&background=EBF4FF' }}" alt="{{ $student->childProfile->user->name ?? 'Student' }}">
                                                        @endif
                                                    </div>
                                                </div>
                                                <div>
                                                    {{ $student->childProfile->user->name ?? 'Unknown Student' }}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            @if ($student->result)
                                                {{ $student->result->score }} / {{ $exam->total_marks }}
                                                <div class="text-xs text-gray-500">{{ round(($student->result->score / $exam->total_marks) * 100) }}%</div>
                                            @else
                                                <span class="text-gray-500">Not graded</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($student->result)
                                                @if ($student->result->score >= $exam->passing_mark)
                                                    <x-badge label="Passed" color="success" />
                                                @else
                                                    <x-badge label="Failed" color="error" />
                                                @endif
                                            @else
                                                <x-badge label="Pending" color="ghost" />
                                            @endif
                                        </td>
                                        <td>
                                            @if ($student->result && $student->result->comments)
                                                <span class="text-sm">{{ $student->result->comments }}</span>
                                            @else
                                                <span class="text-gray-500">No comments</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-4 text-center text-gray-500">
                                            No student results available.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if (count($examStudents) < $totalStudents)
                        <div class="mt-4 text-right">
                            <x-button
                                label="View All Results"
                                icon="o-chevron-right"
                                wire:click="viewResults"
                                class="btn-sm btn-ghost"
                            />
                        </div>
                    @endif
                </x-card>
            @endif
        </div>

        <!-- Right Sidebar -->
        <div>
            <!-- Actions Card -->
            <x-card title="Actions">
                <div class="flex flex-col gap-2">
                    @if ($canEdit)
                        <x-button
                            label="Edit Exam"
                            icon="o-pencil-square"
                            wire:click="editExam"
                            class="w-full"
                        />
                    @endif

                    @if ($needsGrading)
                        <x-button
                            label="Grade Exam"
                            icon="o-academic-cap"
                            wire:click="gradeExam"
                            class="w-full"
                        />
                    @endif

                    @if ($isCompleted && $exam->is_graded)
                        <x-button
                            label="View Results"
                            icon="o-chart-bar"
                            wire:click="viewResults"
                            class="w-full"
                        />
                    @endif

                    <x-button
                        label="Back to Exams"
                        icon="o-arrow-left"
                        href="{{ route('teacher.exams.index') }}"
                        class="w-full btn-outline"
                    />
                </div>
            </x-card>

            <!-- Grade Distribution (if graded) -->
            @if ($isCompleted && $exam->is_graded && $attendedStudents > 0)
                <x-card title="Grade Distribution" class="mt-6">
                    <div class="py-2">
                        <div class="w-full h-4 rounded-full bg-base-300">
                            <div class="h-full rounded-l-full bg-success" style="width: {{ ($passedStudents / $attendedStudents) * 100 }}%"></div>
                        </div>
                        <div class="flex justify-between mt-2 text-sm">
                            <span class="text-success">{{ $passedStudents }} Passed ({{ round(($passedStudents / $attendedStudents) * 100) }}%)</span>
                            <span class="text-error">{{ $failedStudents }} Failed ({{ round(($failedStudents / $attendedStudents) * 100) }}%)</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <h3 class="mb-2 text-sm font-medium">Score Distribution</h3>
                        <div class="space-y-2">
                            @php
                                // Simulated score distribution - in a real app, this would come from the database
                                $ranges = [
                                    ['min' => 90, 'max' => 100, 'count' => $passedStudents > 0 ? rand(0, min(5, $passedStudents)) : 0],
                                    ['min' => 80, 'max' => 89, 'count' => $passedStudents > 0 ? rand(0, min(8, $passedStudents)) : 0],
                                    ['min' => 70, 'max' => 79, 'count' => $passedStudents > 0 ? rand(0, min(10, $passedStudents)) : 0],
                                    ['min' => $exam->passing_mark, 'max' => 69, 'count' => $passedStudents],
                                    ['min' => 0, 'max' => $exam->passing_mark - 1, 'count' => $failedStudents]
                                ];

                                // Ensure the counts add up to attendedStudents
                                $sum = array_sum(array_column($ranges, 'count'));
                                if ($sum != $attendedStudents) {
                                    $ranges[3]['count'] = max(0, $ranges[3]['count'] - ($sum - $attendedStudents));
                                }
                            @endphp

                            @foreach ($ranges as $range)
                                @if ($attendedStudents > 0)
                                    <div>
                                        <div class="flex justify-between mb-1 text-xs">
                                            <span>{{ $range['min'] }}-{{ $range['max'] }}</span>
                                            <span>{{ $range['count'] }} ({{ round(($range['count'] / $attendedStudents) * 100) }}%)</span>
                                        </div>
                                        <div class="w-full h-2 rounded-full bg-base-300">
                                            <div class="h-full rounded-full {{ $range['min'] >= $exam->passing_mark ? 'bg-success' : 'bg-error' }}" style="width: {{ ($range['count'] / $attendedStudents) * 100 }}%"></div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>
