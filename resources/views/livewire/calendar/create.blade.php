<?php

use App\Models\Event;
use App\Models\ActivityLog;
use App\Models\AcademicYear;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Event')] class extends Component {
    use Toast;

    // Event form data
    public string $title = '';
    public string $description = '';
    public string $type = 'general';
    public string $startDate = '';
    public string $startTime = '';
    public string $endDate = '';
    public string $endTime = '';
    public bool $isAllDay = false;
    public string $location = '';
    public string $color = '#3B82F6';
    public ?int $academicYearId = null;
    public bool $recurring = false;
    public array $recurringPattern = [];
    public bool $registrationRequired = false;
    public ?int $maxAttendees = null;
    public string $registrationDeadline = '';
    public string $status = 'active';
    public array $attendees = [];

    // Options
    public array $eventTypeOptions = [
        'general' => 'General',
        'academic' => 'Academic',
        'exam' => 'Exam',
        'holiday' => 'Holiday',
        'meeting' => 'Meeting',
        'event' => 'Event',
        'deadline' => 'Deadline',
    ];

    public array $statusOptions = [
        'active' => 'Active',
        'cancelled' => 'Cancelled',
        'postponed' => 'Postponed',
    ];

    public array $colorOptions = [
        '#3B82F6' => 'Blue',
        '#EF4444' => 'Red',
        '#10B981' => 'Green',
        '#F59E0B' => 'Yellow',
        '#8B5CF6' => 'Purple',
        '#F97316' => 'Orange',
        '#06B6D4' => 'Cyan',
        '#84CC16' => 'Lime',
    ];

    public function mount(): void
    {
        // Set default dates
        $this->startDate = now()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->startTime = '09:00';
        $this->endTime = '10:00';

        // Set default academic year
        $currentAcademicYear = AcademicYear::where('is_current', true)->first();
        if ($currentAcademicYear) {
            $this->academicYearId = $currentAcademicYear->id;
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed event creation page',
            Event::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    public function updatedIsAllDay(): void
    {
        if ($this->isAllDay) {
            $this->startTime = '';
            $this->endTime = '';
        } else {
            $this->startTime = '09:00';
            $this->endTime = '10:00';
        }
    }

    public function updatedStartDate(): void
    {
        // Auto-set end date to start date if not already set
        if (empty($this->endDate) || $this->endDate < $this->startDate) {
            $this->endDate = $this->startDate;
        }
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in(array_keys($this->eventTypeOptions))],
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'startTime' => 'required_unless:isAllDay,true',
            'endTime' => 'required_unless:isAllDay,true',
            'location' => 'nullable|string|max:255',
            'color' => 'nullable|string',
            'academicYearId' => 'nullable|exists:academic_years,id',
            'maxAttendees' => 'nullable|integer|min:1',
            'registrationDeadline' => 'nullable|date|before:startDate',
            'status' => ['required', Rule::in(array_keys($this->statusOptions))],
        ]);

        try {
            // Prepare datetime objects
            if ($this->isAllDay) {
                $startDateTime = Carbon::parse($this->startDate)->startOfDay();
                $endDateTime = Carbon::parse($this->endDate)->endOfDay();
            } else {
                $startDateTime = Carbon::parse($this->startDate . ' ' . $this->startTime);
                $endDateTime = Carbon::parse($this->endDate . ' ' . $this->endTime);
            }

            // Create the event
            $event = Event::create([
                'title' => $this->title,
                'description' => $this->description,
                'type' => $this->type,
                'start_date' => $startDateTime,
                'end_date' => $endDateTime,
                'is_all_day' => $this->isAllDay,
                'location' => $this->location,
                'color' => $this->color,
                'created_by' => Auth::id(),
                'academic_year_id' => $this->academicYearId,
                'recurring' => $this->recurring,
                'recurring_pattern' => $this->recurring ? $this->recurringPattern : null,
                'registration_required' => $this->registrationRequired,
                'max_attendees' => $this->maxAttendees,
                'registration_deadline' => $this->registrationDeadline ? Carbon::parse($this->registrationDeadline) : null,
                'status' => $this->status,
                'attendees' => $this->attendees,
            ]);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created event: {$this->title}",
                Event::class,
                $event->id,
                [
                    'event_title' => $this->title,
                    'event_type' => $this->type,
                    'start_date' => $startDateTime->toDateTimeString(),
                    'end_date' => $endDateTime->toDateTimeString(),
                ]
            );

            $this->success('Event created successfully!');

            // Redirect to calendar or event show page
            return $this->redirect(route('admin.calendar.show', $event));

        } catch (\Exception $e) {
            $this->error('Error creating event: ' . $e->getMessage());
        }
    }

    public function cancel(): void
    {
        return $this->redirect(route('admin.calendar.index'));
    }

    // Get academic years for dropdown
    protected function getAcademicYears()
    {
        try {
            return AcademicYear::orderBy('start_date', 'desc')->get();
        } catch (\Exception $e) {
            return collect();
        }
    }

    // Get users for attendees selection
    protected function getUsers()
    {
        try {
            return User::orderBy('name')->get(['id', 'name', 'email']);
        } catch (\Exception $e) {
            return collect();
        }
    }

    public function with(): array
    {
        return [
            'academicYears' => $this->getAcademicYears(),
            'users' => $this->getUsers(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Create Event" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-x-mark"
                wire:click="cancel"
                class="btn-ghost"
            />
            <x-button
                label="Save Event"
                icon="o-check"
                wire:click="save"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-header>

    <form wire:submit="save">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Main Event Details -->
            <div class="space-y-6 lg:col-span-2">
                <x-card title="Event Details">
                    <div class="space-y-4">
                        <x-input
                            label="Event Title"
                            wire:model="title"
                            placeholder="Enter event title"
                            required
                        />

                        <x-textarea
                            label="Description"
                            wire:model="description"
                            placeholder="Enter event description"
                            rows="4"
                        />

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-select
                                label="Event Type"
                                :options="collect($eventTypeOptions)->map(fn($label, $value) => ['id' => $value, 'name' => $label])->values()->toArray()"
                                option-value="id"
                                option-label="name"
                                wire:model="type"
                                required
                            />

                            <x-select
                                label="Status"
                                :options="collect($statusOptions)->map(fn($label, $value) => ['id' => $value, 'name' => $label])->values()->toArray()"
                                option-value="id"
                                option-label="name"
                                wire:model="status"
                                required
                            />
                        </div>

                        <x-input
                            label="Location"
                            wire:model="location"
                            placeholder="Enter event location"
                        />

                        <div>
                            <label class="block mb-2 text-sm font-medium">Event Color</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach($colorOptions as $colorValue => $colorName)
                                    <label class="cursor-pointer">
                                        <input
                                            type="radio"
                                            wire:model="color"
                                            value="{{ $colorValue }}"
                                            class="sr-only"
                                        />
                                        <div
                                            class="w-8 h-8 rounded-full border-2 flex items-center justify-center {{ $color === $colorValue ? 'border-gray-800' : 'border-gray-300' }}"
                                            style="background-color: {{ $colorValue }}"
                                        >
                                            @if($color === $colorValue)
                                                <x-icon name="o-check" class="w-4 h-4 text-white" />
                                            @endif
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </x-card>

                <!-- Date and Time -->
                <x-card title="Date & Time">
                    <div class="space-y-4">
                        <div class="form-control">
                            <label class="cursor-pointer label">
                                <span class="label-text">All Day Event</span>
                                <x-checkbox wire:model.live="isAllDay" />
                            </label>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-input
                                type="date"
                                label="Start Date"
                                wire:model.live="startDate"
                                required
                            />

                            <x-input
                                type="date"
                                label="End Date"
                                wire:model="endDate"
                                required
                            />
                        </div>

                        @if(!$isAllDay)
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <x-input
                                    type="time"
                                    label="Start Time"
                                    wire:model="startTime"
                                    required
                                />

                                <x-input
                                    type="time"
                                    label="End Time"
                                    wire:model="endTime"
                                    required
                                />
                            </div>
                        @endif
                    </div>
                </x-card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Academic Year -->
                @if($academicYears->isNotEmpty())
                    <x-card title="Academic Information">
                        <x-select
                            label="Academic Year"
                            :options="$academicYears->map(fn($year) => ['id' => $year->id, 'name' => $year->name])->toArray()"
                            option-value="id"
                            option-label="name"
                            wire:model="academicYearId"
                            placeholder="Select academic year"
                        />
                    </x-card>
                @endif

                <!-- Registration Settings -->
                <x-card title="Registration">
                    <div class="space-y-4">
                        <div class="form-control">
                            <label class="cursor-pointer label">
                                <span class="label-text">Registration Required</span>
                                <x-checkbox wire:model.live="registrationRequired" />
                            </label>
                        </div>

                        @if($registrationRequired)
                            <x-input
                                type="number"
                                label="Max Attendees"
                                wire:model="maxAttendees"
                                placeholder="Leave empty for unlimited"
                                min="1"
                            />

                            <x-input
                                type="datetime-local"
                                label="Registration Deadline"
                                wire:model="registrationDeadline"
                            />
                        @endif
                    </div>
                </x-card>

                <!-- Recurring Events -->
                <x-card title="Recurring">
                    <div class="space-y-4">
                        <div class="form-control">
                            <label class="cursor-pointer label">
                                <span class="label-text">Recurring Event</span>
                                <x-checkbox wire:model.live="recurring" />
                            </label>
                        </div>

                        @if($recurring)
                            <div class="p-4 text-sm text-blue-700 rounded-lg bg-blue-50">
                                <strong>Note:</strong> Recurring event patterns will be available in a future update. For now, you'll need to create individual events.
                            </div>
                        @endif
                    </div>
                </x-card>

                <!-- Action Buttons -->
                <x-card>
                    <div class="space-y-3">
                        <x-button
                            label="Save Event"
                            icon="o-check"
                            wire:click="save"
                            class="w-full btn-primary"
                        />

                        <x-button
                            label="Cancel"
                            icon="o-x-mark"
                            wire:click="cancel"
                            class="w-full btn-ghost"
                        />
                    </div>
                </x-card>
            </div>
        </div>
    </form>
</div>
