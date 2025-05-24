<?php

use App\Models\TimetableSlot;
use App\Models\Session;
use App\Models\SubjectEnrollment;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Timetable Slot Details')] class extends Component {
    use Toast;

    public TimetableSlot $timetableSlot;

    public function mount(TimetableSlot $timetableSlot): void
    {
        $this->timetableSlot = $timetableSlot->load([
            'subject.curriculum',
            'subject.subjectEnrollments.programEnrollment.childProfile',
            'teacherProfile.user',
            'teacherProfile.subjects'
        ]);
    }

    public function deleteSlot(): void
    {
        try {
            $this->timetableSlot->delete();
            $this->success('Timetable slot deleted successfully.');
            $this->redirect(route('admin.timetable.index'));
        } catch (\Exception $e) {
            $this->error('Error deleting slot: ' . $e->getMessage());
        }
    }

    public function getEnrolledStudentsProperty(): Collection
    {
        if (!$this->timetableSlot->subject) {
            return collect();
        }

        return $this->timetableSlot->subject->subjectEnrollments()
            ->with(['programEnrollment.childProfile', 'programEnrollment.academicYear'])
            ->get()
            ->pluck('programEnrollment')
            ->filter()
            ->unique('id');
    }

    public function getTeacherOtherSlotsProperty(): Collection
    {
        return TimetableSlot::where('teacher_profile_id', $this->timetableSlot->teacher_profile_id)
            ->where('id', '!=', $this->timetableSlot->id)
            ->with(['subject'])
            ->orderBy('start_time')
            ->get();
    }

    public function getRelatedSessionsProperty(): Collection
    {
        return Session::where('subject_id', $this->timetableSlot->subject_id)
            ->where('teacher_profile_id', $this->timetableSlot->teacher_profile_id)
            ->whereDate('start_time', $this->timetableSlot->start_time->format('Y-m-d'))
            ->with(['attendances.programEnrollment.childProfile'])
            ->orderBy('start_time')
            ->get();
    }

    public function getSameDayOtherSlotsProperty(): Collection
    {
        return TimetableSlot::whereDate('start_time', $this->timetableSlot->start_time->format('Y-m-d'))
            ->where('id', '!=', $this->timetableSlot->id)
            ->with(['subject', 'teacherProfile.user'])
            ->orderBy('start_time')
            ->get();
    }

    public function getDurationProperty(): string
    {
        $minutes = $this->timetableSlot->start_time->diffInMinutes($this->timetableSlot->end_time);
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($hours > 0 && $remainingMinutes > 0) {
            return "{$hours}h {$remainingMinutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$remainingMinutes}m";
        }
    }

    public function getFormattedDateProperty(): string
    {
        return $this->timetableSlot->start_time->format('l, F j, Y');
    }

    public function getFormattedTimeProperty(): string
    {
        return $this->timetableSlot->start_time->format('g:i A') . ' - ' .
               $this->timetableSlot->end_time->format('g:i A');
    }

    public function getStatusColorProperty(): string
    {
        $now = now();
        $slotStart = $this->timetableSlot->start_time;
        $slotEnd = $this->timetableSlot->end_time;

        if ($now < $slotStart) {
            return 'info'; // Upcoming
        } elseif ($now >= $slotStart && $now <= $slotEnd) {
            return 'success'; // Ongoing
        } else {
            return 'ghost'; // Completed
        }
    }

    public function getStatusTextProperty(): string
    {
        $now = now();
        $slotStart = $this->timetableSlot->start_time;
        $slotEnd = $this->timetableSlot->end_time;

        if ($now < $slotStart) {
            $diff = $now->diffForHumans($slotStart, true);
            return "Starts in {$diff}";
        } elseif ($now >= $slotStart && $now <= $slotEnd) {
            $diff = $now->diffForHumans($slotEnd, true);
            return "Ends in {$diff}";
        } else {
            return "Completed";
        }
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Timetable Slot Details" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-3">
                <x-badge
                    label="{{ $this->statusText }}"
                    color="{{ $this->statusColor }}"
                    class="badge-lg"
                />
                <div class="text-sm text-gray-600">
                    {{ $timetableSlot->subject->name ?? 'Unknown Subject' }} - {{ $timetableSlot->day }}
                </div>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <div class="flex gap-2">
                <x-button
                    label="Edit"
                    icon="o-pencil"
                    link="{{ route('admin.timetable.edit', $timetableSlot->id) }}"
                    color="primary"
                />

                <x-button
                    label="Delete"
                    icon="o-trash"
                    wire:click="deleteSlot"
                    wire:confirm="Are you sure you want to delete this timetable slot? This action cannot be undone."
                    color="error"
                />

                <x-button
                    label="Back to Timetable"
                    icon="o-arrow-left"
                    link="{{ route('admin.timetable.index') }}"
                    class="btn-ghost"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Main Content (2/3) -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Slot Overview -->
            <x-card title="Slot Information">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject</div>
                        <div class="text-xl font-bold text-gray-900">
                            {{ $timetableSlot->subject->name ?? 'Unknown Subject' }}
                        </div>
                        <div class="text-sm text-gray-600">
                            {{ $timetableSlot->subject->code ?? 'No Code' }}
                        </div>
                        @if($timetableSlot->subject && $timetableSlot->subject->curriculum)
                            <div class="mt-1">
                                <x-badge
                                    label="{{ $timetableSlot->subject->curriculum->name }}"
                                    color="info"
                                    class="badge-sm"
                                />
                            </div>
                        @endif
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Teacher</div>
                        <div class="text-xl font-bold text-gray-900">
                            {{ $timetableSlot->teacherProfile->user->name ?? 'Unknown Teacher' }}
                        </div>
                        @if($timetableSlot->teacherProfile->specialization)
                            <div class="text-sm text-gray-600">
                                {{ $timetableSlot->teacherProfile->specialization }}
                            </div>
                        @endif
                        @if($timetableSlot->teacherProfile->phone)
                            <div class="text-sm text-gray-600">
                                📞 {{ $timetableSlot->teacherProfile->phone }}
                            </div>
                        @endif
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Schedule</div>
                        <div class="text-lg font-semibold text-gray-900">
                            {{ $timetableSlot->day }}
                        </div>
                        <div class="text-sm text-gray-600">
                            {{ $this->formattedDate }}
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Time & Duration</div>
                        <div class="text-lg font-semibold text-gray-900">
                            {{ $this->formattedTime }}
                        </div>
                        <div class="text-sm text-gray-600">
                            Duration: {{ $this->duration }}
                        </div>
                    </div>
                </div>

                @if($timetableSlot->teacherProfile && $timetableSlot->teacherProfile->bio)
                    <div class="pt-6 mt-6 border-t border-gray-200">
                        <div class="mb-2 text-sm font-medium text-gray-500">Teacher Bio</div>
                        <div class="leading-relaxed text-gray-700">
                            {{ $timetableSlot->teacherProfile->bio }}
                        </div>
                    </div>
                @endif
            </x-card>

            <!-- Enrolled Students -->
            <x-card title="Enrolled Students">
                @if($this->enrolledStudents->count() > 0)
                    <div class="space-y-3">
                        @foreach($this->enrolledStudents as $enrollment)
                            <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                <div class="flex items-center space-x-3">
                                    <div class="flex items-center justify-center w-10 h-10 bg-blue-500 rounded-full">
                                        <span class="text-sm font-semibold text-white">
                                            {{ strtoupper(substr($enrollment->childProfile->full_name ?? 'UK', 0, 2)) }}
                                        </span>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-900">
                                            {{ $enrollment->childProfile->full_name ?? 'Unknown Student' }}
                                        </div>
                                        <div class="text-sm text-gray-600">
                                            Enrollment #{{ $enrollment->id }}
                                            @if($enrollment->academicYear)
                                                • {{ $enrollment->academicYear->name }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <x-badge
                                        label="{{ $enrollment->status ?? 'Unknown' }}"
                                        color="{{ match(strtolower($enrollment->status ?? '')) {
                                            'active' => 'success',
                                            'pending' => 'warning',
                                            'completed' => 'info',
                                            'cancelled' => 'error',
                                            default => 'ghost'
                                        } }}"
                                        class="badge-sm"
                                    />
                                    <x-button
                                        icon="o-eye"
                                        link="{{ route('admin.enrollments.show', $enrollment->id) }}"
                                        class="btn-sm btn-ghost"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 text-center">
                        <div class="text-sm text-gray-600">
                            Total Students: {{ $this->enrolledStudents->count() }}
                        </div>
                    </div>
                @else
                    <div class="py-8 text-center">
                        <div class="text-gray-500">
                            <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                            </svg>
                            <div class="mt-2 text-sm font-medium text-gray-900">No students enrolled</div>
                            <div class="mt-1 text-sm text-gray-500">Students will appear here when they enroll in this subject.</div>
                        </div>
                    </div>
                @endif
            </x-card>

            <!-- Related Sessions -->
            @if($this->relatedSessions->count() > 0)
                <x-card title="Related Sessions">
                    <div class="space-y-3">
                        @foreach($this->relatedSessions as $session)
                            <div class="flex items-center justify-between p-4 border border-green-200 rounded-lg bg-green-50">
                                <div>
                                    <div class="font-semibold text-green-800">
                                        Session: {{ $session->start_time->format('g:i A') }} - {{ $session->end_time->format('g:i A') }}
                                    </div>
                                    <div class="text-sm text-green-600">
                                        Type: {{ ucfirst($session->type ?? 'Regular') }}
                                        @if($session->attendances->count() > 0)
                                            • {{ $session->attendances->count() }} attendees
                                        @endif
                                    </div>
                                    @if($session->link)
                                        <div class="text-sm text-green-600">
                                            <a href="{{ $session->link }}" target="_blank" class="hover:underline">
                                                🔗 Session Link
                                            </a>
                                        </div>
                                    @endif
                                </div>
                                <x-button
                                    icon="o-eye"
                                    class="btn-sm btn-ghost"
                                    title="View Session"
                                />
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Sidebar (1/3) -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-3">
                    <x-button
                        label="Edit Slot"
                        icon="o-pencil"
                        link="{{ route('admin.timetable.edit', $timetableSlot->id) }}"
                        color="primary"
                        class="w-full"
                    />

                    <x-button
                        label="Create Session"
                        icon="o-plus"
                        color="success"
                        class="w-full"
                        title="Create a session for this time slot"
                    />

                    <x-button
                        label="View Subject"
                        icon="o-book-open"
                        color="info"
                        class="w-full"
                        title="View subject details"
                    />

                    <x-button
                        label="Teacher Profile"
                        icon="o-user"
                        color="ghost"
                        class="w-full"
                        title="View teacher profile"
                    />
                </div>
            </x-card>

            <!-- Slot Statistics -->
            <x-card title="Statistics">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Enrolled Students</span>
                        <span class="font-semibold">{{ $this->enrolledStudents->count() }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Duration</span>
                        <span class="font-semibold">{{ $this->duration }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Related Sessions</span>
                        <span class="font-semibold">{{ $this->relatedSessions->count() }}</span>
                    </div>

                    @if($timetableSlot->teacherProfile)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Teacher's Other Slots</span>
                            <span class="font-semibold">{{ $this->teacherOtherSlots->count() }}</span>
                        </div>
                    @endif

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Same Day Slots</span>
                        <span class="font-semibold">{{ $this->sameDayOtherSlots->count() }}</span>
                    </div>
                </div>
            </x-card>

            <!-- Teacher's Other Slots Today -->
            @if($this->teacherOtherSlots->count() > 0)
                <x-card title="Teacher's Other Slots">
                    <div class="space-y-2">
                        @foreach($this->teacherOtherSlots->take(5) as $slot)
                            <div class="flex items-center justify-between p-2 rounded bg-gray-50">
                                <div>
                                    <div class="text-sm font-semibold">{{ $slot->subject->name ?? 'N/A' }}</div>
                                    <div class="text-xs text-gray-600">{{ $slot->day }}</div>
                                </div>
                                <div class="text-xs text-gray-600">
                                    {{ $slot->start_time->format('H:i') }}
                                </div>
                            </div>
                        @endforeach
                        @if($this->teacherOtherSlots->count() > 5)
                            <div class="text-center">
                                <span class="text-xs text-gray-500">
                                    +{{ $this->teacherOtherSlots->count() - 5 }} more slots
                                </span>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Same Day Schedule -->
            @if($this->sameDayOtherSlots->count() > 0)
                <x-card title="Same Day Schedule">
                    <div class="space-y-2">
                        @foreach($this->sameDayOtherSlots->take(5) as $slot)
                            <div class="flex items-center justify-between p-2 rounded bg-blue-50">
                                <div>
                                    <div class="text-sm font-semibold">{{ $slot->subject->name ?? 'N/A' }}</div>
                                    <div class="text-xs text-blue-600">{{ $slot->teacherProfile->user->name ?? 'Unknown' }}</div>
                                </div>
                                <div class="text-xs text-blue-600">
                                    {{ $slot->start_time->format('H:i') }}
                                </div>
                            </div>
                        @endforeach
                        @if($this->sameDayOtherSlots->count() > 5)
                            <div class="text-center">
                                <span class="text-xs text-gray-500">
                                    +{{ $this->sameDayOtherSlots->count() - 5 }} more slots
                                </span>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Slot Timeline -->
            <x-card title="Timeline">
                <div class="space-y-3 text-sm">
                    <div class="flex items-center space-x-3">
                        <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                        <div>
                            <div class="font-medium">Created</div>
                            <div class="text-gray-600">{{ $timetableSlot->created_at->format('M j, Y g:i A') }}</div>
                        </div>
                    </div>

                    @if($timetableSlot->updated_at && $timetableSlot->updated_at != $timetableSlot->created_at)
                        <div class="flex items-center space-x-3">
                            <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
                            <div>
                                <div class="font-medium">Last Modified</div>
                                <div class="text-gray-600">{{ $timetableSlot->updated_at->format('M j, Y g:i A') }}</div>
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center space-x-3">
                        <div class="w-2 h-2 {{ $this->statusColor === 'info' ? 'bg-blue-500' : ($this->statusColor === 'success' ? 'bg-green-500' : 'bg-gray-500') }} rounded-full"></div>
                        <div>
                            <div class="font-medium">Status</div>
                            <div class="text-gray-600">{{ $this->statusText }}</div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
