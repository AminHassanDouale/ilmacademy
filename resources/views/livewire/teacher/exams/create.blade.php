<?php

use App\Models\Exam;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Exam')] class extends Component {
    use Toast;

    // Current teacher profile
    public ?TeacherProfile $teacherProfile = null;

    // Form data
    public string $subject_id = '';
    public string $title = '';
    public string $exam_date = '';
    public string $type = '';
    public string $academic_year_id = '';
    public string $description = '';
    public string $instructions = '';
    public string $duration = '';
    public string $total_marks = '';

    // Options
    public array $subjectOptions = [];
    public array $typeOptions = [];
    public array $academicYearOptions = [];

    // Validation helpers
    public array $validTypes = ['quiz', 'midterm', 'final', 'assignment', 'project', 'practical'];

    // Mount the component
    public function mount(?string $subject = null): void
    {
        $this->teacherProfile = Auth::user()->teacherProfile;

        if (!$this->teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            $this->redirect(route('teacher.profile.edit'));
            return;
        }

        // Pre-select subject if provided
        if ($subject) {
            $subjectModel = Subject::find($subject);
            if ($subjectModel && $this->teacherProfile->subjects->contains($subjectModel)) {
                $this->subject_id = $subject;
            }
        }

        // Set default values
        $this->exam_date = now()->addWeek()->format('Y-m-d');
        $this->type = 'quiz';
        $this->duration = '60';
        $this->total_marks = '100';

        Log::info('Exam Create Component Mounted', [
            'teacher_user_id' => Auth::id(),
            'teacher_profile_id' => $this->teacherProfile->id,
            'preselected_subject' => $subject,
            'ip' => request()->ip()
        ]);

        $this->loadOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed exam create page',
            Exam::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Load form options
    protected function loadOptions(): void
    {
        try {
            // Load teacher's subjects
            $subjects = $this->teacherProfile->subjects()->orderBy('name')->get();
            $this->subjectOptions = $subjects->map(fn($subject) => [
                'id' => $subject->id,
                'name' => "{$subject->name} ({$subject->code})",
                'curriculum' => $subject->curriculum ? $subject->curriculum->name : 'No Curriculum'
            ])->toArray();

            // Exam type options
            $this->typeOptions = [
                ['id' => 'quiz', 'name' => 'Quiz', 'description' => 'Short assessment or test'],
                ['id' => 'midterm', 'name' => 'Midterm', 'description' => 'Mid-semester examination'],
                ['id' => 'final', 'name' => 'Final', 'description' => 'Final examination'],
                ['id' => 'assignment', 'name' => 'Assignment', 'description' => 'Take-home assignment'],
                ['id' => 'project', 'name' => 'Project', 'description' => 'Long-term project assessment'],
                ['id' => 'practical', 'name' => 'Practical', 'description' => 'Hands-on practical exam'],
            ];

            // Load academic years
            $academicYears = AcademicYear::orderBy('start_date', 'desc')->get();
            $currentYear = AcademicYear::where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->first();

            $this->academicYearOptions = $academicYears->map(fn($year) => [
                'id' => $year->id,
                'name' => $year->name,
                'is_current' => $currentYear && $currentYear->id === $year->id
            ])->toArray();

            // Pre-select current academic year
            if ($currentYear) {
                $this->academic_year_id = (string) $currentYear->id;
            }

            Log::info('Exam Create Options Loaded', [
                'subjects_count' => count($this->subjectOptions),
                'academic_years_count' => count($this->academicYearOptions),
                'current_year_id' => $currentYear?->id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Load Exam Create Options', [
                'error' => $e->getMessage()
            ]);

            $this->subjectOptions = [];
            $this->typeOptions = [];
            $this->academicYearOptions = [];
        }
    }

    // Save the exam
    public function save(): void
    {
        Log::info('Exam Create Started', [
            'teacher_user_id' => Auth::id(),
            'teacher_profile_id' => $this->teacherProfile->id,
            'form_data' => [
                'subject_id' => $this->subject_id,
                'title' => $this->title,
                'exam_date' => $this->exam_date,
                'type' => $this->type,
            ]
        ]);

        try {
            // Validate form data
            Log::debug('Starting Validation');

            $validated = $this->validate([
                'subject_id' => 'required|integer|exists:subjects,id',
                'title' => 'required|string|max:255',
                'exam_date' => 'required|date|after_or_equal:today',
                'type' => 'required|string|in:' . implode(',', $this->validTypes),
                'academic_year_id' => 'required|integer|exists:academic_years,id',
                'description' => 'nullable|string|max:1000',
                'instructions' => 'nullable|string|max:2000',
                'duration' => 'nullable|integer|min:1|max:480',
                'total_marks' => 'nullable|numeric|min:1|max:1000',
            ], [
                'subject_id.required' => 'Please select a subject.',
                'subject_id.exists' => 'The selected subject is invalid.',
                'title.required' => 'Please enter an exam title.',
                'title.max' => 'Exam title must not exceed 255 characters.',
                'exam_date.required' => 'Please select an exam date.',
                'exam_date.after_or_equal' => 'Exam date must be today or in the future.',
                'type.required' => 'Please select an exam type.',
                'type.in' => 'The selected exam type is invalid.',
                'academic_year_id.required' => 'Please select an academic year.',
                'academic_year_id.exists' => 'The selected academic year is invalid.',
                'description.max' => 'Description must not exceed 1000 characters.',
                'instructions.max' => 'Instructions must not exceed 2000 characters.',
                'duration.integer' => 'Duration must be a valid number.',
                'duration.min' => 'Duration must be at least 1 minute.',
                'duration.max' => 'Duration must not exceed 480 minutes (8 hours).',
                'total_marks.numeric' => 'Total marks must be a valid number.',
                'total_marks.min' => 'Total marks must be at least 1.',
                'total_marks.max' => 'Total marks must not exceed 1000.',
            ]);

            // Check if teacher is assigned to the subject
            if (!$this->teacherProfile->subjects()->where('subjects.id', $validated['subject_id'])->exists()) {
                $this->addError('subject_id', 'You are not assigned to teach this subject.');
                return;
            }

            // Check if exam date is reasonable (not too far in the future)
            $examDate = \Carbon\Carbon::parse($validated['exam_date']);
            if ($examDate->isAfter(now()->addYear())) {
                $this->addError('exam_date', 'Exam date should not be more than a year in the future.');
                return;
            }

            Log::info('Validation Passed', ['validated_data' => $validated]);

            // Prepare exam data
            $examData = [
                'subject_id' => $validated['subject_id'],
                'teacher_profile_id' => $this->teacherProfile->id,
                'academic_year_id' => $validated['academic_year_id'],
                'title' => $validated['title'],
                'exam_date' => $examDate,
                'type' => $validated['type'],
                'description' => $validated['description'] ?: null,
                'instructions' => $validated['instructions'] ?: null,
                'duration' => $validated['duration'] ? (int) $validated['duration'] : null,
                'total_marks' => $validated['total_marks'] ? (float) $validated['total_marks'] : null,
            ];

            Log::info('Prepared Exam Data', ['exam_data' => $examData]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Create exam
            Log::debug('Creating Exam Record');
            $exam = Exam::create($examData);
            Log::info('Exam Created Successfully', [
                'exam_id' => $exam->id,
                'subject_id' => $exam->subject_id,
                'exam_date' => $exam->exam_date->toDateString()
            ]);

            // Get subject and academic year for logging
            $subject = Subject::find($validated['subject_id']);
            $academicYear = AcademicYear::find($validated['academic_year_id']);

            // Log activity
            Log::debug('Logging Activity');
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created {$validated['type']} exam '{$validated['title']}' for {$subject->name} ({$subject->code}) on {$examDate->format('M d, Y')}",
                Exam::class,
                $exam->id,
                [
                    'exam_id' => $exam->id,
                    'exam_title' => $validated['title'],
                    'subject_name' => $subject->name,
                    'subject_code' => $subject->code,
                    'exam_type' => $validated['type'],
                    'exam_date' => $examDate->toDateString(),
                    'academic_year' => $academicYear->name,
                    'duration' => $validated['duration'],
                    'total_marks' => $validated['total_marks'],
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Show success toast
            $this->success("Exam '{$validated['title']}' has been successfully created.");
            Log::info('Success Toast Displayed');

            // Redirect to exam show page
            Log::info('Redirecting to Exam Show Page', [
                'exam_id' => $exam->id,
                'route' => 'teacher.exams.show'
            ]);

            $this->redirect(route('teacher.exams.show', $exam->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'subject_id' => $this->subject_id,
                    'title' => $this->title,
                    'exam_date' => $this->exam_date,
                    'type' => $this->type,
                ]
            ]);

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Exam Creation Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'teacher_profile_id' => $this->teacherProfile->id,
                'form_data' => [
                    'subject_id' => $this->subject_id,
                    'title' => $this->title,
                    'exam_date' => $this->exam_date,
                    'type' => $this->type,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get selected subject details
    public function getSelectedSubjectProperty(): ?array
    {
        if ($this->subject_id) {
            return collect($this->subjectOptions)->firstWhere('id', (int) $this->subject_id);
        }

        return null;
    }

    // Get selected academic year details
    public function getSelectedAcademicYearProperty(): ?array
    {
        if ($this->academic_year_id) {
            return collect($this->academicYearOptions)->firstWhere('id', (int) $this->academic_year_id);
        }

        return null;
    }

    // Get duration in hours and minutes
    public function getFormattedDurationProperty(): ?string
    {
        if ($this->duration && is_numeric($this->duration)) {
            $minutes = (int) $this->duration;

            if ($minutes >= 60) {
                $hours = floor($minutes / 60);
                $remainingMinutes = $minutes % 60;
                return $remainingMinutes > 0 ? "{$hours}h {$remainingMinutes}m" : "{$hours}h";
            }

            return "{$minutes}m";
        }

        return null;
    }

    public function with(): array
    {
        return [
            'selectedSubject' => $this->selectedSubject,
            'selectedAcademicYear' => $this->selectedAcademicYear,
            'formattedDuration' => $this->formattedDuration,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Create New Exam" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('teacher.exams.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Exam Information">
                <form wire:submit="save" class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Subject -->
                        <div class="md:col-span-2">
                            <label class="block mb-2 text-sm font-medium text-gray-700">Subject *</label>
                            <select
                                wire:model.live="subject_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                <option value="">Select a subject</option>
                                @foreach($subjectOptions as $subject)
                                    <option value="{{ $subject['id'] }}">{{ $subject['name'] }}</option>
                                @endforeach
                            </select>
                            @if($selectedSubject)
                                <p class="mt-1 text-xs text-gray-500">{{ $selectedSubject['curriculum'] }}</p>
                            @endif
                            @error('subject_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Exam Title -->
                        <div class="md:col-span-2">
                            <x-input
                                label="Exam Title"
                                wire:model.live="title"
                                placeholder="Enter exam title (e.g., Midterm Exam - Chapter 1-5)"
                                required
                            />
                            @error('title')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Exam Type -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Exam Type *</label>
                            <select
                                wire:model.live="type"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                @foreach($typeOptions as $type)
                                    <option value="{{ $type['id'] }}">{{ $type['name'] }}</option>
                                @endforeach
                            </select>
                            @if($type)
                                @php
                                    $selectedType = collect($typeOptions)->firstWhere('id', $type);
                                @endphp
                                @if($selectedType)
                                    <p class="mt-1 text-xs text-gray-500">{{ $selectedType['description'] }}</p>
                                @endif
                            @endif
                            @error('type')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Academic Year -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Academic Year *</label>
                            <select
                                wire:model.live="academic_year_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                <option value="">Select academic year</option>
                                @foreach($academicYearOptions as $year)
                                    <option value="{{ $year['id'] }}">
                                        {{ $year['name'] }}
                                        @if($year['is_current'])
                                            (Current)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('academic_year_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Exam Date -->
                        <div>
                            <x-input
                                label="Exam Date"
                                wire:model.live="exam_date"
                                type="date"
                                required
                            />
                            @error('exam_date')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Duration -->
                        <div>
                            <x-input
                                label="Duration (minutes)"
                                wire:model.live="duration"
                                type="number"
                                min="1"
                                max="480"
                                placeholder="e.g., 60"
                            />
                            @if($formattedDuration)
                                <p class="mt-1 text-xs text-green-600">{{ $formattedDuration }}</p>
                            @endif
                            @error('duration')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Total Marks -->
                        <div>
                            <x-input
                                label="Total Marks"
                                wire:model.live="total_marks"
                                type="number"
                                min="1"
                                max="1000"
                                step="0.5"
                                placeholder="e.g., 100"
                            />
                            @error('total_marks')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Description -->
                        <div class="md:col-span-2">
                            <x-textarea
                                label="Description"
                                wire:model.live="description"
                                placeholder="Optional: Brief description of the exam content and scope"
                                rows="3"
                            />
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Instructions -->
                        <div class="md:col-span-2">
                            <x-textarea
                                label="Instructions"
                                wire:model.live="instructions"
                                placeholder="Optional: Exam instructions for students (what to bring, rules, etc.)"
                                rows="4"
                            />
                            @error('instructions')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end pt-6">
                        <x-button
                            label="Cancel"
                            link="{{ route('teacher.exams.index') }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Create Exam"
                            icon="o-plus"
                            type="submit"
                            color="primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Preview and Help -->
        <div class="space-y-6">
            <!-- Exam Preview -->
            <x-card title="Exam Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="space-y-3 text-sm">
                        <div>
                            <strong>Subject:</strong>
                            @if($selectedSubject)
                                <div class="mt-1">{{ $selectedSubject['name'] }}</div>
                                <div class="text-xs text-gray-500">{{ $selectedSubject['curriculum'] }}</div>
                            @else
                                <span class="text-gray-500">No subject selected</span>
                            @endif
                        </div>

                        <div>
                            <strong>Title:</strong>
                            <div class="mt-1">{{ $title ?: 'Exam Title' }}</div>
                        </div>

                        <div>
                            <strong>Type:</strong>
                            @if($type)
                                @php
                                    $selectedType = collect($typeOptions)->firstWhere('id', $type);
                                @endphp
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ml-2 {{ match($type) {
                                    'quiz' => 'bg-green-100 text-green-800',
                                    'midterm' => 'bg-yellow-100 text-yellow-800',
                                    'final' => 'bg-red-100 text-red-800',
                                    'assignment' => 'bg-blue-100 text-blue-800',
                                    'project' => 'bg-purple-100 text-purple-800',
                                    'practical' => 'bg-orange-100 text-orange-800',
                                    default => 'bg-gray-100 text-gray-600'
                                } }}">
                                    {{ $selectedType['name'] ?? ucfirst($type) }}
                                </span>
                            @else
                                <span class="text-gray-500">No type selected</span>
                            @endif
                        </div>

                        @if($exam_date)
                            <div>
                                <strong>Date:</strong>
                                <div class="mt-1">
                                    {{ \Carbon\Carbon::parse($exam_date)->format('l, M d, Y') }}
                                </div>
                                <div class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($exam_date)->diffForHumans() }}</div>
                            </div>
                        @endif

                        @if($formattedDuration)
                            <div>
                                <strong>Duration:</strong>
                                <div class="mt-1">{{ $formattedDuration }}</div>
                            </div>
                        @endif

                        @if($total_marks)
                            <div>
                                <strong>Total Marks:</strong>
                                <div class="mt-1">{{ $total_marks }} marks</div>
                            </div>
                        @endif

                        @if($selectedAcademicYear)
                            <div>
                                <strong>Academic Year:</strong>
                                <div class="mt-1">{{ $selectedAcademicYear['name'] }}</div>
                            </div>
                        @endif

                        @if($description)
                            <div>
                                <strong>Description:</strong>
                                <div class="p-2 mt-1 text-xs rounded bg-gray-50">{{ $description }}</div>
                            </div>
                        @endif

                        @if($instructions)
                            <div>
                                <strong>Instructions:</strong>
                                <div class="p-2 mt-1 text-xs rounded bg-gray-50">{{ $instructions }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Exam Type Guide -->
            <x-card title="Exam Types">
                <div class="space-y-3 text-sm">
                    @foreach($typeOptions as $typeOption)
                        <div class="flex items-start">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium mr-3 {{ match($typeOption['id']) {
                                'quiz' => 'bg-green-100 text-green-800',
                                'midterm' => 'bg-yellow-100 text-yellow-800',
                                'final' => 'bg-red-100 text-red-800',
                                'assignment' => 'bg-blue-100 text-blue-800',
                                'project' => 'bg-purple-100 text-purple-800',
                                'practical' => 'bg-orange-100 text-orange-800',
                                default => 'bg-gray-100 text-gray-600'
                            } }}">
                                {{ $typeOption['name'] }}
                            </span>
                            <div>
                                <div class="font-medium">{{ $typeOption['name'] }}</div>
                                <div class="text-xs text-gray-600">{{ $typeOption['description'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <!-- Guidelines -->
            <x-card title="Exam Guidelines">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Scheduling</div>
                        <p class="text-gray-600">Schedule exams at least one day in advance. Consider student preparation time and avoid conflicts with other subjects.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Duration Guidelines</div>
                        <ul class="mt-2 space-y-1 text-xs text-gray-600">
                            <li><strong>Quiz:</strong> 15-30 minutes</li>
                            <li><strong>Midterm:</strong> 60-90 minutes</li>
                            <li><strong>Final:</strong> 2-3 hours</li>
                            <li><strong>Assignment:</strong> Multiple days</li>
                        </ul>
                    </div>

                    <div>
                        <div class="font-semibold">Instructions</div>
                        <p class="text-gray-600">Include clear instructions about what students should bring, exam format, and any special rules.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Grading</div>
                        <p class="text-gray-600">After creating the exam, you can add exam results and grades for enrolled students.</p>
                    </div>
                </div>
            </x-card>

            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-2">
                    <x-button
                        label="View All Exams"
                        icon="o-document-text"
                        link="{{ route('teacher.exams.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="My Subjects"
                        icon="o-academic-cap"
                        link="{{ route('teacher.subjects.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Create Session"
                        icon="o-presentation-chart-line"
                        link="{{ route('teacher.sessions.create') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
