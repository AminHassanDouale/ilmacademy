<?php

use App\Models\Event;
use App\Models\ActivityLog;
use App\Models\AcademicYear;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Calendar')] class extends Component {
    use Toast;

    // Current view date
    #[Url]
    public string $currentDate;

    // View type (month, week, day)
    #[Url]
    public string $viewType = 'month';

    // Filter options
    #[Url]
    public array $eventTypes = [];

    #[Url]
    public string $academicYearFilter = '';

    #[Url]
    public bool $showFilters = false;

    // Event creation/editing
    public bool $showEventModal = false;
    public bool $editMode = false;
    public ?int $editingEventId = null;

    // Event form data
    public string $eventTitle = '';
    public string $eventDescription = '';
    public string $eventType = 'general';
    public string $eventDate = '';
    public string $eventStartTime = '';
    public string $eventEndTime = '';
    public bool $eventAllDay = false;
    public string $eventLocation = '';
    public array $eventAttendees = [];

    // Available options
    public array $eventTypeOptions = [
        'general' => 'General',
        'academic' => 'Academic',
        'exam' => 'Exam',
        'holiday' => 'Holiday',
        'meeting' => 'Meeting',
        'event' => 'Event',
        'deadline' => 'Deadline',
    ];

    public function mount(): void
    {
        // Set default current date if not provided
        if (empty($this->currentDate)) {
            $this->currentDate = now()->format('Y-m-d');
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed calendar page',
            Event::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Navigation methods
    public function previousPeriod(): void
    {
        $date = Carbon::parse($this->currentDate);

        switch ($this->viewType) {
            case 'month':
                $this->currentDate = $date->subMonth()->format('Y-m-d');
                break;
            case 'week':
                $this->currentDate = $date->subWeek()->format('Y-m-d');
                break;
            case 'day':
                $this->currentDate = $date->subDay()->format('Y-m-d');
                break;
        }
    }

    public function nextPeriod(): void
    {
        $date = Carbon::parse($this->currentDate);

        switch ($this->viewType) {
            case 'month':
                $this->currentDate = $date->addMonth()->format('Y-m-d');
                break;
            case 'week':
                $this->currentDate = $date->addWeek()->format('Y-m-d');
                break;
            case 'day':
                $this->currentDate = $date->addDay()->format('Y-m-d');
                break;
        }
    }

    public function goToToday(): void
    {
        $this->currentDate = now()->format('Y-m-d');
    }

    // Event methods
    public function createEvent(): void
    {
        $this->resetEventForm();
        $this->editMode = false;
        $this->showEventModal = true;
    }

    public function editEvent(int $eventId): void
    {
        $event = Event::find($eventId);

        if (!$event) {
            $this->error('Event not found.');
            return;
        }

        $this->editingEventId = $eventId;
        $this->eventTitle = $event->title;
        $this->eventDescription = $event->description ?? '';
        $this->eventType = $event->type ?? 'general';
        $this->eventDate = $event->start_date->format('Y-m-d');
        $this->eventStartTime = $event->start_date->format('H:i');
        $this->eventEndTime = $event->end_date ? $event->end_date->format('H:i') : '';
        $this->eventAllDay = $event->is_all_day ?? false;
        $this->eventLocation = $event->location ?? '';

        $this->editMode = true;
        $this->showEventModal = true;
    }

    public function saveEvent(): void
    {
        $this->validate([
            'eventTitle' => 'required|string|max:255',
            'eventDescription' => 'nullable|string',
            'eventType' => 'required|string',
            'eventDate' => 'required|date',
            'eventStartTime' => 'required_unless:eventAllDay,true',
            'eventEndTime' => 'nullable',
            'eventLocation' => 'nullable|string|max:255',
        ]);

        try {
            $startDateTime = $this->eventAllDay
                ? Carbon::parse($this->eventDate)->startOfDay()
                : Carbon::parse($this->eventDate . ' ' . $this->eventStartTime);

            $endDateTime = $this->eventAllDay
                ? Carbon::parse($this->eventDate)->endOfDay()
                : ($this->eventEndTime
                    ? Carbon::parse($this->eventDate . ' ' . $this->eventEndTime)
                    : $startDateTime->copy()->addHour());

            $eventData = [
                'title' => $this->eventTitle,
                'description' => $this->eventDescription,
                'type' => $this->eventType,
                'start_date' => $startDateTime,
                'end_date' => $endDateTime,
                'is_all_day' => $this->eventAllDay,
                'location' => $this->eventLocation,
                'created_by' => Auth::id(),
            ];

            if ($this->editMode && $this->editingEventId) {
                $event = Event::find($this->editingEventId);
                $event->update($eventData);

                ActivityLog::log(
                    Auth::id(),
                    'update',
                    "Updated event: {$this->eventTitle}",
                    Event::class,
                    $event->id,
                    ['event_title' => $this->eventTitle, 'event_type' => $this->eventType]
                );

                $this->success('Event updated successfully.');
            } else {
                $event = Event::create($eventData);

                ActivityLog::log(
                    Auth::id(),
                    'create',
                    "Created event: {$this->eventTitle}",
                    Event::class,
                    $event->id,
                    ['event_title' => $this->eventTitle, 'event_type' => $this->eventType]
                );

                $this->success('Event created successfully.');
            }

            $this->showEventModal = false;
            $this->resetEventForm();

        } catch (\Exception $e) {
            $this->error('Error saving event: ' . $e->getMessage());
        }
    }

    public function deleteEvent(int $eventId): void
    {
        try {
            $event = Event::find($eventId);

            if (!$event) {
                $this->error('Event not found.');
                return;
            }

            $eventTitle = $event->title;
            $event->delete();

            ActivityLog::log(
                Auth::id(),
                'delete',
                "Deleted event: {$eventTitle}",
                Event::class,
                $eventId,
                ['event_title' => $eventTitle]
            );

            $this->success('Event deleted successfully.');

        } catch (\Exception $e) {
            $this->error('Error deleting event: ' . $e->getMessage());
        }
    }

    protected function resetEventForm(): void
    {
        $this->eventTitle = '';
        $this->eventDescription = '';
        $this->eventType = 'general';
        $this->eventDate = $this->currentDate;
        $this->eventStartTime = '';
        $this->eventEndTime = '';
        $this->eventAllDay = false;
        $this->eventLocation = '';
        $this->eventAttendees = [];
        $this->editingEventId = null;
    }

    // Get events for current view
    protected function getEvents()
    {
        $date = Carbon::parse($this->currentDate);

        switch ($this->viewType) {
            case 'month':
                $startDate = $date->copy()->startOfMonth()->startOfWeek();
                $endDate = $date->copy()->endOfMonth()->endOfWeek();
                break;
            case 'week':
                $startDate = $date->copy()->startOfWeek();
                $endDate = $date->copy()->endOfWeek();
                break;
            case 'day':
                $startDate = $date->copy()->startOfDay();
                $endDate = $date->copy()->endOfDay();
                break;
            default:
                $startDate = $date->copy()->startOfMonth();
                $endDate = $date->copy()->endOfMonth();
        }

        return Event::where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                      ->orWhereBetween('end_date', [$startDate, $endDate])
                      ->orWhere(function ($q) use ($startDate, $endDate) {
                          $q->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                      });
            })
            ->when($this->eventTypes, function ($query) {
                $query->whereIn('type', $this->eventTypes);
            })
            ->with(['creator'])
            ->orderBy('start_date')
            ->get();
    }

    // Get calendar grid for month view
    protected function getCalendarGrid()
    {
        $date = Carbon::parse($this->currentDate);
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        // Start from the beginning of the week that contains the first day of the month
        $startDate = $startOfMonth->copy()->startOfWeek();
        $endDate = $endOfMonth->copy()->endOfWeek();

        $calendar = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = [
                    'date' => $currentDate->copy(),
                    'isCurrentMonth' => $currentDate->month === $date->month,
                    'isToday' => $currentDate->isToday(),
                    'events' => $this->getEvents()->filter(function ($event) use ($currentDate) {
                        return $currentDate->between(
                            $event->start_date->startOfDay(),
                            $event->end_date->endOfDay()
                        );
                    })
                ];
                $currentDate->addDay();
            }
            $calendar[] = $week;
        }

        return $calendar;
    }

    // Get academic years for filter
    protected function getAcademicYears()
    {
        try {
            return AcademicYear::orderBy('start_date', 'desc')->get();
        } catch (\Exception $e) {
            return collect();
        }
    }

    // Get period title for display
    public function getPeriodTitle(): string
    {
        $date = Carbon::parse($this->currentDate);

        return match ($this->viewType) {
            'month' => $date->format('F Y'),
            'week' => $date->startOfWeek()->format('M d') . ' - ' . $date->endOfWeek()->format('M d, Y'),
            'day' => $date->format('l, F d, Y'),
            default => $date->format('F Y')
        };
    }

    // Get event type color
    public function getEventTypeColor(string $type): string
    {
        return match ($type) {
            'academic' => 'bg-blue-100 text-blue-800 border-blue-200',
            'exam' => 'bg-red-100 text-red-800 border-red-200',
            'holiday' => 'bg-green-100 text-green-800 border-green-200',
            'meeting' => 'bg-purple-100 text-purple-800 border-purple-200',
            'event' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
            'deadline' => 'bg-orange-100 text-orange-800 border-orange-200',
            default => 'bg-gray-100 text-gray-800 border-gray-200'
        };
    }

    public function with(): array
    {
        return [
            'events' => $this->getEvents(),
            'calendarGrid' => $this->viewType === 'month' ? $this->getCalendarGrid() : null,
            'academicYears' => $this->getAcademicYears(),
            'periodTitle' => $this->getPeriodTitle(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Calendar" separator>
        <x-slot:middle class="!justify-center">
            <!-- View type toggle -->
            <div class="join">
                <button
                    wire:click="$set('viewType', 'month')"
                    class="join-item btn btn-sm {{ $viewType === 'month' ? 'btn-active' : '' }}"
                >
                    Month
                </button>
                <button
                    wire:click="$set('viewType', 'week')"
                    class="join-item btn btn-sm {{ $viewType === 'week' ? 'btn-active' : '' }}"
                >
                    Week
                </button>
                <button
                    wire:click="$set('viewType', 'day')"
                    class="join-item btn btn-sm {{ $viewType === 'day' ? 'btn-active' : '' }}"
                >
                    Day
                </button>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                @click="$wire.showFilters = true"
                class="btn-ghost"
            />
            <x-button
                label="Add Event"
                icon="o-plus"
                wire:click="createEvent"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-header>

    <!-- Calendar Navigation -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center space-x-4">
            <button wire:click="previousPeriod" class="btn btn-ghost btn-sm">
                <x-icon name="o-chevron-left" class="w-4 h-4" />
            </button>

            <h2 class="text-2xl font-bold">{{ $periodTitle }}</h2>

            <button wire:click="nextPeriod" class="btn btn-ghost btn-sm">
                <x-icon name="o-chevron-right" class="w-4 h-4" />
            </button>
        </div>

        <x-button
            label="Today"
            wire:click="goToToday"
            class="btn-outline btn-sm"
        />
    </div>

    <!-- Calendar Views -->
    @if($viewType === 'month')
        <!-- Month View -->
        <x-card>
            <div class="grid grid-cols-7 gap-0 border">
                <!-- Days of week header -->
                @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day)
                    <div class="p-3 text-sm font-medium text-center bg-gray-50 border-b">{{ $day }}</div>
                @endforeach

                <!-- Calendar grid -->
                @if($calendarGrid)
                    @foreach($calendarGrid as $week)
                        @foreach($week as $day)
                            <div class="min-h-24 p-2 border-b border-r {{ $day['isCurrentMonth'] ? 'bg-white' : 'bg-gray-50' }} {{ $day['isToday'] ? 'bg-blue-50' : '' }}">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm {{ $day['isCurrentMonth'] ? 'text-gray-900' : 'text-gray-400' }} {{ $day['isToday'] ? 'font-bold text-blue-600' : '' }}">
                                        {{ $day['date']->format('d') }}
                                    </span>
                                </div>

                                <!-- Events for this day -->
                                @foreach($day['events']->take(3) as $event)
                                    <div
                                        class="p-1 mb-1 text-xs rounded cursor-pointer {{ $this->getEventTypeColor($event->type) }}"
                                        wire:click="editEvent({{ $event->id }})"
                                        title="{{ $event->title }}"
                                    >
                                        {{ Str::limit($event->title, 20) }}
                                    </div>
                                @endforeach

                                @if($day['events']->count() > 3)
                                    <div class="text-xs text-gray-500">
                                        +{{ $day['events']->count() - 3 }} more
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                @endif
            </div>
        </x-card>
    @elseif($viewType === 'week')
        <!-- Week View -->
        <x-card>
            <div class="space-y-4">
                @foreach($events->groupBy(function($event) { return $event->start_date->format('Y-m-d'); }) as $date => $dayEvents)
                    <div>
                        <h3 class="mb-2 text-lg font-semibold">{{ Carbon::parse($date)->format('l, F d') }}</h3>
                        <div class="space-y-2">
                            @foreach($dayEvents as $event)
                                <div class="flex items-center p-3 border rounded-lg {{ $this->getEventTypeColor($event->type) }}">
                                    <div class="flex-1">
                                        <h4 class="font-medium">{{ $event->title }}</h4>
                                        <p class="text-sm opacity-75">
                                            {{ $event->is_all_day ? 'All day' : $event->start_date->format('g:i A') . ' - ' . $event->end_date->format('g:i A') }}
                                            @if($event->location)
                                                • {{ $event->location }}
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex space-x-2">
                                        <button wire:click="editEvent({{ $event->id }})" class="btn btn-ghost btn-xs">
                                            <x-icon name="o-pencil" class="w-3 h-3" />
                                        </button>
                                        <button
                                            wire:click="deleteEvent({{ $event->id }})"
                                            wire:confirm="Are you sure you want to delete this event?"
                                            class="btn btn-ghost btn-xs text-error"
                                        >
                                            <x-icon name="o-trash" class="w-3 h-3" />
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if($events->isEmpty())
                    <div class="py-12 text-center">
                        <x-icon name="o-calendar" class="w-16 h-16 mx-auto text-gray-300" />
                        <h3 class="mt-2 text-lg font-semibold text-gray-600">No events this week</h3>
                        <p class="text-gray-500">Get started by creating your first event.</p>
                        <x-button
                            label="Add Event"
                            icon="o-plus"
                            wire:click="createEvent"
                            class="mt-4 btn-primary"
                        />
                    </div>
                @endif
            </div>
        </x-card>
    @else
        <!-- Day View -->
        <x-card>
            <div class="space-y-4">
                @if($events->isNotEmpty())
                    @foreach($events as $event)
                        <div class="flex items-center p-4 border rounded-lg {{ $this->getEventTypeColor($event->type) }}">
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold">{{ $event->title }}</h3>
                                <p class="text-sm opacity-75">
                                    {{ $event->is_all_day ? 'All day event' : $event->start_date->format('g:i A') . ' - ' . $event->end_date->format('g:i A') }}
                                    @if($event->location)
                                        • {{ $event->location }}
                                    @endif
                                </p>
                                @if($event->description)
                                    <p class="mt-2 text-sm">{{ $event->description }}</p>
                                @endif
                            </div>
                            <div class="flex space-x-2">
                                <button wire:click="editEvent({{ $event->id }})" class="btn btn-ghost btn-sm">
                                    <x-icon name="o-pencil" class="w-4 h-4" />
                                </button>
                                <button
                                    wire:click="deleteEvent({{ $event->id }})"
                                    wire:confirm="Are you sure you want to delete this event?"
                                    class="btn btn-ghost btn-sm text-error"
                                >
                                    <x-icon name="o-trash" class="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                    @endforeach
                @else
                    <div class="py-12 text-center">
                        <x-icon name="o-calendar" class="w-16 h-16 mx-auto text-gray-300" />
                        <h3 class="mt-2 text-lg font-semibold text-gray-600">No events today</h3>
                        <p class="text-gray-500">Get started by creating your first event.</p>
                        <x-button
                            label="Add Event"
                            icon="o-plus"
                            wire:click="createEvent"
                            class="mt-4 btn-primary"
                        />
                    </div>
                @endif
            </div>
        </x-card>
    @endif

    <!-- Event Modal -->
    <x-modal wire:model="showEventModal" title="{{ $editMode ? 'Edit Event' : 'Create Event' }}" class="backdrop-blur">
        <div class="space-y-4">
            <x-input
                label="Event Title"
                wire:model="eventTitle"
                placeholder="Enter event title"
                required
            />

            <x-textarea
                label="Description"
                wire:model="eventDescription"
                placeholder="Enter event description (optional)"
                rows="3"
            />

            <x-select
                label="Event Type"
                :options="collect($eventTypeOptions)->map(fn($label, $value) => ['id' => $value, 'name' => $label])->values()->toArray()"
                option-value="id"
                option-label="name"
                wire:model="eventType"
            />

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-input
                    type="date"
                    label="Date"
                    wire:model="eventDate"
                    required
                />

                <div class="form-control">
                    <label class="cursor-pointer label">
                        <span class="label-text">All Day Event</span>
                        <x-checkbox wire:model.live="eventAllDay" />
                    </label>
                </div>
            </div>

            @if(!$eventAllDay)
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-input
                        type="time"
                        label="Start Time"
                        wire:model="eventStartTime"
                        required
                    />

                    <x-input
                        type="time"
                        label="End Time"
                        wire:model="eventEndTime"
                    />
                </div>
            @endif

            <x-input
                label="Location"
                wire:model="eventLocation"
                placeholder="Enter event location (optional)"
            />
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showEventModal', false)" />
            <x-button label="{{ $editMode ? 'Update' : 'Create' }}" wire:click="saveEvent" class="btn-primary" />
        </x-slot:actions>
    </x-modal>

    <!-- Filters Drawer -->
    <x-drawer wire:model="showFilters" title="Calendar Filters" position="right">
        <div class="space-y-4">
            <div>
                <label class="block mb-2 text-sm font-medium">Event Types</label>
                @foreach($eventTypeOptions as $value => $label)
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <x-checkbox wire:model.live="eventTypes" value="{{ $value }}" />
                        <span class="text-sm">{{ $label }}</span>
                    </label>
                @endforeach
            </div>

            @if($academicYears->isNotEmpty())
                <x-select
                    label="Academic Year"
                    :options="$academicYears->map(fn($year) => ['id' => $year->id, 'name' => $year->name])->toArray()"
                    option-value="id"
                    option-label="name"
                    wire:model.live="academicYearFilter"
                    placeholder="All academic years"
                />
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Clear Filters" wire:click="$set('eventTypes', [])" />
            <x-button label="Close" wire:click="$set('showFilters', false)" class="btn-primary" />
        </x-slot:actions>
    </x-drawer>
</div>
