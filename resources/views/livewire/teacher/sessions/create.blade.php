<?php

use App\Models\Session;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\Room;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Session')] class extends Component {
    use Toast;

    // Current teacher profile
    public ?TeacherProfile $teacherProfile = null;

    // Form data
    public string $subject_id = '';
    public string $start_date = '';
    public string $start_time = '';
    public string $end_time = '';
    public string $type = '';
    public string $link = '';
    public string $classroom_id = '';
    public string $description = '';

    // Options
    public array $subjectOptions = [];
    public array $typeOptions = [];
    public array $roomOptions = [];

    // Validation helpers
    public array $validTypes = ['lecture', 'practical', 'tutorial', 'lab', 'seminar'];

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
        $this->start_date = now()->addDay()->format('Y-m-d');
        $this->type = 'lecture';

        Log::info('Session Create Component Mounted', [
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
            'Accessed session create page',
            Session::class,
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

            // Session type options
            $this->typeOptions = [
                ['id' => 'lecture', 'name' => 'Lecture', 'description' => 'Traditional classroom teaching'],
                ['id' => 'practical', 'name' => 'Practical', 'description' => 'Hands-on practice session'],
                ['id' => 'tutorial', 'name' => 'Tutorial', 'description' => 'Small group discussion'],
                ['id' => 'lab', 'name' => 'Lab', 'description' => 'Laboratory work'],
                ['id' => 'seminar', 'name' => 'Seminar', 'description' => 'Presentation and discussion'],
            ];

            // Load available rooms
            $rooms = Room::orderBy('name')->get();
            $this->roomOptions = [
                ['id' => '', 'name' => 'No room assigned'],
                ...$rooms->map(fn($room) => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'capacity' => $room->capacity,
                    'location' => $room->location,
                    'building' => $room->building
                ])->toArray()
            ];

            Log::info('Session Create Options Loaded', [
                'subjects_count' => count($this->subjectOptions),
                'rooms_count' => count($this->roomOptions) - 1, // Exclude "No room"
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Load Session Create Options', [
                'error' => $e->getMessage()
            ]);

            $this->subjectOptions = [];
            $this->typeOptions = [];
            $this->roomOptions = [['id' => '', 'name' => 'No room assigned']];
        }
    }

    // Save the session
    public function save(): void
    {
        Log::info('Session Create Started', [
            'teacher_user_id' => Auth::id(),
            'teacher_profile_id' => $this->teacherProfile->id,
            'form_data' => [
                'subject_id' => $this->subject_id,
                'start_date' => $this->start_date,
                'start_time' => $this->start_time,
                'end_time' => $this->end_time,
                'type' => $this->type,
            ]
        ]);

        try {
            // Validate form data
            Log::debug('Starting Validation');

            $validated = $this->validate([
                'subject_id' => 'required|integer|exists:subjects,id',
                'start_date' => 'required|date|after_or_equal:today',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
                'type' => 'required|string|in:' . implode(',', $this->validTypes),
                'link' => 'nullable|url|max:500',
                'classroom_id' => 'nullable|integer|exists:rooms,id',
                'description' => 'nullable|string|max:1000',
            ], [
                'subject_id.required' => 'Please select a subject.',
                'subject_id.exists' => 'The selected subject is invalid.',
                'start_date.required' => 'Please select a start date.',
                'start_date.after_or_equal' => 'Start date must be today or in the future.',
                'start_time.required' => 'Please enter a start time.',
                'start_time.date_format' => 'Start time must be in HH:MM format.',
                'end_time.required' => 'Please enter an end time.',
                'end_time.date_format' => 'End time must be in HH:MM format.',
                'end_time.after' => 'End time must be after start time.',
                'type.required' => 'Please select a session type.',
                'type.in' => 'The selected session type is invalid.',
                'link.url' => 'Please enter a valid URL.',
                'link.max' => 'Link must not exceed 500 characters.',
                'classroom_id.exists' => 'The selected room is invalid.',
                'description.max' => 'Description must not exceed 1000 characters.',
            ]);

            // Check if teacher is assigned to the subject
            if (!$this->teacherProfile->subjects()->where('subjects.id', $validated['subject_id'])->exists()) {
                $this->addError('subject_id', 'You are not assigned to teach this subject.');
                return;
            }

            // Combine date and time
            $startDateTime = \Carbon\Carbon::parse($validated['start_date'] . ' ' . $validated['start_time']);
            $endDateTime = \Carbon\Carbon::parse($validated['start_date'] . ' ' . $validated['end_time']);

            // Check if start time is in the future (at least 1 hour from now)
            if ($startDateTime->isBefore(now()->addHour())) {
                $this->addError('start_time', 'Session must be scheduled at least 1 hour in advance.');
                return;
            }

            // Check for room conflicts if room is selected
            if ($validated['classroom_id']) {
                $conflictingSession = Session::where('classroom_id', $validated['classroom_id'])
                    ->where(function ($query) use ($startDateTime, $endDateTime) {
                        $query->whereBetween('start_time', [$startDateTime, $endDateTime])
                              ->orWhereBetween('end_time', [$startDateTime, $endDateTime])
                              ->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                                  $q->where('start_time', '<=', $startDateTime)
                                    ->where('end_time', '>=', $endDateTime);
                              });
                    })
                    ->exists();

                if ($conflictingSession) {
                    $this->addError('classroom_id', 'The selected room is already booked for this time slot.');
                    return;
                }
            }

            Log::info('Validation Passed', ['validated_data' => $validated]);

            // Prepare session data
            $sessionData = [
                'subject_id' => $validated['subject_id'],
                'teacher_profile_id' => $this->teacherProfile->id,
                'start_time' => $startDateTime,
                'end_time' => $endDateTime,
                'type' => $validated['type'],
                'link' => $validated['link'] ?: null,
                'classroom_id' => $validated['classroom_id'] ?: null,
                'description' => $validated['description'] ?: null,
            ];

            Log::info('Prepared Session Data', ['session_data' => $sessionData]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Create session
            Log::debug('Creating Session Record');
            $session = Session::create($sessionData);
            Log::info('Session Created Successfully', [
                'session_id' => $session->id,
                'subject_id' => $session->subject_id,
                'start_time' => $session->start_time->toDateTimeString()
            ]);

            // Get subject for logging
            $subject = Subject::find($validated['subject_id']);

            // Log activity
            Log::debug('Logging Activity');
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created session for {$subject->name} ({$subject->code}) on {$startDateTime->format('M d, Y \a\t g:i A')}",
                Session::class,
                $session->id,
                [
                    'session_id' => $session->id,
                    'subject_name' => $subject->name,
                    'subject_code' => $subject->code,
                    'session_type' => $validated['type'],
                    'start_time' => $startDateTime->toDateTimeString(),
                    'end_time' => $endDateTime->toDateTimeString(),
                    'has_link' => !empty($validated['link']),
                    'has_room' => !empty($validated['classroom_id']),
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Show success toast
            $this->success("Session for {$subject->name} has been successfully created.");
            Log::info('Success Toast Displayed');

            // Redirect to session show page
            Log::info('Redirecting to Session Show Page', [
                'session_id' => $session->id,
                'route' => 'teacher.sessions.show'
            ]);

            $this->redirect(route('teacher.sessions.show', $session->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'subject_id' => $this->subject_id,
                    'start_date' => $this->start_date,
                    'start_time' => $this->start_time,
                    'end_time' => $this->end_time,
                    'type' => $this->type,
                ]
            ]);

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Session Creation Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'teacher_profile_id' => $this->teacherProfile->id,
                'form_data' => [
                    'subject_id' => $this->subject_id,
                    'start_date' => $this->start_date,
                    'start_time' => $this->start_time,
                    'end_time' => $this->end_time,
                    'type' => $this->type,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get duration in minutes
    public function getDurationProperty(): ?int
    {
        if ($this->start_time && $this->end_time) {
            try {
                $start = \Carbon\Carbon::parse($this->start_time);
                $end = \Carbon\Carbon::parse($this->end_time);

                if ($end->greaterThan($start)) {
                    return $start->diffInMinutes($end);
                }
            } catch (\Exception $e) {
                // Invalid time format
            }
        }

        return null;
    }

    // Get selected subject details
    public function getSelectedSubjectProperty(): ?array
    {
        if ($this->subject_id) {
            return collect($this->subjectOptions)->firstWhere('id', $this->subject_id);
        }

        return null;
    }

    // Get selected room details
    public function getSelectedRoomProperty(): ?array
    {
        if ($this->classroom_id) {
            return collect($this->roomOptions)->firstWhere('id', $this->classroom_id);
        }

        return null;
    }

    public function with(): array
    {
        return [
            'duration' => $this->duration,
            'selectedSubject' => $this->selectedSubject,
            'selectedRoom' => $this->selectedRoom,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Create New Session" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('teacher.sessions.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Session Information">
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

                        <!-- Session Type -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Session Type *</label>
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

                        <!-- Room -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Room</label>
                            <select
                                wire:model.live="classroom_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            >
                                @foreach($roomOptions as $room)
                                    <option value="{{ $room['id'] }}">{{ $room['name'] }}</option>
                                @endforeach
                            </select>
                            @if($selectedRoom && $selectedRoom['id'])
                                <p class="mt-1 text-xs text-gray-500">
                                    Capacity: {{ $selectedRoom['capacity'] }}
                                    @if($selectedRoom['location'])
                                        • {{ $selectedRoom['location'] }}
                                    @endif
                                    @if($selectedRoom['building'])
                                        • {{ $selectedRoom['building'] }}
                                    @endif
                                </p>
                            @endif
                            @error('classroom_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Start Date -->
                        <div>
                            <x-input
                                label="Date"
                                wire:model.live="start_date"
                                type="date"
                                required
                            />
                            @error('start_date')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Start Time -->
                        <div>
                            <x-input
                                label="Start Time"
                                wire:model.live="start_time"
                                type="time"
                                required
                            />
                            @error('start_time')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- End Time -->
                        <div>
                            <x-input
                                label="End Time"
                                wire:model.live="end_time"
                                type="time"
                                required
                            />
                            @if($duration)
                                <p class="mt-1 text-xs text-green-600">Duration: {{ $duration }} minutes</p>
                            @endif
                            @error('end_time')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Session Link -->
                        <div class="md:col-span-2">
                            <x-input
                                label="Session Link"
                                wire:model.live="link"
                                type="url"
                                placeholder="https://meet.google.com/... or https://zoom.us/..."
                            />
                            <p class="mt-1 text-xs text-gray-500">Optional: Add a link for online sessions (Zoom, Meet, etc.)</p>
                            @error('link')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Description -->
                        <div class="md:col-span-2">
                            <x-textarea
                                label="Description"
                                wire:model.live="description"
                                placeholder="Optional: Add session description, topics to cover, materials needed, etc."
                                rows="4"
                            />
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end pt-6">
                        <x-button
                            label="Cancel"
                            link="{{ route('teacher.sessions.index') }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Create Session"
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
            <!-- Session Preview -->
            <x-card title="Session Preview">
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
                            <strong>Type:</strong>
                            @if($type)
                                @php
                                    $selectedType = collect($typeOptions)->firstWhere('id', $type);
                                @endphp
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ml-2 {{ match($type) {
                                    'lecture' => 'bg-blue-100 text-blue-800',
                                    'practical' => 'bg-green-100 text-green-800',
                                    'tutorial' => 'bg-purple-100 text-purple-800',
                                    'lab' => 'bg-orange-100 text-orange-800',
                                    'seminar' => 'bg-indigo-100 text-indigo-800',
                                    default => 'bg-gray-100 text-gray-600'
                                } }}">
                                    {{ $selectedType['name'] ?? ucfirst($type) }}
                                </span>
                            @else
                                <span class="text-gray-500">No type selected</span>
                            @endif
                        </div>

                        @if($start_date && $start_time && $end_time)
                            <div>
                                <strong>Schedule:</strong>
                                <div class="mt-1">
                                    {{ \Carbon\Carbon::parse($start_date)->format('l, M d, Y') }}
                                </div>
                                <div class="text-gray-600">
                                    {{ \Carbon\Carbon::parse($start_time)->format('g:i A') }} -
                                    {{ \Carbon\Carbon::parse($end_time)->format('g:i A') }}
                                </div>
                                @if($duration)
                                    <div class="text-xs text-green-600">{{ $duration }} minutes</div>
                                @endif
                            </div>
                        @endif

                        @if($selectedRoom && $selectedRoom['id'])
                            <div>
                                <strong>Room:</strong>
                                <div class="mt-1">{{ $selectedRoom['name'] }}</div>
                                <div class="text-xs text-gray-500">
                                    Capacity: {{ $selectedRoom['capacity'] }}
                                    @if($selectedRoom['location'])
                                        • {{ $selectedRoom['location'] }}
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if($link)
                            <div>
                                <strong>Online Link:</strong>
                                <div class="mt-1">
                                    <a href="{{ $link }}" target="_blank" class="text-xs text-blue-600 break-all hover:text-blue-800">
                                        {{ $link }}
                                    </a>
                                </div>
                            </div>
                        @endif

                        @if($description)
                            <div>
                                <strong>Description:</strong>
                                <div class="p-2 mt-1 text-xs rounded bg-gray-50">{{ $description }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Help Card -->
            <x-card title="Session Guidelines">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Scheduling</div>
                        <p class="text-gray-600">Sessions must be scheduled at least 1 hour in advance. Choose appropriate start and end times.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Session Types</div>
                        <ul class="mt-2 space-y-1 text-xs text-gray-600">
                            <li><strong>Lecture:</strong> Traditional classroom teaching</li>
                            <li><strong>Practical:</strong> Hands-on practice session</li>
                            <li><strong>Tutorial:</strong> Small group discussion</li>
                            <li><strong>Lab:</strong> Laboratory work</li>
                            <li><strong>Seminar:</strong> Presentation and discussion</li>
                        </ul>
                    </div>

                    <div>
                        <div class="font-semibold">Room Booking</div>
                        <p class="text-gray-600">Select a room if conducting in-person sessions. The system will check for conflicts automatically.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Online Sessions</div>
                        <p class="text-gray-600">Add meeting links for online or hybrid sessions. Students will see this link when the session starts.</p>
                    </div>
                </div>
            </x-card>

            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-2">
                    <x-button
                        label="View All Sessions"
                        icon="o-presentation-chart-line"
                        link="{{ route('teacher.sessions.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="My Subjects"
                        icon="o-academic-cap"
                        link="{{ route('teacher.subjects.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Create Exam"
                        icon="o-document-plus"
                        link="{{ route('teacher.exams.create') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
