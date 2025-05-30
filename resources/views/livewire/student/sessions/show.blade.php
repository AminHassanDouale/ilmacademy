<?php

use App\Models\Session;
use App\Models\Attendance;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Carbon\Carbon;

new #[Title('Session Details')] class extends Component {
    use Toast;

    public Session $session;
    public $attendance;
    public $childProfiles = [];
    public $selectedChildId = null;

    // Load data
    public function mount(Session $session): void
    {
        // Get the client profile associated with this user
        $clientProfile = Auth::user()->clientProfile;

        if (!$clientProfile) {
            $this->error("You don't have a client profile.");
            return redirect()->route('student.sessions.index');
        }

        // Get child profiles associated with this parent
        $this->childProfiles = \App\Models\ChildProfile::where('parent_profile_id', $clientProfile->id)->get();
        $childProfileIds = $this->childProfiles->pluck('id')->toArray();

        // Check if the session has attendances for any of the user's children
        $hasAttendance = Attendance::where('session_id', $session->id)
            ->whereIn('child_profile_id', $childProfileIds)
            ->exists();

        if (!$hasAttendance) {
            $this->error("You don't have access to this session.");
            return redirect()->route('student.sessions.index');
        }

        $this->session = $session;
        $this->session->load(['subject', 'teacherProfile.user']);

        // Set selected child to the first one with attendance by default
        $firstAttendance = Attendance::where('session_id', $session->id)
            ->whereIn('child_profile_id', $childProfileIds)
            ->first();

        if ($firstAttendance) {
            $this->selectedChildId = $firstAttendance->child_profile_id;
            $this->loadAttendance();
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            'Student viewed session details',
            Session::class,
            $session->id,
            ['ip' => request()->ip()]
        );
    }

    // Change selected child
    public function changeChild($childId): void
    {
        $this->selectedChildId = $childId;
        $this->loadAttendance();
    }

    // Load attendance for selected child
    public function loadAttendance(): void
    {
        if (!$this->selectedChildId) {
            return;
        }

        $this->attendance = Attendance::where('session_id', $this->session->id)
            ->where('child_profile_id', $this->selectedChildId)
            ->first();
    }

    // Join session
    public function joinSession()
    {
        if ($this->session->type !== 'online') {
            $this->error('This is not an online session.');
            return;
        }

        // Check if the session is starting soon (within 15 minutes) or has already started
        $now = Carbon::now();
        $sessionStart = Carbon::parse($this->session->start_time);
        $canJoin = $now->diffInMinutes($sessionStart, false) <= 15;

        if (!$canJoin) {
            $this->error('You can only join a session 15 minutes before its scheduled start time.');
            return;
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'join',
            'Student joined a class session',
            Session::class,
            $this->session->id,
            ['ip' => request()->ip()]
        );

        // Redirect to the session link
        return redirect()->away($this->session->link);
    }

    // Get related sessions with the same subject
    public function getRelatedSessions()
    {
        // Get the client profile associated with this user
        $clientProfile = Auth::user()->clientProfile;

        if (!$clientProfile) {
            return collect();
        }

        // Get child profiles associated with this parent
        $childProfiles = \App\Models\ChildProfile::where('parent_profile_id', $clientProfile->id)->get();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        return Session::with(['subject'])
            ->whereHas('attendances', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->where('subject_id', $this->session->subject_id)
            ->where('id', '!=', $this->session->id)
            ->where('start_time', '>', Carbon::now())
            ->orderBy('start_time', 'asc')
            ->limit(3)
            ->get();
    }

    // Back to sessions list
    public function backToList()
    {
        return redirect()->route('student.sessions.index');
    }

    // Check if session is upcoming
    public function isUpcoming()
    {
        return Carbon::parse($this->session->start_time)->isFuture();
    }

    // Get session status
    public function getSessionStatus()
    {
        $now = Carbon::now();
        $start = Carbon::parse($this->session->start_time);
        $end = Carbon::parse($this->session->end_time);

        if ($now->lt($start)) {
            return 'upcoming';
        } elseif ($now->gte($start) && $now->lte($end)) {
            return 'in_progress';
        } else {
            return 'completed';
        }
    }

    public function with(): array
    {
        return [
            'relatedSessions' => $this->getRelatedSessions(),
            'isUpcoming' => $this->isUpcoming(),
            'sessionStatus' => $this->getSessionStatus(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Session Details" separator back-button back-url="{{ route('student.sessions.index') }}">
        <x-slot:subtitle>
            {{ $session->subject->name }} - {{ Carbon\Carbon::parse($session->start_time)->format('M d, Y') }}
        </x-slot:subtitle>

        <!-- ACTIONS -->
        <x-slot:actions>
            @php
                $now = Carbon\Carbon::now();
                $sessionStart = Carbon\Carbon::parse($session->start_time);
                $canJoin = $session->type === 'online' && $now->diffInMinutes($sessionStart, false) <= 15 && $now->diffInMinutes($sessionStart, false) > -60;
            @endphp

            @if ($canJoin)
                <x-button
                    label="Join Session"
                    icon="o-video-camera"
                    color="success"
                    wire:click="joinSession"
                />
            @endif
        </x-slot:actions>
    </x-header>

    <!-- Session Details -->
    <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-3">
        <!-- Left column - Session metadata -->
        <div class="col-span-1">
            <x-card title="Session Information">
                <div class="space-y-4">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Date</div>
                        <div class="mt-1 font-semibold">{{ Carbon\Carbon::parse($session->start_time)->format('l, F d, Y') }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Time</div>
                        <div class="mt-1">{{ Carbon\Carbon::parse($session->start_time)->format('g:i A') }} - {{ Carbon\Carbon::parse($session->end_time)->format('g:i A') }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject</div>
                        <div class="mt-1">{{ $session->subject->name }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Teacher</div>
                        <div class="mt-1">
                            @if($session->teacherProfile && $session->teacherProfile->user)
                                {{ $session->teacherProfile->user->name }}
                            @else
                                <span class="text-gray-400">To be assigned</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Session Type</div>
                        <div class="mt-1">
                            @if($session->type === 'online')
                                <span class="inline-flex items-center">
                                    <x-icon name="o-video-camera" class="w-4 h-4 mr-1 text-info" />
                                    Online Session
                                </span>
                            @else
                                <span class="inline-flex items-center">
                                    <x-icon name="o-map-pin" class="w-4 h-4 mr-1 text-warning" />
                                    In-Person Session
                                </span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div class="mt-1">
                            @if ($sessionStatus === 'upcoming')
                                <x-badge label="Upcoming" color="warning" />
                            @elseif ($sessionStatus === 'in_progress')
                                <x-badge label="In Progress" color="success" />
                            @else
                                <x-badge label="Completed" color="info" />
                            @endif
                        </div>
                    </div>
                </div>

                @if($session->type === 'online' && $isUpcoming)
                    <div class="mt-6">
                        <div class="p-4 rounded-lg bg-base-200">
                            <div class="text-sm font-medium">Session Link</div>
                            <div class="mt-2">
                                @if($canJoin)
                                    <x-button
                                        label="Join Now"
                                        icon="o-video-camera"
                                        color="success"
                                        class="w-full"
                                        wire:click="joinSession"
                                    />
                                @else
                                    <p class="text-sm text-gray-500">
                                        The session link will be available 15 minutes before the session starts.
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </x-card>

            @if(count($childProfiles) > 1)
                <x-card title="Child Selection" class="mt-4">
                    <div class="space-y-4">
                        <div class="text-sm text-gray-500">
                            Select which child's attendance to view:
                        </div>

                        <div>
                            @foreach($childProfiles as $child)
                                @php
                                    $hasAttendance = \App\Models\Attendance::where('session_id', $session->id)
                                        ->where('child_profile_id', $child->id)
                                        ->exists();
                                @endphp

                                @if($hasAttendance)
                                    <div class="mb-2">
                                        <x-radio
                                            id="child-{{ $child->id }}"
                                            wire:model.live="selectedChildId"
                                            value="{{ $child->id }}"
                                            label="{{ $child->user->name }}"
                                        />
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Right column - Details and attendance -->
        <div class="col-span-1 md:col-span-2">
            <!-- Attendance information if session is in the past -->
            @if(!$isUpcoming && $attendance)
                <x-card title="Attendance Record">
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <div class="text-sm font-medium text-gray-500">Attendance Status</div>
                                <div class="mt-1">
                                    @if($attendance->status === 'present')
                                        <x-badge label="Present" color="success" />
                                    @elseif($attendance->status === 'absent')
                                        <x-badge label="Absent" color="error" />
                                    @elseif($attendance->status === 'excused')
                                        <x-badge label="Excused" color="warning" />
                                    @elseif($attendance->status === 'late')
                                        <x-badge label="Late" color="info" />
                                    @else
                                        <x-badge label="{{ ucfirst($attendance->status) }}" color="neutral" />
                                    @endif
                                </div>
                            </div>

                            @if($attendance->check_in_time)
                                <div>
                                    <div class="text-sm font-medium text-gray-500">Check-in Time</div>
                                    <div class="mt-1">{{ Carbon\Carbon::parse($attendance->check_in_time)->format('g:i A') }}</div>
                                </div>
                            @endif
                        </div>

                        @if($attendance->notes)
                            <div>
                                <div class="text-sm font-medium text-gray-500">Notes</div>
                                <div class="p-3 mt-1 text-sm rounded-lg bg-base-200">
                                    {{ $attendance->notes }}
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Session details -->
            <x-card title="Session Details" class="mt-4">
                <div class="space-y-4">
                    @if($session->description)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Description</div>
                            <div class="p-3 mt-1 rounded-lg bg-base-200">
                                {{ $session->description ?? 'No description available.' }}
                            </div>
                        </div>
                    @endif

                    @if($session->location)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Location</div>
                            <div class="mt-1">
                                <div class="flex items-start">
                                    <x-icon name="o-map-pin" class="w-5 h-5 mr-1 text-gray-500 flex-shrink-0 mt-0.5" />
                                    <span>{{ $session->location }}</span>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if($session->equipment)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Required Equipment</div>
                            <div class="mt-1">
                                <ul class="list-disc list-inside">
                                    @foreach(explode(',', $session->equipment) as $item)
                                        <li>{{ trim($item) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Related sessions -->
            @if(count($relatedSessions) > 0)
                <x-card title="Upcoming Sessions for This Subject" class="mt-4">
                    <div class="space-y-4">
                        @foreach($relatedSessions as $relatedSession)
                            <div class="p-3 transition-colors border rounded-lg hover:bg-base-100">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-medium">{{ Carbon\Carbon::parse($relatedSession->start_time)->format('l, F d, Y') }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ Carbon\Carbon::parse($relatedSession->start_time)->format('g:i A') }} - {{ Carbon\Carbon::parse($relatedSession->end_time)->format('g:i A') }}
                                        </div>
                                    </div>
                                    <x-button
                                        icon="o-eye"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View Session Details"
                                        href="{{ route('student.sessions.show', $relatedSession->id) }}"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>
