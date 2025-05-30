<?php

use App\Models\Exam;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Rule;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Exam')] class extends Component {
    use Toast;
    use WithFileUploads;

    // Exam model
    public Exam $exam;

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
    public $type;

    #[Rule('required|integer|min:1|max:480')]
    public $duration;

    #[Rule('required|integer|min:0|max:100')]
    public $passing_mark;

    #[Rule('required|integer|min:0')]
    public $total_marks;

    #[Rule('nullable|string')]
    public $description;

    #[Rule('nullable|string')]
    public $instructions;

    #[Rule('nullable|string')]
    public $location;

    #[Rule('boolean')]
    public $is_online;

    #[Rule('nullable|file|max:5120')]
    public $new_attachment;

    #[Rule('boolean')]
    public $notify_students = false;

    #[Rule('boolean')]
    public $remove_attachment = false;

    // Available options
    public $subjects = [];
    public $examTypes = [
        ['label' => 'Quiz', 'value' => 'quiz'],
        ['label' => 'Midterm', 'value' => 'midterm'],
        ['label' => 'Final', 'value' => 'final'],
        ['label' => 'Assignment', 'value' => 'assignment'],
        ['label' => 'Project', 'value' => 'project']
    ];

    // Exam status
    public $isUpcoming = false;
    public $canEditDate = false;

    public function mount(Exam $exam): void
    {
        $this->exam = $exam;

        // Check if teacher owns this exam
        $teacherProfile = Auth::user()->teacherProfile;
        $isOwner = $teacherProfile && $teacherProfile->id === $this->exam->teacher_profile_id;

        if (!$isOwner) {
            $this->error('You do not have permission to edit this exam.');
            redirect()->route('teacher.exams.index');
            return;
        }

        // Check if exam is upcoming
        $now = Carbon::now();
        $examDate = Carbon::parse($this->exam->date);

        $this->isUpcoming = $examDate->isAfter($now->startOfDay());
        $this->canEditDate = $this->isUpcoming;

        if (!$this->isUpcoming) {
            $this->error('You cannot edit a completed exam.');
            redirect()->route('teacher.exams.show', $this->exam->id);
            return;
        }

        // Fill form fields with exam data
        $this->subject_id = $this->exam->subject_id;
        $this->date = $this->exam->date->format('Y-m-d');
        $this->time = $this->exam->time;
        $this->title = $this->exam->title;
        $this->type = $this->exam->type;
        $this->duration = $this->exam->duration;
        $this->passing_mark = $this->exam->passing_mark;
        $this->total_marks = $this->exam->total_marks;
        $this->description = $this->exam->description;
        $this->instructions = $this->exam->instructions;
        $this->location = $this->exam->location;
        $this->is_online = $this->exam->is_online;

        // Set validation rules based on exam status
        $this->setCustomValidation();

        // Load available subjects
        $this->loadSubjects();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed edit exam page: ' . $this->exam->title,
            Exam::class,
            $this->exam->id,
            ['ip' => request()->ip()]
        );
    }

    // Set custom validation rules based on exam status
    private function setCustomValidation(): void
    {
        if (!$this->canEditDate) {
            $this->rules['date'] = 'required|date';
        }
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

    // Update exam
    public function save(): void
    {
        // Validate form
        $this->validate();

        // Additional validation for marks
        if ($this->passing_mark > $this->total_marks) {
            $this->addError('passing_mark', 'Passing mark cannot be greater than total marks.');
            return;
        }

        try {
            // Handle file upload if there's a new attachment
            $attachmentPath = $this->exam->attachment;

            // Remove attachment if requested
            if ($this->remove_attachment && $attachmentPath) {
                Storage::disk('public')->delete($attachmentPath);
                $attachmentPath = null;
            }

            // Upload new attachment
            if ($this->new_attachment) {
                if ($attachmentPath) {
                    Storage::disk('public')->delete($attachmentPath);
                }
                $attachmentPath = $this->new_attachment->store('exam-attachments', 'public');
            }

            // Update exam
            $this->exam->update([
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
            ]);

            // Notify students if selected
            if ($this->notify_students) {
                // Send notifications to enrolled students
                // Implementation would depend on your notification system
                // This is a placeholder for that logic
                if (method_exists($this->exam->subject, 'enrolledStudents')) {
                    $students = $this->exam->subject->enrolledStudents;
                    // Add notification code here
                }
            }

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                'Teacher updated exam: ' . $this->title,
                Exam::class,
                $this->exam->id,
                ['ip' => request()->ip()]
            );

            $this->success('Exam updated successfully.');

            // Redirect to exam details
            redirect()->route('teacher.exams.show', $this->exam->id);
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error updating exam: ' . $e->getMessage(),
                Exam::class,
                $this->exam->id,
                ['ip' => request()->ip()]
            );

            $this->error('Failed to update exam: ' . $e->getMessage());
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

    // Toggle online exam
    public function updatedIsOnline(): void
    {
        if ($this->is_online) {
            $this->location = null;
        }
    }

    // Get attachment filename
    public function getAttachmentFilename(): string
    {
        if (!$this->exam->attachment) {
            return '';
        }

        $path = $this->exam->attachment;
        $filename = basename($path);

        // Truncate long filenames
        if (strlen($filename) > 20) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $basename = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($basename, 0, 16) . '...' . ($extension ? '.' . $extension : '');
        }

        return $filename;
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Edit Exam" separator progress-indicator>
        <x-slot:subtitle>
            Update information for: {{ $exam->title }}
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
                            hint="{{ $canEditDate ? 'Exam date' : 'Date cannot be changed for past exams' }}"
                            :disabled="!$canEditDate"
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
                        <label class="block mb-2 text-sm font-medium">Attachment</label>
                        @if ($exam->attachment)
                            <div class="flex items-center justify-between p-2 mb-2 border rounded-lg border-base-300">
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-document-text" class="w-5 h-5 text-primary" />
                                    <span>{{ getAttachmentFilename() }}</span>
                                </div>
                                <div class="flex gap-2">
                                    <x-button
                                        icon="o-arrow-down-tray"
                                        size="xs"
                                        tooltip="Download"
                                        href="{{ Storage::url($exam->attachment) }}"
                                        target="_blank"
                                    />
                                    <x-button
                                        icon="o-trash"
                                        color="error"
                                        size="xs"
                                        tooltip="Remove"
                                        wire:click="$set('remove_attachment', true)"
                                    />
                                </div>
                            </div>
                        @endif

                        @if (!$exam->attachment || $remove_attachment)
                            <input
                                type="file"
                                wire:model="new_attachment"
                                class="w-full file-input file-input-bordered"
                                accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip"
                            />
                            <div class="mt-1 text-xs text-gray-500">
                                Upload exam materials, study guides, or resources (max 5MB)
                            </div>
                        @endif

                        @error('new_attachment')
                            <div class="mt-1 text-sm text-error">{{ $message }}</div>
                        @enderror
                    </div>

                    <x-toggle
                        label="Notify Students"
                        wire:model="notify_students"
                        hint="Send notifications to enrolled students about these changes"
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
                    label="Save Changes"
                    icon="o-check"
                    wire:click="save"
                    class="btn-primary"
                />
            </div>
        </x-slot:footer>
    </x-card>
</div>
