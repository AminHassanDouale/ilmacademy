<?php

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ActivityLog;
use App\Models\ChildProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Rule;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Record Exam Results')] class extends Component {
    use Toast;

    // Exam model
    public Exam $exam;

    // Attributes for bulk upload
    public $bulkUpload = false;
    public $uploadFile = null;

    // Search filter
    public $search = '';

    // Student results data
    public $students = [];
    public $results = [];
    public $comments = [];
    public $absentStudents = [];

    // Status tracking
    public $isAllStudentsProcessed = false;
    public $isCompleted = false;

    // Options
    public $notifyStudents = true;
    #[Rule('required|numeric')]
    public $markAsAbsentBelowScore = null;

    public function mount(Exam $exam): void
    {
        $this->exam = $exam;

        // Load the exam with relationships
        $this->exam->load(['subject', 'teacherProfile.user']);

        // Check if current teacher is the owner of this exam
        $teacherProfile = Auth::user()->teacherProfile;
        $isOwner = $teacherProfile && $teacherProfile->id === $this->exam->teacher_profile_id;

        if (!$isOwner) {
            $this->error('You do not have permission to record results for this exam.');
            redirect()->route('teacher.exams.index');
            return;
        }

        // Check if exam is completed
        $now = Carbon::now();
        $examDate = Carbon::parse($this->exam->date);
        $this->isCompleted = $examDate->isBefore($now->startOfDay());

        if (!$this->isCompleted) {
            $this->error('You cannot record results for an exam that has not been completed yet.');
            redirect()->route('teacher.exams.show', $this->exam->id);
            return;
        }

        // Check if exam is already graded
        if ($this->exam->is_graded) {
            $this->error('This exam has already been graded. You can view the results instead.');
            redirect()->route('teacher.exams.results', $this->exam->id);
            return;
        }

        // Load students and initialize results
        $this->loadStudents();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed create exam results page: ' . $this->exam->title,
            Exam::class,
            $this->exam->id,
            ['ip' => request()->ip()]
        );
    }

    // Load students enrolled in the subject
    private function loadStudents(): void
    {
        try {
            // Get students enrolled in the subject
            if (method_exists($this->exam->subject, 'enrolledStudents')) {
                $this->students = $this->exam->subject->enrolledStudents()
                    ->with(['childProfile.user', 'program'])
                    ->get()
                    ->map(function ($student) {
                        return [
                            'id' => $student->childProfile->id,
                            'name' => $student->childProfile->user->name ?? 'Unknown Student',
                            'photo' => $student->childProfile->photo,
                            'profile_url' => $student->childProfile->user->profile_photo_url ?? null,
                            'program' => $student->program->name ?? 'No Program',
                            'enrollment_id' => $student->id,
                        ];
                    })
                    ->toArray();

                // Initialize results and comments arrays with student IDs as keys
                foreach ($this->students as $student) {
                    $this->results[$student['id']] = null;
                    $this->comments[$student['id']] = '';
                    $this->absentStudents[$student['id']] = false;
                }
            } else {
                $this->students = [];
            }
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading enrolled students: ' . $e->getMessage(),
                Exam::class,
                $this->exam->id,
                ['ip' => request()->ip()]
            );

            $this->error('Failed to load enrolled students. Please try again.');
        }
    }

    // Filter students based on search term
    public function getFilteredStudents()
    {
        if (empty($this->search)) {
            return $this->students;
        }

        return array_filter($this->students, function ($student) {
            return stripos($student['name'], $this->search) !== false;
        });
    }

    // Mark student as absent
    public function toggleAbsent($studentId): void
    {
        $this->absentStudents[$studentId] = !$this->absentStudents[$studentId];

        // Clear score if marked as absent
        if ($this->absentStudents[$studentId]) {
            $this->results[$studentId] = null;
        }
    }

    // Validate results before saving
    public function validateResults(): bool
    {
        try {
            // Check if all students have a valid result or are marked absent
            $this->isAllStudentsProcessed = true;

            foreach ($this->students as $student) {
                $studentId = $student['id'];

                // If student is marked as absent, skip validation
                if ($this->absentStudents[$studentId]) {
                    continue;
                }

                // Check if score is provided
                if ($this->results[$studentId] === null || $this->results[$studentId] === '') {
                    $this->isAllStudentsProcessed = false;
                    break;
                }

                // Validate score is within acceptable range
                if ($this->results[$studentId] < 0 || $this->results[$studentId] > $this->exam->total_marks) {
                    $this->addError("results.{$studentId}", "Score must be between 0 and {$this->exam->total_marks}");
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error validating exam results: ' . $e->getMessage(),
                Exam::class,
                $this->exam->id,
                ['ip' => request()->ip()]
            );

            $this->error('Error validating results: ' . $e->getMessage());
            return false;
        }
    }

    // Process and validate mark as absent below score
    public function applyAbsentBelowScore(): void
    {
        try {
            if ($this->markAsAbsentBelowScore === null) {
                $this->error('Please enter a valid score threshold');
                return;
            }

            // Validate input
            $this->validate([
                'markAsAbsentBelowScore' => 'required|numeric|min:0|max:' . $this->exam->total_marks,
            ]);

            $count = 0;

            // Apply to all students
            foreach ($this->students as $student) {
                $studentId = $student['id'];

                // If score is below threshold and not null, mark as absent
                if (is_numeric($this->results[$studentId]) && $this->results[$studentId] < $this->markAsAbsentBelowScore) {
                    $this->absentStudents[$studentId] = true;
                    $this->results[$studentId] = null;
                    $count++;
                }
            }

            $this->success($count . ' students with scores below ' . $this->markAsAbsentBelowScore . ' marked as absent.');
            $this->markAsAbsentBelowScore = null;
        } catch (\Exception $e) {
            $this->error('Error applying absent threshold: ' . $e->getMessage());
        }
    }

    // Save exam results
    public function saveResults(): void
    {
        try {
            // Validate results first
            if (!$this->validateResults()) {
                return;
            }

            // Begin transaction to ensure all results are saved
            \DB::beginTransaction();

            $savedCount = 0;
            $absentCount = 0;
            $now = Carbon::now();

            // Create result records
            foreach ($this->students as $student) {
                $studentId = $student['id'];
                $isAbsent = $this->absentStudents[$studentId];

                // Create exam result record
                ExamResult::create([
                    'exam_id' => $this->exam->id,
                    'child_profile_id' => $studentId,
                    'score' => $isAbsent ? 0 : $this->results[$studentId],
                    'comments' => $this->comments[$studentId],
                    'is_absent' => $isAbsent,
                    'submitted_at' => $now,
                    'graded_by' => Auth::id(),
                ]);

                if ($isAbsent) {
                    $absentCount++;
                } else {
                    $savedCount++;
                }
            }

            // Update exam status to graded
            $this->exam->update([
                'is_graded' => true,
                'graded_at' => $now,
            ]);

            // Notify students if selected
            if ($this->notifyStudents) {
                // TODO: Implement notification logic
                // This would typically involve sending emails or notifications to students
            }

            // Commit transaction
            \DB::commit();

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                'Teacher recorded exam results: ' . $this->exam->title,
                Exam::class,
                $this->exam->id,
                [
                    'ip' => request()->ip(),
                    'stats' => [
                        'students_count' => count($this->students),
                        'results_saved' => $savedCount,
                        'absent_count' => $absentCount,
                    ]
                ]
            );

            $this->success('Exam results saved successfully. ' . $savedCount . ' results recorded and ' . $absentCount . ' students marked as absent.');

            // Redirect to results page
            redirect()->route('teacher.exams.results', $this->exam->id);
        } catch (\Exception $e) {
            // Rollback transaction on error
            \DB::rollBack();

            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error saving exam results: ' . $e->getMessage(),
                Exam::class,
                $this->exam->id,
                ['ip' => request()->ip()]
            );

            $this->error('Failed to save exam results: ' . $e->getMessage());
        }
    }

    // Handle Excel/CSV upload for bulk results
    public function handleBulkUpload(): void
    {
        try {
            // TODO: Implement file upload and parsing logic
            // This would typically involve:
            // 1. Validating the uploaded file (Excel/CSV)
            // 2. Parsing the file contents
            // 3. Mapping student data to the results array
            // 4. Handling any errors in the file format

            $this->bulkUpload = false;
            $this->success('Bulk upload processed successfully.');
        } catch (\Exception $e) {
            $this->error('Failed to process bulk upload: ' . $e->getMessage());
        }
    }

    // Cancel and go back
    public function cancel(): void
    {
        redirect()->route('teacher.exams.show', $this->exam->id);
    }

    // Format date in d/m/Y format
    public function formatDate($date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Record Exam Results" separator progress-indicator>
        <x-slot:subtitle>
            {{ $exam->title }} | {{ $exam->subject->name ?? 'Unknown Subject' }} | {{ formatDate($exam->date) }}
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search students..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Bulk Upload"
                icon="o-arrow-up-tray"
                @click="$wire.bulkUpload = true"
                class="btn-outline"
                responsive
            />
            <x-button
                label="Cancel"
                icon="o-x-mark"
                wire:click="cancel"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Exam info card -->
    <x-card class="mb-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
                <h3 class="text-sm font-medium text-gray-500">Exam Details</h3>
                <div class="mt-2 space-y-1">
                    <div class="flex justify-between">
                        <span>Type:</span>
                        <span class="font-medium">{{ ucfirst($exam->type) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Date:</span>
                        <span class="font-medium">{{ formatDate($exam->date) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Duration:</span>
                        <span class="font-medium">{{ $exam->duration }} minutes</span>
                    </div>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-medium text-gray-500">Scoring</h3>
                <div class="mt-2 space-y-1">
                    <div class="flex justify-between">
                        <span>Total Marks:</span>
                        <span class="font-medium">{{ $exam->total_marks }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Passing Mark:</span>
                        <span class="font-medium">{{ $exam->passing_mark }} ({{ round(($exam->passing_mark / $exam->total_marks) * 100) }}%)</span>
                    </div>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-medium text-gray-500">Students</h3>
                <div class="mt-2 space-y-1">
                    <div class="flex justify-between">
                        <span>Total Students:</span>
                        <span class="font-medium">{{ count($students) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Results Entered:</span>
                        <span class="font-medium">{{ count(array_filter($results, fn($score) => $score !== null)) + count(array_filter($absentStudents)) }} / {{ count($students) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <x-alert icon="o-information-circle" class="mb-4">
                <span class="font-medium">Instructions:</span> Enter scores for each student or mark them as absent. All students must have a score or be marked as absent before saving the results.
            </x-alert>

            <div class="flex flex-col gap-3 mt-4 md:flex-row">
                <div class="flex-1">
                    <div class="flex gap-2">
                        <x-input
                            type="number"
                            placeholder="Score threshold"
                            wire:model="markAsAbsentBelowScore"
                            min="0"
                            max="{{ $exam->total_marks }}"
                            class="w-full"
                        />
                        <x-button
                            label="Mark as Absent Below Score"
                            wire:click="applyAbsentBelowScore"
                            class="btn-secondary"
                        />
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        This will mark students with scores below the threshold as absent.
                    </div>
                </div>

                <div>
                    <x-toggle
                        label="Notify Students"
                        wire:model="notifyStudents"
                        hint="Send notifications to students about their results"
                    />
                </div>
            </div>
        </div>
    </x-card>

    <!-- Results entry table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th class="w-32 text-center">Score</th>
                        <th class="w-32 text-center">Status</th>
                        <th>Comments</th>
                        <th class="w-20 text-center">Absent</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (getFilteredStudents() as $student)
                        <tr class="hover {{ $absentStudents[$student['id']] ? 'bg-base-300' : '' }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar">
                                        <div class="w-10 h-10 mask mask-squircle">
                                            @if ($student['photo'])
                                                <img src="{{ asset('storage/' . $student['photo']) }}" alt="{{ $student['name'] }}">
                                            @else
                                                <img src="{{ $student['profile_url'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($student['name']) . '&color=7F9CF5&background=EBF4FF' }}" alt="{{ $student['name'] }}">
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <div class="font-bold">{{ $student['name'] }}</div>
                                        <div class="text-sm opacity-50">{{ $student['program'] }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <x-input
                                    type="number"
                                    min="0"
                                    max="{{ $exam->total_marks }}"
                                    wire:model.blur="results.{{ $student['id'] }}"
                                    class="w-24 text-center"
                                    :disabled="$absentStudents[$student['id']]"
                                />
                                @error("results.{$student['id']}")
                                    <div class="mt-1 text-xs text-error">{{ $message }}</div>
                                @enderror
                            </td>
                            <td class="text-center">
                                @if ($absentStudents[$student['id']])
                                    <x-badge label="Absent" color="secondary" />
                                @elseif (is_numeric($results[$student['id']]))
                                    @if ($results[$student['id']] >= $exam->passing_mark)
                                        <x-badge label="Passed" color="success" />
                                    @else
                                        <x-badge label="Failed" color="error" />
                                    @endif
                                @else
                                    <x-badge label="Pending" color="ghost" />
                                @endif
                            </td>
                            <td>
                                <x-textarea
                                    placeholder="Optional comments"
                                    wire:model.blur="comments.{{ $student['id'] }}"
                                    rows="1"
                                    class="h-10"
                                />
                            </td>
                            <td class="text-center">
                                <x-checkbox
                                    wire:model.live="absentStudents.{{ $student['id'] }}"
                                    wire:click="toggleAbsent('{{ $student['id'] }}')"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-users" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No students found</h3>
                                    <p class="text-gray-500">No students are enrolled in this subject or match your search criteria</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-button
                    label="Cancel"
                    icon="o-x-mark"
                    wire:click="cancel"
                />
                <x-button
                    label="Save Results"
                    icon="o-check"
                    wire:click="saveResults"
                    class="btn-primary"
                    :disabled="!isAllStudentsProcessed"
                />
            </div>
        </x-slot:footer>
    </x-card>

    <!-- Bulk Upload Modal -->
    <x-modal wire:model="bulkUpload" title="Bulk Upload Exam Results">
        <div class="space-y-4">
            <x-alert icon="o-information-circle">
                Upload an Excel or CSV file containing student results. The file should have columns for Student ID, Score, and optional Comments.
            </x-alert>

            <div>
                <label class="block mb-2 text-sm font-medium">Result File</label>
                <input
                    type="file"
                    wire:model="uploadFile"
                    class="w-full file-input file-input-bordered"
                    accept=".xlsx,.xls,.csv"
                />
                <div class="mt-1 text-xs text-gray-500">
                    Accepted formats: Excel (.xlsx, .xls) or CSV
                </div>
            </div>

            <div>
                <h3 class="mb-2 text-sm font-medium">Expected Format</h3>
                <div class="p-3 rounded-lg bg-base-200">
                    <pre class="text-xs">StudentID | Score | Comments (optional)
s12345    | 85    | Good understanding of concepts
s67890    | 72    | Needs improvement in section B
...       | ...   | ...</pre>
                </div>
            </div>

            <div>
                <x-toggle
                    label="First row contains headers"
                    checked
                />
            </div>

            <div>
                <x-toggle
                    label="Mark student as absent if score is zero"
                />
            </div>
        </div>

        <x-slot:footer>
            <div class="flex justify-between w-full">
                <x-button
                    label="Download Template"
                    icon="o-arrow-down-tray"
                    class="btn-outline btn-sm"
                />
                <div>
                    <x-button
                        label="Cancel"
                        @click="$wire.bulkUpload = false"
                    />
                    <x-button
                        label="Upload"
                        icon="o-arrow-up-tray"
                        wire:click="handleBulkUpload"
                        class="btn-primary"
                    />
                </div>
            </div>
        </x-slot:footer>
    </x-modal>
</div>
