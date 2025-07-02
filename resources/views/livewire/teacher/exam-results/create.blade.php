<?php

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\TeacherProfile;
use App\Models\SubjectEnrollment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Add Exam Results')] class extends Component {
    use Toast;

    // Model instances
    public Exam $exam;
    public ?TeacherProfile $teacherProfile = null;

    // Data collections
    public $enrolledStudents = [];

    // Form data
    public array $resultData = [];
    public bool $bulkMode = false;
    public string $bulkScore = '';
    public string $bulkRemarks = '';

    // Mount the component
    public function mount(Exam $exam): void
    {
        $this->exam = $exam->load(['subject', 'subject.curriculum', 'teacherProfile', 'academicYear', 'examResults']);
        $this->teacherProfile = Auth::user()->teacherProfile;

        if (!$this->teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            $this->redirect(route('teacher.profile.edit'));
            return;
        }

        // Check if teacher owns this exam
        if ($this->exam->teacher_profile_id !== $this->teacherProfile->id) {
            $this->error('You are not authorized to add results for this exam.');
            $this->redirect(route('teacher.exams.index'));
            return;
        }

        // Check if results already exist
        if ($this->exam->examResults->count() > 0) {
            $this->info('Results already exist for this exam. Redirecting to manage results.');
            $this->redirect(route('teacher.exams.results', $this->exam->id));
            return;
        }

        Log::info('Exam Results Create Component Mounted', [
            'teacher_user_id' => Auth::id(),
            'exam_id' => $exam->id,
            'subject_id' => $exam->subject_id,
            'ip' => request()->ip()
        ]);

        $this->loadStudents();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed create results page for exam: {$exam->title} for {$exam->subject->name}",
            Exam::class,
            $exam->id,
            [
                'exam_id' => $exam->id,
                'exam_title' => $exam->title,
                'subject_name' => $exam->subject->name,
                'ip' => request()->ip()
            ]
        );
    }

    protected function loadStudents(): void
    {
        try {
            // Get enrolled students for this subject
            $this->enrolledStudents = SubjectEnrollment::with(['childProfile', 'childProfile.user'])
                ->where('subject_id', $this->exam->subject_id)
                ->where('status', 'active')
                ->get()
                ->sortBy('childProfile.full_name')
                ->values();

            // Initialize result data with default values
            $this->resultData = [];
            foreach ($this->enrolledStudents as $enrollment) {
                $studentId = $enrollment->child_profile_id;
                $this->resultData[$studentId] = [
                    'score' => '',
                    'remarks' => '',
                ];
            }

            Log::info('Students Data Loaded', [
                'exam_id' => $this->exam->id,
                'enrolled_students_count' => $this->enrolledStudents->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to load students data', [
                'exam_id' => $this->exam->id,
                'error' => $e->getMessage()
            ]);

            $this->enrolledStudents = collect();
            $this->resultData = [];
        }
    }

    // Update individual student result
    public function updateResult(int $studentId, string $field, string $value): void
    {
        if (isset($this->resultData[$studentId])) {
            $this->resultData[$studentId][$field] = $value;

            Log::debug('Result Updated', [
                'student_id' => $studentId,
                'field' => $field,
                'value' => $value,
                'exam_id' => $this->exam->id
            ]);
        }
    }

    // Bulk update results
    public function bulkUpdateResults(): void
    {
        if (empty($this->bulkScore)) {
            $this->error('Please enter a score for bulk update.');
            return;
        }

        // Validate bulk score
        if (!is_numeric($this->bulkScore)) {
            $this->error('Please enter a valid numeric score.');
            return;
        }

        if ($this->exam->total_marks && (float) $this->bulkScore > $this->exam->total_marks) {
            $this->error('Score cannot exceed total marks (' . $this->exam->total_marks . ').');
            return;
        }

        foreach ($this->resultData as $studentId => $data) {
            $this->resultData[$studentId]['score'] = $this->bulkScore;
            if ($this->bulkRemarks) {
                $this->resultData[$studentId]['remarks'] = $this->bulkRemarks;
            }
        }

        $this->bulkMode = false;

        $this->success("All students scored as {$this->bulkScore}");

        Log::info('Bulk Results Update', [
            'exam_id' => $this->exam->id,
            'bulk_score' => $this->bulkScore,
            'bulk_remarks' => $this->bulkRemarks,
            'student_count' => count($this->resultData)
        ]);
    }

    // Save all results
    public function saveResults(): void
    {
        Log::info('Exam Results Create Started', [
            'teacher_user_id' => Auth::id(),
            'exam_id' => $this->exam->id,
            'student_count' => count($this->resultData)
        ]);

        try {
            // Validate that at least one result is entered
            $hasResults = false;
            $validationErrors = [];

            foreach ($this->resultData as $studentId => $data) {
                if (!empty($data['score'])) {
                    $hasResults = true;

                    // Validate score
                    if (!is_numeric($data['score'])) {
                        $student = $this->enrolledStudents->firstWhere('child_profile_id', $studentId);
                        $validationErrors[] = "Invalid score for {$student->childProfile->full_name}";
                    } elseif ((float) $data['score'] < 0) {
                        $student = $this->enrolledStudents->firstWhere('child_profile_id', $studentId);
                        $validationErrors[] = "Score cannot be negative for {$student->childProfile->full_name}";
                    } elseif ($this->exam->total_marks && (float) $data['score'] > $this->exam->total_marks) {
                        $student = $this->enrolledStudents->firstWhere('child_profile_id', $studentId);
                        $validationErrors[] = "Score for {$student->childProfile->full_name} exceeds total marks ({$this->exam->total_marks})";
                    }
                }
            }

            if (!$hasResults) {
                $this->error('Please enter at least one result before saving.');
                return;
            }

            if (!empty($validationErrors)) {
                foreach ($validationErrors as $error) {
                    $this->error($error);
                }
                return;
            }

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            $createdCount = 0;
            $createdResults = [];

            foreach ($this->resultData as $studentId => $data) {
                if (!empty($data['score'])) {
                    // Create new result
                    $resultData = [
                        'exam_id' => $this->exam->id,
                        'child_profile_id' => $studentId,
                        'score' => (float) $data['score'],
                        'remarks' => $data['remarks'] ?: null,
                    ];

                    $examResult = ExamResult::create($resultData);
                    $createdResults[] = $examResult;
                    $createdCount++;
                }
            }

            // Calculate statistics for logging
            $scores = collect($createdResults)->pluck('score');
            $averageScore = $scores->avg();
            $highestScore = $scores->max();
            $lowestScore = $scores->min();

            // Log activity
            $description = "Created results for exam '{$this->exam->title}' for {$this->exam->subject->name}";

            ActivityLog::log(
                Auth::id(),
                'create',
                $description,
                Exam::class,
                $this->exam->id,
                [
                    'exam_id' => $this->exam->id,
                    'exam_title' => $this->exam->title,
                    'subject_name' => $this->exam->subject->name,
                    'created_count' => $createdCount,
                    'total_students' => $this->enrolledStudents->count(),
                    'statistics' => [
                        'average_score' => round($averageScore, 1),
                        'highest_score' => $highestScore,
                        'lowest_score' => $lowestScore,
                    ],
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Show success message
            $this->success("Results created successfully for {$createdCount} students.");

            Log::info('Exam Results Create Completed', [
                'exam_id' => $this->exam->id,
                'created_count' => $createdCount,
                'average_score' => round($averageScore, 1),
            ]);

            // Redirect to results management page
            $this->redirect(route('teacher.exams.results', $this->exam->id));

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Exam Results Create Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'exam_id' => $this->exam->id,
                'student_count' => count($this->resultData)
            ]);

            $this->error("An error occurred while creating results: {$e->getMessage()}");
        }
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

    // Get percentage
    public function getPercentage(float $score): string
    {
        if (!$this->exam->total_marks) {
            return 'N/A';
        }

        return round(($score / $this->exam->total_marks) * 100, 1) . '%';
    }

    // Get current statistics
    public function getCurrentStatsProperty(): array
    {
        $scores = collect($this->resultData)
            ->filter(fn($data) => !empty($data['score']))
            ->pluck('score')
            ->map(fn($score) => (float) $score);

        if ($scores->count() === 0) {
            return [
                'total_entries' => 0,
                'average_score' => 0,
                'completion_percentage' => 0,
            ];
        }

        return [
            'total_entries' => $scores->count(),
            'average_score' => round($scores->avg(), 1),
            'completion_percentage' => round(($scores->count() / $this->enrolledStudents->count()) * 100, 1),
        ];
    }

    public function with(): array
    {
        return [
            'currentStats' => $this->currentStats,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Add Results: {{ $exam->title }}" separator>
        <x-slot:actions>
            <x-button
                label="View Exam"
                icon="o-eye"
                link="{{ route('teacher.exams.show', $exam->id) }}"
                class="btn-ghost"
            />
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('teacher.exams.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <!-- Left column (3/4) - Results Form -->
        <div class="space-y-6 lg:col-span-3">
            <!-- Exam Information -->
            <x-card title="Exam Information">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject</div>
                        <div class="font-semibold">{{ $exam->subject->name }}</div>
                        <div class="text-sm text-gray-600">{{ $exam->subject->code }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Exam Date</div>
                        <div class="font-semibold">{{ $exam->exam_date->format('M d, Y') }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Total Marks</div>
                        <div class="text-2xl font-bold text-blue-600">{{ $exam->total_marks ?? 'N/A' }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Students</div>
                        <div class="text-2xl font-bold text-purple-600">{{ $enrolledStudents->count() }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Bulk Actions -->
            <x-card title="Quick Entry">
                <div class="flex flex-wrap items-center gap-4">
                    @if(!$bulkMode)
                        <x-button
                            label="Bulk Entry Mode"
                            icon="o-squares-plus"
                            wire:click="$set('bulkMode', true)"
                            class="btn-outline"
                        />
                        <span class="text-sm text-gray-500">Use bulk entry to quickly set the same score for all students</span>
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium">Set all scores to:</span>
                            <input
                                type="number"
                                wire:model.live="bulkScore"
                                placeholder="Score"
                                class="w-24 px-3 py-2 text-sm border border-gray-300 rounded"
                                step="0.5"
                                min="0"
                                max="{{ $exam->total_marks }}"
                            />
                            <input
                                type="text"
                                wire:model.live="bulkRemarks"
                                placeholder="Remarks (optional)"
                                class="w-40 px-3 py-2 text-sm border border-gray-300 rounded"
                            />
                            <x-button
                                label="Apply to All"
                                icon="o-check"
                                wire:click="bulkUpdateResults"
                                class="btn-sm btn-success"
                            />
                            <x-button
                                label="Cancel"
                                icon="o-x-mark"
                                wire:click="$set('bulkMode', false)"
                                class="btn-sm btn-ghost"
                            />
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Results Entry Form -->
            <x-card title="Enter Student Results">
                @if($enrolledStudents->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="table w-full table-zebra">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Grade</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($enrolledStudents as $enrollment)
                                    @php
                                        $student = $enrollment->childProfile;
                                        $studentId = $student->id;
                                        $result = $resultData[$studentId] ?? ['score' => '', 'remarks' => ''];
                                        $score = !empty($result['score']) ? (float) $result['score'] : null;
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="flex items-center space-x-3">
                                                <div class="avatar">
                                                    <div class="w-10 h-10 rounded-full">
                                                        <img src="{{ $student->user->profile_photo_url ?? 'https://ui-avatars.com/api/?name=' . urlencode($student->full_name) }}" alt="{{ $student->full_name }}" />
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="font-semibold">{{ $student->full_name }}</div>
                                                    <div class="text-sm text-gray-500">ID: {{ $student->id }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                wire:model.live="resultData.{{ $studentId }}.score"
                                                wire:change="updateResult({{ $studentId }}, 'score', $event.target.value)"
                                                placeholder="Enter score"
                                                class="w-24 px-3 py-2 text-sm border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                step="0.5"
                                                min="0"
                                                max="{{ $exam->total_marks }}"
                                            />
                                            @if($exam->total_marks)
                                                <span class="ml-1 text-xs text-gray-500">/ {{ $exam->total_marks }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($score !== null)
                                                <span class="font-medium">{{ $this->getPercentage($score) }}</span>
                                            @else
                                                <span class="text-gray-400">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($score !== null)
                                                @php $grade = $this->getGrade($score); @endphp
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $gradeInfo['color'] }}">
                                {{ $gradeInfo['grade'] }}
                            </span>
                            <span class="text-gray-600">{{ $gradeInfo['range'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <!-- Entry Guidelines -->
            <x-card title="Entry Guidelines">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-semibold">Score Entry</div>
                        <p class="text-gray-600">Enter numerical scores only. Scores cannot exceed the total marks ({{ $exam->total_marks ?? 'N/A' }}).</p>
                    </div>

                    <div>
                        <div class="font-semibold">Bulk Entry</div>
                        <p class="text-gray-600">Use bulk entry to quickly assign the same score to all students, then adjust individual scores as needed.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Remarks</div>
                        <p class="text-gray-600">Add optional remarks for specific feedback, notes about performance, or special circumstances.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Grades</div>
                        <p class="text-gray-600">Grades are calculated automatically based on the percentage of total marks achieved.</p>
                    </div>
                </div>
            </x-card>

            <!-- Exam Summary -->
            <x-card title="Exam Summary">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Exam Type</div>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ match($exam->type) {
                            'quiz' => 'bg-green-100 text-green-800',
                            'midterm' => 'bg-yellow-100 text-yellow-800',
                            'final' => 'bg-red-100 text-red-800',
                            'assignment' => 'bg-blue-100 text-blue-800',
                            'project' => 'bg-purple-100 text-purple-800',
                            'practical' => 'bg-orange-100 text-orange-800',
                            default => 'bg-gray-100 text-gray-600'
                        } }}">
                            {{ ucfirst($exam->type) }}
                        </span>
                    </div>

                    @if($exam->duration)
                        <div>
                            <div class="font-medium text-gray-500">Duration</div>
                            <div>
                                @php
                                    $minutes = $exam->duration;
                                    if ($minutes >= 60) {
                                        $hours = floor($minutes / 60);
                                        $remainingMinutes = $minutes % 60;
                                        echo $remainingMinutes > 0 ? "{$hours}h {$remainingMinutes}m" : "{$hours}h";
                                    } else {
                                        echo "{$minutes}m";
                                    }
                                @endphp
                            </div>
                        </div>
                    @endif

                    <div>
                        <div class="font-medium text-gray-500">Academic Year</div>
                        <div>{{ $exam->academicYear ? $exam->academicYear->name : 'Not specified' }}</div>
                    </div>

                    @if($exam->description)
                        <div>
                            <div class="font-medium text-gray-500">Description</div>
                            <div class="p-2 text-xs rounded bg-gray-50">{{ $exam->description }}</div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-2">
                    <x-button
                        label="View Exam Details"
                        icon="o-eye"
                        link="{{ route('teacher.exams.show', $exam->id) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Edit Exam"
                        icon="o-pencil"
                        link="{{ route('teacher.exams.edit', $exam->id) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="All Exams"
                        icon="o-document-text"
                        link="{{ route('teacher.exams.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Subject Details"
                        icon="o-academic-cap"
                        link="{{ route('teacher.subjects.show', $exam->subject_id) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>

            <!-- Important Notes -->
            <x-card title="Important Notes" class="border-yellow-200 bg-yellow-50">
                <div class="space-y-2 text-sm text-yellow-800">
                    <div class="flex items-start">
                        <x-icon name="o-exclamation-triangle" class="w-4 h-4 mr-2 mt-0.5 text-yellow-600" />
                        <div>
                            <div class="font-semibold">First Time Entry</div>
                            <p>This is the initial results entry for this exam. You can edit results later if needed.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-clock" class="w-4 h-4 mr-2 mt-0.5 text-yellow-600" />
                        <div>
                            <div class="font-semibold">Save Progress</div>
                            <p>You don't need to enter all results at once. Save partial results and continue later.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-information-circle" class="w-4 h-4 mr-2 mt-0.5 text-yellow-600" />
                        <div>
                            <div class="font-semibold">Student Notifications</div>
                            <p>Students will be able to view their results once you save them.</p>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
