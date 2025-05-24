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

new #[Title('Edit Session')] class extends Component {
    use Toast;

    // Session model
    public Session $session;

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

    #[Rule('nullable|string')]
    public $teacher_notes;

    // Available options
    public $subjects = [];
    public $classrooms = [];

    // Session status check
    public $canEdit = false;
    public $isUpcoming = false;

    public function mount(Session $session): void
    {
        $this->session = $session;

        // Check if current teacher is the owner of this session
        $teacherProfile = Auth::user()->teacherProfile;
        $isOwner = $teacherProfile && $teacherProfile->id === $this->session->teacher_profile_id;

        // Check if session is upcoming
        $this->isUpcoming = Carbon::parse($this->session->date.' '.$this->session->start_time)->isFuture();

        // Check if session can be edited
        $this->canEdit = $isOwner && $this->isUpcoming && $this->session->status !== 'cancelled';

        // Redirect if cannot edit
        if (!$this->canEdit) {
            $this->error('You cannot edit this session. Only upcoming sessions can be edited by the teacher who created them.');
            return redirect()->route('teacher.sessions.show', $this->session->id);
        }

        // Load session data
        $this->subject_id = $this->session->subject_id;
        $this->date = $this->session->date->format('Y-m-d');
        $this->start_time = $this->session->start_time;
        $this->end_time = $this->session->end_time;
        $this->topic = $this->session->topic;
        $this->description = $this->session->description;
        $this->classroom_id = $this->session->classroom_id;
        $this->is_online = $this->session->is_online;
        $this->online_meeting_url = $this->session->online_meeting_url;
        $this->online_meeting_password = $this->session->online_meeting_password;
        $this->teacher_notes = $this->session->teacher_notes;

        // Load available options
        $this->loadOptions();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed edit session page for: ' . $this->session->topic,
            Session::class,
            $this->session->id,
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

    // Save updated session
    public function save()
    {
        // Validate form
        $this->validate([
            'subject_id' => 'required',
            'date' => ['required', 'date', function ($attribute, $value, $fail) {
                if (!$this->isUpcoming && $value != $this->session->date->format('Y-m-d')) {
                    $fail('You cannot change the date of a session that has already started or completed.');
                }
            }],
            'start_time' => 'required',
            'end_time' => 'required',
            'topic' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'classroom_id' => 'nullable',
            'online_meeting_url' => 'nullable|string|max:255',
            'online_meeting_password' => 'nullable|string|max:255',
            'is_online' => 'boolean',
            'teacher_notes' => 'nullable|string',
        ]);

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

        try {
            // Update session
            $this->session->update([
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
                'teacher_notes' => $this->teacher_notes,
            ]);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                'Teacher updated session: ' . $this->session->topic,
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            // Show success message
            $this->success('Session updated successfully.');

            // Redirect to session details
            return redirect()->route('teacher.sessions.show', $this->session->id);
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error updating session: ' . $e->getMessage(),
                Session::class,
                $this->session->id,
                ['ip' => request()->ip()]
            );

            // Show error message
            $this->error('Failed to update session: ' . $e->getMessage());
        }
    }

    // Cancel and go back
    public function cancel()
    {
        return redirect()->route('teacher.sessions.show', $this->session->id);
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
};
?>

<div>
    <!-- Page header -->
    <x-header title="Edit Session" separator progress-indicator>
        <x-slot:subtitle>
            Update session information and details
        </x-slot:subtitle>
    </x-header>

    @if (!$canEdit)
        <div class="p-4 text-white shadow-lg alert bg-error">
            <div>
                <x-icon name="o-exclamation-triangle" class="w-6 h-6" />
                <span>You cannot edit this session. Only upcoming sessions can be edited by the teacher who created them.</span>
            </div>
        </div>
    @else
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
                            min="{{ $isUpcoming ? now()->format('Y-m-d') : $date }}"
                            :disabled="!$isUpcoming"
                            hint="{{ $isUpcoming ? 'Select the date for this session' : 'Date cannot be changed for sessions that have already started' }}"
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

                <!-- Teacher Notes -->
                <div class="md:col-span-2" id="notes">
                    <h3 class="mb-4 text-lg font-medium">Teacher Notes</h3>

                    <div class="space-y-4">
                        <x-textarea
                            label="Teacher Notes"
                            wire:model="teacher_notes"
                            placeholder="Add private notes for yourself about this session..."
                            hint="These notes are only visible to teachers, not students"
                            rows="6"
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
    @endif
</div>
