$this->academic_year_id = $this->exam->academic_year_id ? (string) $this->exam->academic_year_id : '';
        $this->description = $this->exam->description ?? '';
        $this->instructions = $this->exam->instructions ?? '';
        $this->duration = $this->exam->duration ? (string) $this->exam->duration : '';
        $this->total_marks = $this->exam->total_marks ? (string) $this->exam->total_marks : '';

        Log::info('Exam Data Loaded', [
            'exam_id' => $this->exam->id,
            'form_data' => [
                'subject_id' => $this->subject_id,
                'title' => $this->title,
                'exam_date' => $this->exam_date,
                'type' => $this->type,
            ]
        ]);
    }

    // Store original data for change tracking
    protected function storeOriginalData(): void
    {
        $this->originalData = [
            'subject_id' => $this->exam->subject_id,
            'title' => $this->exam->title,
            'exam_date' => $this->exam->exam_date->format('Y-m-d'),
            'type' => $this->exam->type ?? '',
            'academic_year_id' => $this->exam->academic_year_id ?? '',
            'description' => $this->exam->description ?? '',
            'instructions' => $this->exam->instructions ?? '',
            'duration' => $this->exam->duration ?? '',
            'total_marks' => $this->exam->total_marks ?? '',
        ];
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
            $this->academicYearOptions = $academicYears->map(fn($year) => [
                'id' => $year->id,
                'name' => $year->name,
                'is_current' => $year->start_date <= now() && $year->end_date >= now()
            ])->toArray();

            Log::info('Exam Edit Options Loaded', [
                'subjects_count' => count($this->subjectOptions),
                'academic_years_count' => count($this->academicYearOptions),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Load Exam Edit Options', [
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
        Log::info('Exam Update Started', [
            'teacher_user_id' => Auth::id(),
            'exam_id' => $this->exam->id,
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
                'exam_date' => 'required|date',
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

            // Check if exam date is reasonable
            $examDate = \Carbon\Carbon::parse($validated['exam_date']);
            if ($examDate->isAfter(now()->addYear())) {
                $this->addError('exam_date', 'Exam date should not be more than a year in the future.');
                return;
            }

            // Warning if exam has results and we're changing critical data
            if ($this->exam->examResults->count() > 0) {
                $criticalChanges = [];
                if ($this->originalData['subject_id'] != $validated['subject_id']) {
                    $criticalChanges[] = 'subject';
                }
                if ($this->originalData['total_marks'] != $validated['total_marks']) {
                    $criticalChanges[] = 'total marks';
                }
                if ($this->originalData['type'] != $validated['type']) {
                    $criticalChanges[] = 'exam type';
                }

                if (!empty($criticalChanges)) {
                    $this->addError('general', 'Warning: This exam has existing results. Changing ' . implode(', ', $criticalChanges) . ' may affect grade calculations.');
                }
            }

            // Track changes for activity log
            $changes = $this->getChanges($validated, $examDate);

            Log::info('Validation Passed', ['validated_data' => $validated, 'changes' => $changes]);

            // Prepare exam data
            $examData = [
                'subject_id' => $validated['subject_id'],
                'academic_year_id' => $validated['academic_year_id'],
                'title' => $validated['title'],
                'exam_date' => $examDate,
                'type' => $validated['type'],
                'description' => $validated['description'] ?: null,
                'instructions' => $validated['instructions'] ?: null,
                'duration' => $validated['duration'] ? (int) $validated['duration'] : null,
                'total_marks' => $validated['total_marks'] ? (float) $validated['total_marks'] : null,
            ];

            Log::info('Prepared Exam Data', ['exam_data' => $examData, 'changes' => $changes]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Update exam
            Log::debug('Updating Exam Record');
            $this->exam->update($examData);
            Log::info('Exam Updated Successfully', [
                'exam_id' => $this->exam->id,
                'subject_id' => $this->exam->subject_id,
                'exam_date' => $this->exam->exam_date->toDateString()
            ]);

            // Get subject and academic year for logging
            $subject = Subject::find($validated['subject_id']);
            $academicYear = AcademicYear::find($validated['academic_year_id']);

            // Log activity with changes
            if (!empty($changes)) {
                $changeDescription = "Updated exam '{$this->exam->title}' for {$subject->name} ({$subject->code}). Changes: " . implode(', ', $changes);

                ActivityLog::log(
                    Auth::id(),
                    'update',
                    $changeDescription,
                    Exam::class,
                    $this->exam->id,
                    [
                        'exam_id' => $this->exam->id,
                        'changes' => $changes,
                        'original_data' => $this->originalData,
                        'new_data' => $validated,
                        'exam_title' => $this->exam->title,
                        'subject_name' => $subject->name,
                        'subject_code' => $subject->code,
                        'exam_type' => $validated['type'],
                        'exam_date' => $examDate->toDateString(),
                        'academic_year' => $academicYear->name,
                        'has_results' => $this->exam->examResults->count() > 0,
                    ]
                );
            }

            DB::commit();
            Log::info('Database Transaction Committed');

            // Update original data for future comparisons
            $this->storeOriginalData();

            // Show success toast
            $this->success("Exam '{$this->exam->title}' has been successfully updated.");
            Log::info('Success Toast Displayed');

            // Redirect to exam show page
            Log::info('Redirecting to Exam Show Page', [
                'exam_id' => $this->exam->id,
                'route' => 'teacher.exams.show'
            ]);

            $this->redirect(route('teacher.exams.show', $this->exam->id));

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
            Log::error('Exam Update Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'exam_id' => $this->exam->id,
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

    // Get changes between original and new data
    protected function getChanges(array $newData, $examDate): array
    {
        $changes = [];

        // Map form fields to human-readable names
        $fieldMap = [
            'subject_id' => 'Subject',
            'title' => 'Title',
            'exam_date' => 'Exam Date',
            'type' => 'Exam Type',
            'academic_year_id' => 'Academic Year',
            'description' => 'Description',
            'instructions' => 'Instructions',
            'duration' => 'Duration',
            'total_marks' => 'Total Marks',
        ];

        // Compare basic fields
        foreach ($newData as $field => $newValue) {
            $originalValue = $this->originalData[$field] ?? null;

            // Handle special cases
            if ($field === 'subject_id') {
                if ($originalValue != $newValue) {
                    $oldSubject = Subject::find($originalValue);
                    $newSubject = Subject::find($newValue);
                    $changes[] = "Subject from {$oldSubject->name} to {$newSubject->name}";
                }
            } elseif ($field === 'academic_year_id') {
                if ($originalValue != $newValue) {
                    $oldYear = $originalValue ? AcademicYear::find($originalValue)->name : 'None';
                    $newYear = AcademicYear::find($newValue)->name;
                    $changes[] = "Academic Year from {$oldYear} to {$newYear}";
                }
            } elseif ($field === 'exam_date') {
                $originalDate = $this->originalData['exam_date'];
                $newDate = $examDate->format('Y-m-d');
                if ($originalDate != $newDate) {
                    $changes[] = "Date from {$originalDate} to {$newDate}";
                }
            } elseif ($originalValue != $newValue) {
                $fieldName = $fieldMap[$field] ?? $field;
                if (empty($originalValue) && !empty($newValue)) {
                    $changes[] = "{$fieldName} added";
                } elseif (!empty($originalValue) && empty($newValue)) {
                    $changes[] = "{$fieldName} removed";
                } else {
                    $changes[] = "{$fieldName} updated";
                }
            }
        }

        return $changes;
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

    // Get exam status
    public function getExamStatusProperty(): array
    {
        $now = now();

        if ($this->exam->exam_date > $now) {
            return ['status' => 'upcoming', 'color' => 'bg-blue-100 text-blue-800', 'text' => 'Upcoming'];
        } elseif ($this->exam->examResults->count() > 0) {
            return ['status' => 'graded', 'color' => 'bg-green-100 text-green-800', 'text' => 'Graded'];
        } else {
            return ['status' => 'pending', 'color' => 'bg-yellow-100 text-yellow-800', 'text' => 'Pending Results'];
        }
    }

    public function with(): array
    {
        return [
            'selectedSubject' => $this->selectedSubject,
            'selectedAcademicYear' => $this->selectedAcademicYear,
            'formattedDuration' => $this->formattedDuration,
            'examStatus' => $this->examStatus,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Edit Exam: {{ $exam->title }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Exam Status -->
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $examStatus['color'] }}">
                {{ $examStatus['text'] }}
            </span>
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

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Exam Information">
                <form wire:submit="save" class="space-y-6">
                    @error('general')
                        <div class="p-4 border border-orange-200 rounded-md bg-orange-50">
                            <div class="flex">
                                <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-orange-400 mr-3 mt-0.5" />
                                <div class="text-sm text-orange-700">{{ $message }}</div>
                            </div>
                        </div>
                    @enderror

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
                                placeholder="Enter exam title"
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
                                placeholder="Brief description of the exam content and scope"
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
                                placeholder="Exam instructions for students (what to bring, rules, etc.)"
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
                            link="{{ route('teacher.exams.show', $exam->id) }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Update Exam"
                            icon="o-check"
                            type="submit"
                            color="primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Preview and Info -->
        <div class="space-y-6">
            <!-- Current Exam Info -->
            <x-card title="Current Exam">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Subject</div>
                        <div class="font-semibold">{{ $exam->subject->name }}</div>
                        <div class="text-xs text-gray-500">{{ $exam->subject->code }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Current Date</div>
                        <div>{{ $exam->exam_date->format('l, M d, Y') }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Current Status</div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $examStatus['color'] }}">
                            {{ $examStatus['text'] }}
                        </span>
                    </div>

                    @if($exam->examResults->count() > 0)
                        <div>
                            <div class="font-medium text-gray-500">Results</div>
                            <div class="text-orange-600">
                                <x-icon name="o-exclamation-triangle" class="inline w-4 h-4 mr-1" />
                                {{ $exam->examResults->count() }} results exist
                            </div>
                            <div class="text-xs text-gray-500">Be careful when changing exam details</div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Updated Preview -->
            <x-card title="Updated Preview">
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
                                <strong>Updated Date:</strong>
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

            <!-- Edit Guidelines -->
            <x-card title="Edit Guidelines">
                <div class="space-y-4 text-sm">
                    @if($exam->examResults->count() > 0)
                        <div>
                            <div class="font-semibold text-orange-600">Results Warning</div>
                            <p class="text-gray-600">This exam has {{ $exam->examResults->count() }} existing results. Changing critical details like total marks or exam type may affect grade calculations.</p>
                        </div>
                    @endif

                    <div>
                        <div class="font-semibold">Date Changes</div>
                        <p class="text-gray-600">
                            @if($examStatus['status'] === 'upcoming')
                                You can change the exam date, but notify students about any changes.
                            @elseif($examStatus['status'] === 'pending')
                                This exam has passed. Date changes are for record-keeping only.
                            @else
                                This exam is completed with results. Date changes won't affect grades.
                            @endif
                        </p>
                    </div>

                    <div>
                        <div class="font-semibold">Subject Changes</div>
                        <p class="text-gray-600">Changing the subject will move this exam to a different subject. Ensure you're assigned to the new subject.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Marks Changes</div>
                        <p class="text-gray-600">If results exist, changing total marks may require recalculating grades and percentages.</p>
                    </div>
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
                    @if($exam->examResults->count() > 0)
                        <x-button
                            label="Manage Results"
                            icon="o-chart-bar"
                            link="{{ route('teacher.exams.results', $exam->id) }}"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif
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
        </div>
    </div>
</div>
