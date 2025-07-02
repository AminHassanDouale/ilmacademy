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

new #[Title('Exam Results')] class extends Component {
    use Toast;

    // Model instances
    public Exam $exam;
    public ?TeacherProfile $teacherProfile = null;

    // Data collections
    public $enrolledStudents = [];
    public $examResults = [];

    // Form data for bulk/quick entry
    public array $resultData = [];
    public bool $bulkMode = false;
    public string $bulkScore = '';
    public string $bulkRemarks = '';

    // UI state
    public bool $isSubmitted = false;
    public string $sortBy = 'name';
    public string $sortDirection = 'asc';

    // Stats
    public array $stats = [];

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
            $this->error('You are not authorized to manage results for this exam.');
            $this->redirect(route('teacher.exams.index'));
            return;
        }

        Log::info('Exam Results Component Mounted', [
            'teacher_user_id' => Auth::id(),
            'exam_id' => $exam->id,
            'subject_id' => $exam->subject_id,
            'ip' => request()->ip()
        ]);

        $this->loadStudentsAndResults();
        $this->loadStats();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed results page for exam: {$exam->title} for {$exam->subject->name}",
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

    protected function loadStudentsAndResults(): void
    {
        try {
            // Get enrolled students for this subject
            $this->enrolledStudents = SubjectEnrollment::with(['childProfile', 'childProfile.user'])
                ->where('subject_id', $this->exam->subject_id)
                ->where('status', 'active')
                ->get()
                ->sortBy('childProfile.full_name')
                ->values();

            // Initialize result data
            $this->resultData = [];

            foreach ($this->enrolledStudents as $enrollment) {
                $studentId = $enrollment->child_profile_id;

                // Check if result already exists
                $existingResult = $this->exam->examResults
                    ->where('child_profile_id', $studentId)
                    ->first();

                $this->resultData[$studentId] = [
                    'score' => $existingResult ? (string) $existingResult->score : '',
                    'remarks' => $existingResult ? $existingResult->remarks : '',
                    'existing_id' => $existingResult ? $existingResult->id : null,
                ];
            }

            // Check if results were already submitted
            $this->isSubmitted = $this->exam->examResults->count() > 0;

            Log::info('Students and Results Data Loaded', [
                'exam_id' => $this->exam->id,
                'enrolled_students_count' => $this->enrolledStudents->count(),
                'existing_results_count' => $this->exam->examResults->count(),
                'is_submitted' => $this->isSubmitted
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to load students and results data', [
                'exam_id' => $this->exam->id,
                'error' => $e->getMessage()
            ]);

            $this->enrolledStudents = collect();
            $this->resultData = [];
        }
    }

    protected function loadStats(): void
    {
        try {
            $results = collect($this->resultData)->filter(fn($data) => !empty($data['score']));
            $scores = $results->pluck('score')->map(fn($score) => (float) $score)->filter();

            if ($scores->count() === 0) {
                $this->stats = [
                    'total_results' => 0,
                    'average_score' => 0,
                    'highest_score' => 0,
                    'lowest_score' => 0,
                    'pass_rate' => 0,
                    'completion_rate' => 0,
                ];
                return;
            }

            $totalStudents = $this->enrolledStudents->count();
            $completedResults = $scores->count();
            $averageScore = $scores->avg();
            $highestScore = $scores->max();
            $lowestScore = $scores->min();

            // Calculate pass rate (assuming 50% is pass)
            $passingScore = $this->exam->total_marks ? ($this->exam->total_marks * 0.5) : 50;
            $passedCount = $scores->filter(fn($score) => $score >= $passingScore)->count();
            $passRate = $completedResults > 0 ? round(($passedCount / $completedResults) * 100, 1) : 0;
            $completionRate = $totalStudents > 0 ? round(($completedResults / $totalStudents) * 100, 1) : 0;

            $this->stats = [
                'total_results' => $completedResults,
                'average_score' => round($averageScore, 1),
                'highest_score' => $highestScore,
                'lowest_score' => $lowestScore,
                'pass_rate' => $passRate,
                'completion_rate' => $completionRate,
            ];

        } catch (\Exception $e) {
            $this->stats = [
                'total_results' => 0,
                'average_score' => 0,
                'highest_score' => 0,
                'lowest_score' => 0,
                'pass_rate' => 0,
                'completion_rate' => 0,
            ];
        }
    }

    // Update individual student result
    public function updateResult(int $studentId, string $field, string $value): void
    {
        if (isset($this->resultData[$studentId])) {
            $this->resultData[$studentId][$field] = $value;

            // Recalculate stats when scores change
            if ($field === 'score') {
                $this->loadStats();
            }

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

        foreach ($this->resultData as $studentId => $data) {
            $this->resultData[$studentId]['score'] = $this->bulkScore;
            if ($this->bulkRemarks) {
                $this->resultData[$studentId]['remarks'] = $this->bulkRemarks;
            }
        }

        $this->bulkMode = false;
        $this->loadStats();

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
        Log::info('Exam Results Save Started', [
            'teacher_user_id' => Auth::id(),
            'exam_id' => $this->exam->id,
            'student_count' => count($this->resultData)
        ]);

        try {
            // Validate all results
            $validationErrors = [];
            foreach ($this->resultData as $studentId => $data) {
                if (!empty($data['score'])) {
                    if (!is_numeric($data['score'])) {
                        $student = $this->enrolledStudents->firstWhere('child_profile_id', $studentId);
                        $validationErrors[] = "Invalid score for {$student->childProfile->full_name}";
                    } elseif ($this->exam->total_marks && (float) $data['score'] > $this->exam->total_marks) {
                        $student = $this->enrolledStudents->firstWhere('child_profile_id', $studentId);
                        $validationErrors[] = "Score for {$student->childProfile->full_name} exceeds total marks";
                    }
                }
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
            $updatedCount = 0;
            $deletedCount = 0;

            foreach ($this->resultData as $studentId => $data) {
                if (!empty($data['score'])) {
                    // Create or update result
                    $resultData = [
                        'exam_id' => $this->exam->id,
                        'child_profile_id' => $studentId,
                        'score' => (float) $data['score'],
                        'remarks' => $data['remarks'] ?: null,
                    ];

                    if ($data['existing_id']) {
                        // Update existing result
                        ExamResult::where('id', $data['existing_id'])->update($resultData);
                        $updatedCount++;
                    } else {
                        // Create new result
                        ExamResult::create($resultData);
                        $createdCount++;
                    }
                } else {
                    // Delete existing result if score is empty
                    if ($data['existing_id']) {
                        ExamResult::where('id', $data['existing_id'])->delete();
                        $deletedCount++;
                    }
                }
            }

            // Log activity
            $description = "Recorded results for exam '{$this->exam->title}' for {$this->exam->subject->name}";
            if ($updatedCount > 0) {
                $description .= " (Updated existing results)";
            }

            ActivityLog::log(
                Auth::id(),
                $this->isSubmitted ? 'update' : 'create',
                $description,
                Exam::class,
                $this->exam->id,
                [
                    'exam_id' => $this->exam->id,
                    'exam_title' => $this->exam->title,
                    'subject_name' => $this->exam->subject->name,
                    'created_count' => $createdCount,
                    'updated_count' => $updatedCount,
                    'deleted_count' => $deletedCount,
                    'total_students' => $this->enrolledStudents->count(),
                    'results_stats' => $this->stats,
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Update submitted status
            $this->isSubmitted = true;

            // Reload data
            $this->loadStudentsAndResults();
            $this->loadStats();

            // Show success message
            if ($updatedCount > 0) {
                $this->success("Results updated successfully for the exam.");
            } else {
                $this->success("Results recorded successfully for the exam.");
            }

            Log::info('Exam Results Save Completed', [
                'exam_id' => $this->exam->id,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'deleted_count' => $deletedCount,
                'stats' => $this->stats
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Exam Results Save Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'exam_id' => $this->exam->id,
                'student_count' => count($this->resultData)
            ]);

            $this->error("An error occurred while saving results: {$e->getMessage()}");
        }
    }

    // Sort results
    public function sortResults(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    // Get sorted students
    public function getSortedStudentsProperty()
    {
        $students = $this->enrolledStudents;

        switch ($this->sortBy) {
            case 'score':
                return $students->sortBy(function ($enrollment) {
                    $score = $this->resultData[$enrollment->child_profile_id]['score'] ?? '';
                    return $score !== '' ? (float) $score : -1;
                }, SORT_REGULAR, $this->sortDirection === 'desc');

            case 'grade':
                return $students->sortBy(function ($enrollment) {
                    $score = $this->resultData[$enrollment->child_profile_id]['score'] ?? '';
                    return $score !== '' ? $this->getGrade((float) $score) : 'Z';
                }, SORT_REGULAR, $this->sortDirection === 'desc');

            default: // name
                return $students->sortBy('childProfile.full_name', SORT_REGULAR, $this->sortDirection === 'desc');
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

    public function with(): array
    {
        return [
            'sortedStudents' => $this->sortedStudents,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Results: {{ $exam->title }}" separator>
        <x-slot:middle class="!justify-end">
            @if($isSubmitted)
                <span class="inline-flex items-center px-3 py-1 text-sm font-medium text-green-800 bg-green-100 rounded-full">
                    <x-icon name="o-check-circle" class="w-4 h-4 mr-1" />
                    Results Submitted
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="View Exam"
                icon="o-eye"
                link="{{ route('teacher.exams.show', $exam->id) }}"
                class="btn-ghost"
            />
            <x-button
                label="Back to Exams"
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
                        <div class="text-sm font-medium text-gray-500">Students Enrolled</div>
                        <div class="text-2xl font-bold text-purple-600">{{ $enrolledStudents->count() }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Bulk Actions -->
            <x-card title="Quick Actions">
                <div class="flex flex-wrap gap-4">
                    @if(!$bulkMode)
                        <x-button
                            label="Bulk Entry"
                            icon="o-squares-plus"
                            wire:click="$set('bulkMode', true)"
                            class="btn-outline"
                        />
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium">Set all scores to:</span>
                            <input
                                type="number"
                                wire:model.live="bulkScore"
                                placeholder="Score"
                                class="w-20 px-2 py-1 text-sm border border-gray-300 rounded"
                                step="0.5"
                                min="0"
                                max="{{ $exam->total_marks }}"
                            />
                            <input
                                type="text"
                                wire:model.live="bulkRemarks"
                                placeholder="Remarks (optional)"
                                class="w-32 px-2 py-1 text-sm border border-gray-300 rounded"
                            />
                            <x-button
                                label="Apply"
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

                    <div class="ml-auto">
                        <x-button
                            label="{{ $isSubmitted ? 'Update Results' : 'Save Results' }}"
                            icon="o-check"
                            wire:click="saveResults"
                            class="btn-primary"
                            wire:confirm="Are you sure you want to {{ $isSubmitted ? 'update' : 'save' }} results for this exam?"
                        />
                    </div>
                </div>
            </x-card>

            <!-- Results Table -->
            <x-card title="Student Results">
                @if($enrolledStudents->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="table w-full table-zebra">
                            <thead>
                                <tr>
                                    <th class="cursor-pointer" wire:click="sortResults('name')">
                                        <div class="flex items-center">
                                            Student
                                            @if ($sortBy === 'name')
                                                <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                            @endif
                                        </div>
                                    </th>
                                    <th class="cursor-pointer" wire:click="sortResults('score')">
                                        <div class="flex items-center">
                                            Score
                                            @if ($sortBy === 'score')
                                                <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                            @endif
                                        </div>
                                    </th>
                                    <th>Percentage</th>
                                    <th class="cursor-pointer" wire:click="sortResults('grade')">
                                        <div class="flex items-center">
                                            Grade
                                            @if ($sortBy === 'grade')
                                                <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                            @endif
                                        </div>
                                    </th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sortedStudents as $enrollment)
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
                                                placeholder="Score"
                                                class="w-20 px-2 py-1 text-sm border border-gray-300 rounded"
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
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getGradeColor($grade) }}">
                                                    {{ $grade }}
                                                </span>
                                            @else
                                                <span class="text-gray-400">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            <input
                                                type="text"
                                                wire:model.live="resultData.{{ $studentId }}.remarks"
                                                wire:change="updateResult({{ $studentId }}, 'remarks', $event.target.value)"
                                                placeholder="Optional remarks"
                                                class="w-full px-2 py-1 text-sm border border-gray-300 rounded"
                                                maxlength="255"
                                            />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Save Button -->
                    <div class="pt-6 text-center border-t">
                        <x-button
                            label="{{ $isSubmitted ? 'Update Results' : 'Save Results' }}"
                            icon="o-check"
                            wire:click="saveResults"
                            class="btn-primary btn-lg"
                            wire:confirm="Are you sure you want to {{ $isSubmitted ? 'update' : 'save' }} results for this exam?"
                        />
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-users" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No students enrolled in this subject</div>
                        <p class="mt-1 text-xs text-gray-400">Contact the administrator to enroll students</p>
                    </div>
                @endif
            </x-card>
        </div>

        <!-- Right column (1/4) - Stats and Info -->
        <div class="space-y-6">
            <!-- Results Summary -->
            <x-card title="Results Summary">
                <div class="space-y-4">
                    <!-- Summary Stats -->
                    <div class="grid grid-cols-1 gap-3">
                        <div class="p-3 text-center rounded-lg bg-blue-50">
                            <div class="text-lg font-bold text-blue-600">{{ $stats['total_results'] }}/{{ $enrolledStudents->count() }}</div>
                            <div class="text-xs text-blue-600">Results Entered</div>
                        </div>

                        <div class="p-3 text-center rounded-lg bg-green-50">
                            <div class="text-lg font-bold text-green-600">{{ $stats['completion_rate'] }}%</div>
                            <div class="text-xs text-green-600">Completion Rate</div>
                        </div>
                    </div>

                    @if($stats['total_results'] > 0)
                        <div class="grid grid-cols-1 gap-3">
                            <div class="p-3 text-center rounded-lg bg-yellow-50">
                                <div class="text-lg font-bold text-yellow-600">{{ $stats['average_score'] }}</div>
                                <div class="text-xs text-yellow-600">Average Score</div>
                            </div>

                            <div class="p-3 text-center rounded-lg bg-purple-50">
                                <div class="text-lg font-bold text-purple-600">{{ $stats['pass_rate'] }}%</div>
                                <div class="text-xs text-purple-600">Pass Rate</div>
                            </div>
                        </div>

                        <!-- Score Range -->
                        <div class="pt-3 border-t">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm text-gray-500">Score Range</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="font-medium text-green-600">High: {{ $stats['highest_score'] }}</span>
                                <span class="font-medium text-red-600">Low: {{ $stats['lowest_score'] }}</span>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Grading Scale -->
            <x-card title="Grading Scale">
                <div class="space-y-2 text-sm">
                    @php
                        $grades = [
                            ['grade' => 'A', 'range' => '90-100%', 'color' => 'bg-green-100 text-green-800'],
                            ['grade' => 'B', 'range' => '80-89%', 'color' => 'bg-blue-100 text-blue-800'],
                            ['grade' => 'C', 'range' => '70-79%', 'color' => 'bg-yellow-100 text-yellow-800'],
                            ['grade' => 'D', 'range' => '50-69%', 'color' => 'bg-orange-100 text-orange-800'],
                            ['grade' => 'F', 'range' => 'Below 50%', 'color' => 'bg-red-100 text-red-800'],
                        ];
                    @endphp
                    @foreach($grades as $gradeInfo)
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $gradeInfo['color'] }}">
                                {{ $gradeInfo['grade'] }}
                            </span>
                            <span class="text-gray-600">{{ $gradeInfo['range'] }}</span>
                        </div>
                    @endforeach
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

            <!-- Entry Tips -->
            <x-card title="Entry Tips">
                <div class="space-y-3 text-xs text-gray-600">
                    <div>
                        <div class="font-semibold">Bulk Entry</div>
                        <p>Use bulk entry to quickly set the same score for all students, then adjust individual scores as needed.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Score Validation</div>
                        <p>Scores cannot exceed the total marks set for this exam ({{ $exam->total_marks ?? 'N/A' }}).</p>
                    </div>

                    <div>
                        <div class="font-semibold">Remarks</div>
                        <p>Add optional remarks for students who need feedback or have special circumstances.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Sorting</div>
                        <p>Click column headers to sort by student name, score, or grade for easier entry.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Auto-Save</div>
                        <p>Changes are saved automatically as you type. Use the Save Results button to finalize.</p>
                    </div>
                </div>
            </x-card>

            <!-- Exam Details -->
            <x-card title="Exam Details">
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

                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $exam->created_at->format('M d, Y') }}</div>
                        <div class="text-xs text-gray-400">{{ $exam->created_at->diffForHumans() }}</div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
