<?php

use App\Models\Session;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Rule;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Session')] class extends Component {
    use Toast;

    // Form fields
    #[Rule('required')]
    public $subject_id;

    #[Rule('required|date|after_or_equal:today')]
    public $date;

    #[Rule('required')]
    public $start_time;

    #[Rule('required')]
    public $end_time;

    #[Rule('required|string|max:255')]
    public $topic;

    #[Rule('nullable|string|max:1000')]
    public $description;

    #[Rule('nullable')]
    public $classroom_id;

    #[Rule('nullable|string|max:255')]
    public $online_meeting_url;

    #[Rule('nullable|string|max:255')]
    public $online_meeting_password;

    #[Rule('boolean')]
    public $is_online = false;

    // Prefilled subject ID (for when creating from subject page)
    public $prefilledSubjectId = null;

    // Repeating options
    public $is_repeating = false;
    public $repeat_frequency = 'weekly';
    public $repeat_until = null;
    public $repeat_days = [];

    // Available options
    public $subjects = [];
    public $classrooms = [];

    public function mount($subject_id = null): void
    {
        // Set default date to today
        $this->date = Carbon::now()->format('Y-m-d');

        // Set default times
        $this->start_time = '09:00';
        $this->end_time = '10:00';

        // Set prefilled subject if provided
        if ($subject_id) {
            $this->prefilledSubjectId = $subject_id;
            $this->subject_id = $subject_id;
        }

        // Load available options
        $this->loadOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed create session page',
            Session::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Load available options for dropdowns
    private function loadOptions(): void
    {
        $teacherProfile = Auth::user()->teacherProfile;

        // If no teacher profile, create empty arrays
        if (!$teacherProfile) {
            $this->subjects = [];
            $this->classrooms = [];
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

            // Add classrooms - use Room model if exists, otherwise create a simple array
            try {
                if (class_exists('\\App\\Models\\Room')) {
                    $this->classrooms = \App\Models\Room::orderBy('name')
                        ->get()
                        ->map(function ($room) {
                            return [
                                'id' => $room->id,
                                'name' => $room->name . (isset($room->capacity) ? ' (' . $room->capacity . ' seats)' : '')
                            ];
                        })
                        ->toArray();
                } else {
                    // Fallback to empty array or mock data
                    $this->classrooms = [
                        ['id' => 1, 'name' => 'Room 101'],
                        ['id' => 2, 'name' => 'Room 102'],
                        ['id' => 3, 'name' => 'Room 103'],
                        ['id' => 4, 'name' => 'Computer Lab'],
                        ['id' => 5, 'name' => 'Science Lab'],
                    ];
                }
            } catch (\Exception $e) {
                // Fallback to empty array if Room model causes issues
                $this->classrooms = [
                    ['id' => 1, 'name' => 'Room 101'],
                    ['id' => 2, 'name' => 'Room 102'],
                    ['id' => 3, 'name' => 'Room 103']
                ];
            }
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading session options: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            // Set empty arrays
            $this->subjects = [];
            $this->classrooms = [];
        }
    }

    // Create a single session
    private function createSingleSession(): bool
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            return false;
        }

        try {
            // Create session
            $session = Session::create([
                'teacher_profile_id' => $teacherProfile->id,
                'subject_id' => $this->subject_id,
                'date' => $this->date,
                'start_time' => $this->start_time,
                'end_time' => $this->end_time,
                'topic' => $this->topic,
                'description' => $this->description,
                'classroom_id' => $this->is_online ? null : $this->classroom_id,
                'is_online' => $this->is_online,
                'online_meeting_url' => $this->is_online ? $this->online_meeting_url : null,
                'online_meeting_password' => $this->is_online ? $this->online_meeting_password : null,
                'status' => 'scheduled'
            ]);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                'Teacher created a new session: ' . $this->topic,
                Session::class,
                $session->id,
                ['ip' => request()->ip()]
            );

            return true;
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error creating session: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip(), 'data' => [
                    'subject_id' => $this->subject_id,
                    'date' => $this->date,
                    'topic' => $this->topic
                ]]
            );

            $this->error('Failed to create session: ' . $e->getMessage());
            return false;
        }
    }

    // Create repeating sessions
    private function createRepeatingSessions(): bool
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            return false;
        }

        // Parse dates
        $startDate = Carbon::parse($this->date);
        $endDate = Carbon::parse($this->repeat_until);

        // Check if end date is after start date
        if ($endDate->isBefore($startDate)) {
            $this->error('End date must be after the start date.');
            return false;
        }

        // Calculate dates based on frequency
        $sessionDates = [];
        $currentDate = $startDate->copy();

        try {
            // Weekly repeating
            if ($this->repeat_frequency === 'weekly') {
                // If no days selected, use the day of the start date
                if (empty($this->repeat_days)) {
                    $this->repeat_days = [$startDate->dayOfWeek];
                }

                // Generate dates
                while ($currentDate->lte($endDate)) {
                    // Check if current day is in selected days
                    if (in_array($currentDate->dayOfWeek, $this->repeat_days)) {
                        $sessionDates[] = $currentDate->format('Y-m-d');
                    }

                    // Move to next day
                    $currentDate->addDay();
                }
            }
            // Daily repeating
            elseif ($this->repeat_frequency === 'daily') {
                while ($currentDate->lte($endDate)) {
                    $sessionDates[] = $currentDate->format('Y-m-d');
                    $currentDate->addDay();
                }
            }
            // Monthly repeating (same day each month)
            elseif ($this->repeat_frequency === 'monthly') {
                while ($currentDate->lte($endDate)) {
                    $sessionDates[] = $currentDate->format('Y-m-d');
                    $currentDate->addMonth();
                }
            }

            // Create sessions for each date
            $createdCount = 0;
            foreach ($sessionDates as $sessionDate) {
                $session = Session::create([
                    'teacher_profile_id' => $teacherProfile->id,
                    'subject_id' => $this->subject_id,
                    'date' => $sessionDate,
                    'start_time' => $this->start_time,
                    'end_time' => $this->end_time,
                    'topic' => $this->topic,
                    'description' => $this->description,
                    'classroom_id' => $this->is_online ? null : $this->classroom_id,
                    'is_online' => $this->is_online,
                    'online_meeting_url' => $this->is_online ? $this->online_meeting_url : null,
                    'online_meeting_password' => $this->is_online ? $this->online_meeting_password : null,
                    'status' => 'scheduled'
                ]);

                $createdCount++;
            }

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                'Teacher created ' . $createdCount . ' repeating sessions for: ' . $this->topic,
                Session::class,
                null,
                ['ip' => request()->ip(), 'frequency' => $this->repeat_frequency]
            );

            return true;
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error creating repeating sessions: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            $this->error('Failed to create repeating sessions: ' . $e->getMessage());
            return false;
        }
    }

    // Save session(s)
    public function save()
    {
        // Validate form
        $this->validate();

        // Additional validation for end time after start time
        $startTime = Carbon::createFromFormat('H:i', $this->start_time);
        $endTime = Carbon::createFromFormat('H:i', $this->end_time);

        if ($startTime->gte($endTime)) {
            $this->addError('end_time', 'End time must be after start time.');
            return;
        }

        // Validate online meeting URL if online
        if ($this->is_online && empty($this->online_meeting_url)) {
            $this->addError('online_meeting_url', 'Online meeting URL is required for online sessions.');
            return;
        }

        // Validate classroom if not online
        if (!$this->is_online && empty($this->classroom_id)) {
            $this->addError('classroom_id', 'Classroom is required for in-person sessions.');
            return;
        }

        // Create sessions
        $success = false;

        if ($this->is_repeating) {
            // Additional validation for repeating sessions
            if (empty($this->repeat_until)) {
                $this->addError('repeat_until', 'End date is required for repeating sessions.');
                return;
            }

            $success = $this->createRepeatingSessions();
        } else {
            $success = $this->createSingleSession();
        }

        // Redirect on success
        if ($success) {
            $this->success('Session(s) created successfully.');
            return redirect()->route('teacher.sessions.index');
        }
    }

    // Cancel and go back
    public function cancel()
    {
        return redirect()->route('teacher.sessions.index');
    }

    // Toggle online/offline mode
    public function updatedIsOnline()
    {
        if ($this->is_online) {
            $this->classroom_id = null;
        } else {
            $this->online_meeting_url = null;
            $this->online_meeting_password = null;
        }
    }

    // Toggle repeating mode
    public function updatedIsRepeating()
    {
        if ($this->is_repeating) {
            $this->repeat_until = Carbon::parse($this->date)->addWeeks(4)->format('Y-m-d');
        } else {
            $this->repeat_until = null;
            $this->repeat_days = [];
        }
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Schedule New Session" separator progress-indicator>
        <x-slot:subtitle>
            Create a new teaching session for your students
        </x-slot:subtitle>
    </x-header>

    <x-card>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <!-- Basic Session Information -->
            <div>
                <h3 class="mb-4 text-lg font-medium">Session Information</h3>

                <div class="space-y-4">
                    <x-select
                        label="Subject"
                        wire:model="subject_id"
                        :options="$subjects"
                        option-label="name"
                        option-value="id"
                        placeholder="Select a subject"
                        :disabled="!empty($prefilledSubjectId)"
                        hint="Select the subject for this session"
                        required
                    />

                    <x-input
                        label="Topic"
                        wire:model="topic"
                        placeholder="e.g. Introduction to Algebra"
                        hint="The main topic of this session"
                        required
                    />

                    <x-textarea
                        label="Description"
                        wire:model="description"
                        placeholder="Describe what will be covered in this session..."
                        hint="Provide details about the session content and goals"
                        rows="4"
                    />
                </div>
            </div>

            <!-- Date and Time -->
            <div>
                <h3 class="mb-4 text-lg font-medium">Date and Time</h3>

                <div class="space-y-4">
                    <x-input
                        type="date"
                        label="Date"
                        wire:model="date"
                        min="{{ now()->format('Y-m-d') }}"
                        hint="Select the date for this session"
                        required
                    />

                    <div class="grid grid-cols-2 gap-4">
                        <x-input
                            type="time"
                            label="Start Time"
                            wire:model="start_time"
                            hint="Session start time"
                            required
                        />

                        <x-input
                            type="time"
                            label="End Time"
                            wire:model="end_time"
                            hint="Session end time"
                            required
                        />
                    </div>

                    <div>
                        <x-toggle
                            label="Repeating Session"
                            wire:model.live="is_repeating"
                            hint="Create multiple sessions with the same schedule"
                        />
                    </div>

                    @if ($is_repeating)
                        <div class="p-4 border rounded-lg border-base-300 bg-base-200">
                            <h4 class="mb-2 font-medium">Repeating Options</h4>

                            <div class="space-y-4">
                                <x-select
                                    label="Frequency"
                                    wire:model.live="repeat_frequency"
                                    :options="[
                                        ['label' => 'Daily', 'value' => 'daily'],
                                        ['label' => 'Weekly', 'value' => 'weekly'],
                                        ['label' => 'Monthly', 'value' => 'monthly']
                                    ]"
                                    option-label="label"
                                    option-value="value"
                                    hint="How often should this session repeat"
                                />

                                <x-input
                                    type="date"
                                    label="Repeat Until"
                                    wire:model="repeat_until"
                                    min="{{ now()->addDays(1)->format('Y-m-d') }}"
                                    hint="Last date to create sessions for"
                                    required
                                />

                                @if ($repeat_frequency === 'weekly')
                                    <div>
                                        <label class="block mb-2 text-sm font-medium">Days of Week</label>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach([
                                                ['value' => 1, 'label' => 'Mon'],
                                                ['value' => 2, 'label' => 'Tue'],
                                                ['value' => 3, 'label' => 'Wed'],
                                                ['value' => 4, 'label' => 'Thu'],
                                                ['value' => 5, 'label' => 'Fri'],
                                                ['value' => 6, 'label' => 'Sat'],
                                                ['value' => 0, 'label' => 'Sun']
                                            ] as $day)
                                                <label class="flex items-center gap-2 p-2 border rounded cursor-pointer border-base-300 hover:bg-base-300">
                                                    <input
                                                        type="checkbox"
                                                        value="{{ $day['value'] }}"
                                                        wire:model.live="repeat_days"
                                                        class="checkbox checkbox-sm"
                                                    />
                                                    <span>{{ $day['label'] }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            Select which days of the week to create sessions for
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Location Information -->
            <div class="md:col-span-2">
                <h3 class="mb-4 text-lg font-medium">Location</h3>

                <div class="p-4 border rounded-lg border-base-300">
                    <x-toggle
                        label="Online Session"
                        wire:model.live="is_online"
                        hint="Toggle for online or in-person session"
                    />

                    <div class="grid grid-cols-1 gap-4 mt-4 md:grid-cols-2">
                        @if ($is_online)
                            <div class="space-y-4">
                                <x-input
                                    label="Meeting URL"
                                    wire:model="online_meeting_url"
                                    placeholder="https://zoom.us/j/123456789"
                                    hint="Link for students to join the online session"
                                    required
                                />

                                <x-input
                                    label="Meeting Password (optional)"
                                    wire:model="online_meeting_password"
                                    placeholder="Enter password if required"
                                    hint="Password for the online meeting room"
                                />
                            </div>
                        @else
                            <x-select
                                label="Classroom"
                                wire:model="classroom_id"
                                :options="$classrooms"
                                option-label="name"
                                option-value="id"
                                placeholder="Select a classroom"
                                hint="Physical location for this session"
                                required
                            />
                        @endif
                    </div>
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
                    label="Schedule Session"
                    icon="o-check"
                    wire:click="save"
                    class="btn-primary"
                />
            </div>
        </x-slot:footer>
    </x-card>
</div>
