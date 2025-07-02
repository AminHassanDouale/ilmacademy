<?php

use App\Models\Event;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Event Details')] class extends Component {
    use Toast;

    public Event $event;

    // Registration
    public bool $showRegistrationModal = false;
    public string $registrationNote = '';
    public bool $isRegistered = false;

    public function mount(Event $event): void
    {
        $this->event = $event->load(['creator', 'academicYear']);

        // Check if current user is registered
        $this->checkRegistrationStatus();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed event: {$event->title}",
            Event::class,
            $event->id,
            [
                'event_title' => $event->title,
                'event_type' => $event->type,
                'ip' => request()->ip()
            ]
        );
    }

    protected function checkRegistrationStatus(): void
    {
        if ($this->event->registration_required && $this->event->attendees) {
            $attendees = $this->event->attendees;
            $this->isRegistered = collect($attendees)->contains('user_id', Auth::id());
        }
    }

    public function register(): void
    {
        if (!$this->event->registration_required) {
            $this->error('Registration is not required for this event.');
            return;
        }

        if ($this->isRegistered) {
            $this->error('You are already registered for this event.');
            return;
        }

        // Check registration deadline
        if ($this->event->registration_deadline && now() > $this->event->registration_deadline) {
            $this->error('Registration deadline has passed.');
            return;
        }

        // Check max attendees
        if ($this->event->max_attendees) {
            $currentAttendees = count($this->event->attendees ?? []);
            if ($currentAttendees >= $this->event->max_attendees) {
                $this->error('This event is fully booked.');
                return;
            }
        }

        try {
            $attendees = $this->event->attendees ?? [];
            $attendees[] = [
                'user_id' => Auth::id(),
                'user_name' => Auth::user()->name,
                'user_email' => Auth::user()->email,
                'registered_at' => now()->toISOString(),
                'note' => $this->registrationNote,
            ];

            $this->event->update(['attendees' => $attendees]);

            ActivityLog::log(
                Auth::id(),
                'register',
                "Registered for event: {$this->event->title}",
                Event::class,
                $this->event->id,
                ['event_title' => $this->event->title]
            );

            $this->isRegistered = true;
            $this->showRegistrationModal = false;
            $this->registrationNote = '';
            $this->success('Successfully registered for the event!');

        } catch (\Exception $e) {
            $this->error('Error registering for event: ' . $e->getMessage());
        }
    }

    public function unregister(): void
    {
        if (!$this->isRegistered) {
            $this->error('You are not registered for this event.');
            return;
        }

        try {
            $attendees = collect($this->event->attendees ?? [])
                ->reject(fn($attendee) => $attendee['user_id'] === Auth::id())
                ->values()
                ->toArray();

            $this->event->update(['attendees' => $attendees]);

            ActivityLog::log(
                Auth::id(),
                'unregister',
                "Unregistered from event: {$this->event->title}",
                Event::class,
                $this->event->id,
                ['event_title' => $this->event->title]
            );

            $this->isRegistered = false;
            $this->success('Successfully unregistered from the event.');

        } catch (\Exception $e) {
            $this->error('Error unregistering from event: ' . $e->getMessage());
        }
    }

    public function deleteEvent(): void
    {
        try {
            $eventTitle = $this->event->title;
            $this->event->delete();

            ActivityLog::log(
                Auth::id(),
                'delete',
                "Deleted event: {$eventTitle}",
                Event::class,
                $this->event->id,
                ['event_title' => $eventTitle]
            );

            $this->success('Event deleted successfully.');
            return $this->redirect(route('admin.calendar.index'));

        } catch (\Exception $e) {
            $this->error('Error deleting event: ' . $e->getMessage());
        }
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

    // Get status color
    public function getStatusColor(string $status): string
    {
        return match ($status) {
            'active' => 'bg-green-100 text-green-800',
            'cancelled' => 'bg-red-100 text-red-800',
            'postponed' => 'bg-yellow-100 text-yellow-800',
            default => 'bg-gray-100 text-gray-800'
        };
    }

    // Format date for display
    public function formatEventDate(): string
    {
        if ($this->event->is_all_day) {
            if ($this->event->start_date->format('Y-m-d') === $this->event->end_date->format('Y-m-d')) {
                return $this->event->start_date->format('l, F d, Y') . ' (All day)';
            } else {
                return $this->event->start_date->format('M d') . ' - ' . $this->event->end_date->format('M d, Y') . ' (All day)';
            }
        } else {
            if ($this->event->start_date->format('Y-m-d') === $this->event->end_date->format('Y-m-d')) {
                return $this->event->start_date->format('l, F d, Y') . ' from ' .
                       $this->event->start_date->format('g:i A') . ' to ' .
                       $this->event->end_date->format('g:i A');
            } else {
                return $this->event->start_date->format('M d, Y g:i A') . ' - ' .
                       $this->event->end_date->format('M d, Y g:i A');
            }
        }
    }

    public function with(): array
    {
        return [
            'canEdit' => Auth::id() === $this->event->created_by || Auth::user()->hasRole('admin'),
            'attendeeCount' => count($this->event->attendees ?? []),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="{{ $event->title }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Status Badge -->
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($event->status) }}">
                {{ ucfirst($event->status) }}
            </span>

            <!-- Event Type Badge -->
            <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium rounded-full {{ $this->getEventTypeColor($event->type) }}">
                {{ ucfirst($event->type) }}
            </span>
        </x-slot:middle>

        <x-slot:actions>
            <!-- Registration Actions -->
            @if($event->registration_required)
                @if(!$isRegistered)
                    <x-button
                        label="Register"
                        icon="o-plus"
                        wire:click="$set('showRegistrationModal', true)"
                        class="btn-success"
                    />
                @else
                    <x-button
                        label="Unregister"
                        icon="o-minus"
                        wire:click="unregister"
                        wire:confirm="Are you sure you want to unregister from this event?"
                        class="btn-warning"
                    />
                @endif
            @endif

            <!-- Edit/Delete Actions -->
            @if($canEdit)
                <x-button
                    label="Edit"
                    icon="o-pencil"
                    link="{{ route('admin.calendar.edit', $event) }}"
                    class="btn-primary"
                />

                <x-button
                    label="Delete"
                    icon="o-trash"
                    wire:click="deleteEvent"
                    wire:confirm="Are you sure you want to delete this event?"
                    class="btn-error"
                />
            @endif

            <x-button
                label="Back to Calendar"
                icon="o-arrow-left"
                link="{{ route('admin.calendar.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Main Event Details -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Event Information -->
            <x-card title="Event Details">
                <div class="space-y-6">
                    <!-- Event Header -->
                    <div class="flex items-start space-x-4">
                        <div
                            class="flex-shrink-0 w-4 h-16 rounded"
                            style="background-color: {{ $event->color ?? '#3B82F6' }}"
                        ></div>
                        <div class="flex-1">
                            <h2 class="text-2xl font-bold text-gray-900">{{ $event->title }}</h2>
                            <p class="mt-1 text-lg text-gray-600">{{ $this->formatEventDate() }}</p>
                            @if($event->location)
                                <p class="flex items-center mt-2 text-gray-600">
                                    <x-icon name="o-map-pin" class="w-4 h-4 mr-2" />
                                    {{ $event->location }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <!-- Description -->
                    @if($event->description)
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Description</h3>
                            <div class="mt-2 prose max-w-none">
                                {!! nl2br(e($event->description)) !!}
                            </div>
                        </div>
                    @endif

                    <!-- Event Details Grid -->
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Event Type</h4>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getEventTypeColor($event->type) }}">
                                {{ ucfirst($event->type) }}
                            </span>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Status</h4>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($event->status) }}">
                                {{ ucfirst($event->status) }}
                            </span>
                        </div>

                        @if($event->academicYear)
                            <div>
                                <h4 class="text-sm font-medium text-gray-500">Academic Year</h4>
                                <p class="mt-1 text-sm text-gray-900">{{ $event->academicYear->name }}</p>
                            </div>
                        @endif

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Created By</h4>
                            <p class="mt-1 text-sm text-gray-900">{{ $event->creator->name }}</p>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Registration Information -->
            @if($event->registration_required)
                <x-card title="Registration Information">
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                            <div>
                                <h4 class="text-sm font-medium text-gray-500">Registration Status</h4>
                                <p class="mt-1 text-sm font-semibold {{ $isRegistered ? 'text-green-600' : 'text-gray-600' }}">
                                    {{ $isRegistered ? 'Registered' : 'Not Registered' }}
                                </p>
                            </div>

                            @if($event->max_attendees)
                                <div>
                                    <h4 class="text-sm font-medium text-gray-500">Capacity</h4>
                                    <p class="mt-1 text-sm text-gray-900">
                                        {{ $attendeeCount }} / {{ $event->max_attendees }} attendees
                                    </p>
                                </div>
                            @endif

                            @if($event->registration_deadline)
                                <div>
                                    <h4 class="text-sm font-medium text-gray-500">Registration Deadline</h4>
                                    <p class="mt-1 text-sm text-gray-900">
                                        {{ $event->registration_deadline->format('M d, Y g:i A') }}
                                    </p>
                                </div>
                            @endif
                        </div>

                        <!-- Attendees List (for admins and event creators) -->
                        @if($canEdit && $attendeeCount > 0)
                            <div>
                                <h4 class="mb-3 text-sm font-medium text-gray-500">Registered Attendees</h4>
                                <div class="space-y-2">
                                    @foreach($event->attendees as $attendee)
                                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                            <div>
                                                <p class="text-sm font-medium text-gray-900">{{ $attendee['user_name'] }}</p>
                                                <p class="text-xs text-gray-500">{{ $attendee['user_email'] }}</p>
                                                @if(!empty($attendee['note']))
                                                    <p class="text-xs text-gray-600 mt-1">Note: {{ $attendee['note'] }}</p>
                                                @endif
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                {{ \Carbon\Carbon::parse($attendee['registered_at'])->format('M d, Y') }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <x-card title="Actions">
                <div class="space-y-3">
                    @if($event->registration_required && !$isRegistered)
                        <x-button
                            label="Register for Event"
                            icon="o-plus"
                            wire:click="$set('showRegistrationModal', true)"
                            class="w-full btn-success"
                        />
                    @elseif($event->registration_required && $isRegistered)
                        <x-button
                            label="Unregister"
                            icon="o-minus"
                            wire:click="unregister"
                            wire:confirm="Are you sure you want to unregister from this event?"
                            class="w-full btn-warning"
                        />
                    @endif

                    @if($canEdit)
                        <x-button
                            label="Edit Event"
                            icon="o-pencil"
                            link="{{ route('admin.calendar.edit', $event) }}"
                            class="w-full btn-primary"
                        />

                        <x-button
                            label="Delete Event"
                            icon="o-trash"
                            wire:click="deleteEvent"
                            wire:confirm="Are you sure you want to delete this event?"
                            class="w-full btn-error"
                        />
                    @endif

                    <x-button
                        label="Back to Calendar"
                        icon="o-calendar"
                        link="{{ route('admin.calendar.index') }}"
                        class="w-full btn-outline"
                    />
                </div>
            </x-card>

            <!-- Event Statistics -->
            @if($event->registration_required)
                <x-card title="Event Statistics">
                    <div class="space-y-4">
                        <div class="text-center">
                            <div class="text-3xl font-bold text-blue-600">{{ $attendeeCount }}</div>
                            <div class="text-sm text-gray-500">
                                @if($event->max_attendees)
                                    of {{ $event->max_attendees }} attendees
                                @else
                                    attendees registered
                                @endif
                            </div>
                        </div>

                        @if($event->max_attendees)
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div
                                    class="bg-blue-600 h-2 rounded-full"
                                    style="width: {{ $event->max_attendees > 0 ? ($attendeeCount / $event->max_attendees) * 100 : 0 }}%"
                                ></div>
                            </div>
                            <div class="text-xs text-center text-gray-500">
                                {{ $event->max_attendees > 0 ? round(($attendeeCount / $event->max_attendees) * 100) : 0 }}% capacity
                            </div>
                        @endif

                        @if($event->registration_deadline)
                            <div class="p-3 rounded-lg {{ now() > $event->registration_deadline ? 'bg-red-50' : 'bg-green-50' }}">
                                <div class="text-sm font-medium {{ now() > $event->registration_deadline ? 'text-red-800' : 'text-green-800' }}">
                                    Registration {{ now() > $event->registration_deadline ? 'Closed' : 'Open' }}
                                </div>
                                <div class="text-xs {{ now() > $event->registration_deadline ? 'text-red-600' : 'text-green-600' }}">
                                    Until {{ $event->registration_deadline->format('M d, Y g:i A') }}
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- System Information -->
            <x-card title="Event Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Event ID</div>
                        <div class="font-mono text-xs">{{ $event->id }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $event->created_at->format('M d, Y g:i A') }}</div>
                        <div class="text-xs text-gray-500">{{ $event->created_at->diffForHumans() }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $event->updated_at->format('M d, Y g:i A') }}</div>
                        <div class="text-xs text-gray-500">{{ $event->updated_at->diffForHumans() }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Created By</div>
                        <div>{{ $event->creator->name }}</div>
                        <div class="text-xs text-gray-500">{{ $event->creator->email }}</div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>

    <!-- Registration Modal -->
    <x-modal wire:model="showRegistrationModal" title="Register for Event" class="backdrop-blur">
        <div class="space-y-4">
            <div class="p-4 rounded-lg bg-blue-50">
                <h3 class="font-medium text-blue-900">{{ $event->title }}</h3>
                <p class="text-sm text-blue-700">{{ $this->formatEventDate() }}</p>
                @if($event->location)
                    <p class="text-sm text-blue-700">{{ $event->location }}</p>
                @endif
            </div>

            @if($event->max_attendees && $attendeeCount >= $event->max_attendees)
                <div class="p-4 text-red-700 bg-red-50 rounded-lg">
                    <p class="font-medium">Event is Full</p>
                    <p class="text-sm">This event has reached its maximum capacity of {{ $event->max_attendees }} attendees.</p>
                </div>
            @elseif($event->registration_deadline && now() > $event->registration_deadline)
                <div class="p-4 text-red-700 bg-red-50 rounded-lg">
                    <p class="font-medium">Registration Closed</p>
                    <p class="text-sm">The registration deadline for this event has passed.</p>
                </div>
            @else
                <x-textarea
                    label="Note (Optional)"
                    wire:model="registrationNote"
                    placeholder="Add any additional notes or requirements..."
                    rows="3"
                />

                @if($event->max_attendees)
                    <div class="text-sm text-gray-600">
                        <strong>Available spots:</strong> {{ $event->max_attendees - $attendeeCount }} of {{ $event->max_attendees }}
                    </div>
                @endif
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showRegistrationModal', false)" />
            @if(!($event->max_attendees && $attendeeCount >= $event->max_attendees) && !($event->registration_deadline && now() > $event->registration_deadline))
                <x-button label="Register" wire:click="register" class="btn-primary" />
            @endif
        </x-slot:actions>
    </x-modal>
</div>
