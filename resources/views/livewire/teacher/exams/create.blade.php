<?php

use App\Models\Exam;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Rule;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Exam')] class extends Component {
    use Toast;
    use WithFileUploads;

    // Form fields
    #[Rule('required')]
    public $subject_id;

    #[Rule('required|date|after_or_equal:today')]
    public $date;

    #[Rule('nullable')]
    public $time;

    #[Rule('required|string|max:255')]
    public $title;

    #[Rule('required|string|max:255')]
    public $type = 'quiz';

    #[Rule('required|integer|min:1|max:480')]
    public $duration = 60;

    #[Rule('required|integer|min:0|max:100')]
    public $passing_mark = 60;

    #[Rule('required|integer|min:0')]
    public $total_marks = 100;

    #[Rule('nullable|string')]
    public $description;

    #[Rule('nullable|string')]
    public $instructions;

    #[Rule('nullable|string')]
    public $location;

    #[Rule('boolean')]
    public $is_online = false;

    #[Rule('nullable|file|max:5120')]
    public $attachment;

    #[Rule('boolean')]
    public $notify_students = true;

    // Prefilled subject ID (for when creating from subject page)
    public $prefilledSubjectId = null;

    // Available options
    public $subjects = [];
    public $examTypes = [
        ['label' => 'Quiz', 'value' => 'quiz'],
        ['label' => 'Midterm', 'value' => 'midterm'],
        ['label' => 'Final', 'value' => 'final'],
        ['label' => 'Assignment', 'value' => 'assignment'],
        ['label' => 'Project', 'value' => 'project']
    ];

    public function mount($subject_id = null): void
    {
        // Set default date to tomorrow
        $this->date = Carbon::tomorrow()->format('Y-m-d');

        // Set prefilled subject if provided
        if ($subject_id) {
            $this->prefilledSubjectId = $subject_id;
            $this->subject_id = $subject_id;
        }

        // Load available subjects
        $this->loadSubjects();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed create exam page',
            Exam::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Load available subjects for dropdown
    private function loadSubjects(): void
    {
        $teacherProfile = Auth::user()->teacherProfile;

        // If no teacher profile, create empty array
        if (!$teacherProfile) {
            $this->subjects = [];
            return;
        }

        try {
            // Try to get subjects assigned to this teacher
            if (method_exists($teacherProfile, 'subjects')) {
                $this->subjects = $teacherProfile->subjects()
                    ->orderBy('name')
                    ->get()
                    ->map(function ($subject) {
                        return [
                            'id' => $subject->id,
                            'name' => $subject->name . ' (' . $subject->code . ')'
                        ];
                    })
                    ->toArray();
            } else {
                // Fallback to all subjects
                $this->subjects = Subject::orderBy('name')
                    ->take(30)
                    ->get()
                    ->map(function ($subject) {
                        return [
                            'id' => $subject->id,
                            'name' => $subject->name . ' (' . $subject->code . ')'
                        ];
                    })
                    ->toArray();
            }
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading subjects: ' . $e->getMessage(),
                TeacherProfile::class,
                Auth::user()->teacherProfile->id,
                ['ip' => request()->ip()]
            );

            // Set empty array
            $this->subjects = [];
        }
    }

    // Create exam
    public function save(): void
    {
        // Validate form
        $this->validate();

        // Additional validation for marks
        if ($this->passing_mark > $this->total_marks) {
            $this->addError('passing_mark', 'Passing mark cannot be greater than total marks.');
            return;
        }

        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            return;
        }

        try {
            // Handle file upload if there's an attachment
            $attachmentPath = null;
            if ($this->attachment) {
                $attachmentPath = $this->attachment->store('exam-attachments', 'public');
            }

            // Create exam
            $exam = Exam::create([
                'teacher_profile_id' => $teacherProfile->id,
                'subject_id' => $this->subject_id,
                'date' => $this->date,
                'time' => $this->time,
                'title' => $this->title,
                'type' => $this->type,
                'duration' => $this->duration,
                'passing_mark' => $this->passing_mark,
                'total_marks' => $this->total_marks,
                'description' => $this->description,
                'instructions' => $this->instructions,
                'location' => $this->is_online ? null : $this->location,
                'is_online' => $this->is_online,
                'attachment' => $attachmentPath,
                'is_graded' => false,
            ]);

            // Notify students if selected
            if ($this->notify_students) {
                // Send notifications to enrolled students
                // Implementation would depend on your notification system
                // This is a placeholder for that logic
                if (method_exists($exam->subject, 'enrolledStudents')) {
                    $students = $exam->subject->enrolledStudents;
                    foreach ($students as $student) {
                        // Add notification code here
                    }
                }
            }

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                'Teacher created a new exam: ' . $this->title,
                Exam::class,
                $exam->id,
                ['ip' => request()->ip()]
            );

            $this->success('Exam created successfully.');

            // Redirect to exams list
            redirect()->route('teacher.exams.index');
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error creating exam: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip(), 'data' => [
                    'subject_id' => $this->subject_id,
                    'date' => $this->date,
                    'title' => $this->title
                ]]
            );

            $this->error('Failed to create exam: ' . $e->getMessage());
        }
    }

    // Cancel and go back
    public function cancel(): void
    {
        redirect()->route('teacher.exams.index');
    }

    // Format date in d/m/Y format
    public function formatDate($date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    // Toggle online exam
    public function updatedIsOnline(): void
    {
        if ($this->is_online) {
            $this->location = null;
        }
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Create New Exam" separator progress-indicator>
        <x-slot:subtitle>
            Schedule an exam, quiz, or assessment for your students
        </x-slot:subtitle>
    </x-header>

    <x-card>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <!-- Basic Exam Information -->
            <div>
                <h3 class="mb-4 text-lg font-medium">Exam Information</h3>

                <div class="space-y-4">
                    <x-select
                        label="Subject"
                        wire:model="subject_id"
                        :options="$subjects"
                        option-label="name"
                        option-value="id"
                        placeholder="Select a subject"
                        :disabled="!empty($prefilledSubjectId)"
                        hint="Select the subject for this exam"
                        required
                    />

                    <x-input
                        label="Title"
                        wire:model="title"
                        placeholder="e.g. Midterm Examination"
                        hint="Descriptive title for this exam"
                        required
                    />

                    <x-select
                        label="Type"
                        wire:model="type"
                        :options="$examTypes"
                        option-label="label"
                        option-value="value"
                        hint="Select the type of assessment"
                        required
                    />

                    <x-textarea
                        label="Description"
                        wire:model="description"
                        placeholder="Brief description of the exam content and topics covered..."
                        hint="Provides students with information about what to expect"
                        rows="4"
                    />
                </div>
            </div>

            <!-- Date, Time and Marks -->
            <div>
                <h3 class="mb-4 text-lg font-medium">Schedule and Scoring</h3>

                <div class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-input
                            type="date"
                            label="Date"
                            wire:model="date"
                            min="{{ now()->format('Y-m-d') }}"
                            hint="Exam date"
                            required
                        />

                        <x-input
                            type="time"
                            label="Time (optional)"
                            wire:model="time"
                            hint="Start time if applicable"
                        />
                    </div>

                    <x-input
                        type="number"
                        label="Duration (minutes)"
                        wire:model="duration"
                        min="1"
                        max="480"
                        hint="How long students have to complete the exam"
                        required
                    />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-input
                            type="number"
                            label="Total Marks"
                            wire:model="total_marks"
                            min="0"
                            hint="Maximum possible score"
                            required
                        />

                        <x-input
                            type="number"
                            label="Passing Mark"
                            wire:model="passing_mark"
                            min="0"
                            max="{{ $total_marks }}"
                            hint="Minimum score to pass"
                            required
                        />
                    </div>
                </div>
            </div>

            <!-- Location -->
            <div>
                <h3 class="mb-4 text-lg font-medium">Location</h3>

                <div class="p-4 border rounded-lg border-base-300">
                    <x-toggle
                        label="Online Exam"
                        wire:model.live="is_online"
                        hint="Toggle for online or in-person exam"
                    />

                    @if (!$is_online)
                        <div class="mt-4">
                            <x-input
                                label="Exam Location"
                                wire:model="location"
                                placeholder="e.g. Room 101, Main Building"
                                hint="Where the exam will take place"
                            />
                        </div>
                    @endif
                </div>
            </div>

            <!-- Additional Information -->
            <div>
                <h3 class="mb-4 text-lg font-medium">Additional Information</h3>

                <div class="space-y-4">
                    <x-textarea
                        label="Instructions"
                        wire:model="instructions"
                        placeholder="Specific instructions for students taking the exam..."
                        hint="Special requirements, materials needed, rules, etc."
                        rows="4"
                    />

                    <div>
                        <label class="block mb-2 text-sm font-medium">Attachment (optional)</label>
                        <input
                            type="file"
                            wire:model="attachment"
                            class="w-full file-input file-input-bordered"
                            accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip"
                        />
                        <div class="mt-1 text-xs text-gray-500">
                            Upload exam materials, study guides, or resources (max 5MB)
                        </div>

                        @error('attachment')
                            <div class="mt-1 text-sm text-error">{{ $message }}</div>
                        @enderror
                    </div>

                    <x-toggle
                        label="Notify Students"
                        wire:model="notify_students"
                        hint="Send notifications to enrolled students about this exam"
                    />
                </div>
            </div>
        </div>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-button
                    label="Cancel"
                    icon="o-x-mark"
                    wire:click="cancel"
                />

                <x-button
                    label="Create Exam"
                    icon="o-check"
                    wire:click="save"
                    class="btn-primary"
                />
            </div>
        </x-slot:footer>
    </x-card>
</div>
