<?php

use App\Models\TeacherProfile;
use App\Models\TimetableSlot;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Teacher Timetable')] class extends Component {
    use Toast;

    // Teacher to display timetable for
    public TeacherProfile $teacher;

    // Current week being viewed
    #[Url]
    public string $currentWeek = '';

    // View options
    #[Url]
    public string $view = 'week'; // week, day, month

    #[Url]
    public bool $showConflicts = false;

    // Time settings
    public int $startHour = 8;
    public int $endHour = 18;
    public array $timeSlots = [];
    public array $daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    // Component initialization
    public function mount(TeacherProfile $teacherProfile): void
    {
        $this->teacher = $teacherProfile;

        // Set current week if not provided
        if (empty($this->currentWeek)) {
            $this->currentWeek = now()->startOfWeek()->format('Y-m-d');
        }

        // Generate time slots
        $this->generateTimeSlots();

        // Log access to timetable page
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'access',
            'description' => "Viewed timetable for teacher: " . ($teacherProfile->user ? $teacherProfile->user->name : 'Unknown'),
            'loggable_type' => TeacherProfile::class,
            'loggable_id' => $teacherProfile->id,
            'ip_address' => request()->ip(),
        ]);
    }

    // Generate time slots for the timetable
    private function generateTimeSlots(): void
    {
        $this->timeSlots = [];
        for ($hour = $this->startHour; $hour <= $this->endHour; $hour++) {
            $this->timeSlots[] = sprintf('%02d:00', $hour);
            if ($hour < $this->endHour) {
                $this->timeSlots[] = sprintf('%02d:30', $hour);
            }
        }
    }

    // Navigate between weeks
    public function previousWeek(): void
    {
        $currentWeek = Carbon::parse($this->currentWeek);
        $this->currentWeek = $currentWeek->subWeek()->format('Y-m-d');
    }

    public function nextWeek(): void
    {
        $currentWeek = Carbon::parse($this->currentWeek);
        $this->currentWeek = $currentWeek->addWeek()->format('Y-m-d');
    }

    public function goToCurrentWeek(): void
    {
        $this->currentWeek = now()->startOfWeek()->format('Y-m-d');
    }

    // Change view type
    public function setView(string $view): void
    {
        $this->view = $view;
    }

    // Go back to teacher profile
    public function backToProfile(): void
    {
        redirect()->route('admin.teachers.show', $this->teacher);
    }

    // Get timetable slots for the current week
    public function getTimetableSlots(): Collection
    {
        if (!method_exists($this->teacher, 'timetableSlots')) {
            return collect([]);
        }

        return $this->teacher->timetableSlots()
            ->with(['subject'])
            ->get()
            ->groupBy('day');
    }

    // Get formatted week dates
    public function getWeekDates(): array
    {
        $startOfWeek = Carbon::parse($this->currentWeek);
        $dates = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);
            $dates[$this->daysOfWeek[$i]] = [
                'date' => $date->format('Y-m-d'),
                'formatted' => $date->format('M j'),
                'is_today' => $date->isToday(),
                'is_weekend' => $date->isWeekend(),
            ];
        }

        return $dates;
    }

    // Check if a slot conflicts with another
    public function hasConflict(TimetableSlot $slot, Collection $allSlots): bool
    {
        return $allSlots->where('day', $slot->day)
            ->where('id', '!=', $slot->id)
            ->filter(function ($otherSlot) use ($slot) {
                $slotStart = Carbon::parse($slot->start_time);
                $slotEnd = Carbon::parse($slot->end_time);
                $otherStart = Carbon::parse($otherSlot->start_time);
                $otherEnd = Carbon::parse($otherSlot->end_time);

                return ($slotStart < $otherEnd && $slotEnd > $otherStart);
            })
            ->isNotEmpty();
    }

    // Get slot duration in minutes
    public function getSlotDuration(TimetableSlot $slot): int
    {
        $start = Carbon::parse($slot->start_time);
        $end = Carbon::parse($slot->end_time);
        return $start->diffInMinutes($end);
    }

    // Get slot position in grid (for CSS positioning)
    public function getSlotPosition(TimetableSlot $slot): array
    {
        $start = Carbon::parse($slot->start_time);
        $startMinutes = ($start->hour - $this->startHour) * 60 + $start->minute;
        $duration = $this->getSlotDuration($slot);

        return [
            'top' => ($startMinutes / 30) * 2.5, // 2.5rem per 30 minutes
            'height' => ($duration / 30) * 2.5,
        ];
    }

    // Get color for subject
    public function getSubjectColor(string $subjectName): string
    {
        $colors = [
            'primary', 'secondary', 'accent', 'info', 'success',
            'warning', 'error', 'purple', 'pink', 'indigo'
        ];

        $hash = md5($subjectName);
        $index = hexdec(substr($hash, 0, 2)) % count($colors);

        return $colors[$index];
    }

    // Create new timetable slot
    public function createSlot(string $day, string $time): void
    {
        redirect()->route('admin.timetable-slots.create', [
            'teacher' => $this->teacher->id,
            'day' => $day,
            'time' => $time
        ]);
    }

    // Edit existing slot
    public function editSlot(int $slotId): void
    {
        redirect()->route('admin.timetable-slots.edit', $slotId);
    }

    // Delete slot
    public function deleteSlot(int $slotId): void
    {
        try {
            $slot = TimetableSlot::findOrFail($slotId);
            $slotInfo = $slot->subject ? $slot->subject->name : 'Unknown Subject';
            $slotInfo .= ' - ' . $slot->day . ' ' . Carbon::parse($slot->start_time)->format('H:i');

            $slot->delete();

            // Log the deletion
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'delete',
                'description' => "Deleted timetable slot for {$this->teacher->user->name}: {$slotInfo}",
                'loggable_type' => TimetableSlot::class,
                'loggable_id' => $slotId,
                'ip_address' => request()->ip(),
            ]);

            $this->success("Timetable slot deleted successfully.");
        } catch (\Exception $e) {
            $this->error("Failed to delete timetable slot: {$e->getMessage()}");
        }
    }

    // Get timetable statistics
    public function getTimetableStats(): array
    {
        if (!method_exists($this->teacher, 'timetableSlots')) {
            return [
                'total_slots' => 0,
                'total_hours' => 0,
                'subjects_count' => 0,
                'busiest_day' => 'N/A',
                'conflicts' => 0
            ];
        }

        $slots = $this->teacher->timetableSlots()->with('subject')->get();
        $totalMinutes = $slots->sum(function ($slot) {
            return $this->getSlotDuration($slot);
        });

        $slotsByDay = $slots->groupBy('day');
        $busiestDay = $slotsByDay->map->count()->sortDesc()->keys()->first() ?? 'N/A';

        $conflicts = 0;
        foreach ($slotsByDay as $daySlots) {
            foreach ($daySlots as $slot) {
                if ($this->hasConflict($slot, $daySlots)) {
                    $conflicts++;
                }
            }
        }

        return [
            'total_slots' => $slots->count(),
            'total_hours' => round($totalMinutes / 60, 1),
            'subjects_count' => $slots->pluck('subject.name')->unique()->count(),
            'busiest_day' => $busiestDay,
            'conflicts' => $conflicts
        ];
    }

    public function with(): array
    {
        return [
            'timetableSlots' => $this->getTimetableSlots(),
            'weekDates' => $this->getWeekDates(),
            'stats' => $this->getTimetableStats(),
            'allSlots' => $this->getTimetableSlots()->flatten()
        ];
    }
};?>

<div>
    <x-header title="Timetable for {{ $teacher->user ? $teacher->user->name : 'Unknown Teacher' }}" separator>
        <x-slot:subtitle>
            Week of {{ \Carbon\Carbon::parse($currentWeek)->format('M j, Y') }} - {{ \Carbon\Carbon::parse($currentWeek)->addDays(6)->format('M j, Y') }}
        </x-slot:subtitle>

        <x-slot:middle class="!justify-center">
            <div class="flex items-center gap-2">
                <x-button
                    icon="o-chevron-left"
                    wire:click="previousWeek"
                    class="btn-ghost btn-sm"
                    tooltip="Previous Week"
                />

                <x-button
                    label="Current Week"
                    wire:click="goToCurrentWeek"
                    class="btn-ghost btn-sm"
                />

                <x-button
                    icon="o-chevron-right"
                    wire:click="nextWeek"
                    class="btn-ghost btn-sm"
                    tooltip="Next Week"
                />
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <div class="flex gap-2">
                <x-button
                    label="Stats"
                    icon="o-chart-bar"
                    :badge="$stats['conflicts'] > 0 ? $stats['conflicts'] : null"
                    badge-classes="badge-error"
                    wire:click="$toggle('showConflicts')"
                    class="{{ $showConflicts ? 'btn-active' : 'btn-ghost' }}"
                />

                <x-button
                    label="Back to Profile"
                    icon="o-arrow-left"
                    wire:click="backToProfile"
                    class="btn-ghost"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-5">
        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-xl font-bold">{{ $stats['total_slots'] }}</h3>
                <p class="text-sm opacity-70">Total Classes</p>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-xl font-bold">{{ $stats['total_hours'] }}h</h3>
                <p class="text-sm opacity-70">Weekly Hours</p>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-xl font-bold">{{ $stats['subjects_count'] }}</h3>
                <p class="text-sm opacity-70">Subjects</p>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-xl font-bold">{{ $stats['busiest_day'] }}</h3>
                <p class="text-sm opacity-70">Busiest Day</p>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-xl font-bold {{ $stats['conflicts'] > 0 ? 'text-error' : 'text-success' }}">
                    {{ $stats['conflicts'] }}
                </h3>
                <p class="text-sm opacity-70">Conflicts</p>
            </div>
        </x-card>
    </div>

    <!-- Timetable Grid -->
    <x-card class="overflow-hidden">
        <div class="overflow-x-auto">
            <div class="min-w-[800px] relative">
                <!-- Header with days -->
                <div class="grid grid-cols-8 gap-1 mb-4 text-center">
                    <div class="p-2 font-semibold text-center">Time</div>
                    @foreach ($weekDates as $day => $dateInfo)
                        <div class="p-2 font-semibold text-center {{ $dateInfo['is_today'] ? 'bg-primary/10 rounded' : '' }}">
                            <div>{{ $day }}</div>
                            <div class="text-xs opacity-70">{{ $dateInfo['formatted'] }}</div>
                            @if($dateInfo['is_today'])
                                <div class="text-xs font-normal">
                                    <x-badge label="Today" color="primary" class="badge-xs" />
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <!-- Time slots grid -->
                <div class="relative">
                    @foreach ($timeSlots as $timeSlot)
                        <div class="grid grid-cols-8 gap-1 border-b border-base-200">
                            <!-- Time column -->
                            <div class="p-2 text-sm font-medium text-center border-r border-base-200">
                                {{ $timeSlot }}
                            </div>

                            <!-- Days columns -->
                            @foreach ($weekDates as $day => $dateInfo)
                                <div class="relative h-10 border-r border-base-200 {{ $dateInfo['is_weekend'] ? 'bg-base-100' : '' }}">
                                    <!-- Slots for this day and time -->
                                    @if(isset($timetableSlots[$day]))
                                        @foreach ($timetableSlots[$day] as $slot)
                                            @php
                                                $slotStart = \Carbon\Carbon::parse($slot->start_time)->format('H:i');
                                                $slotEnd = \Carbon\Carbon::parse($slot->end_time)->format('H:i');
                                                $position = $this->getSlotPosition($slot);
                                                $hasConflict = $this->hasConflict($slot, $allSlots);
                                                $subjectColor = $this->getSubjectColor($slot->subject ? $slot->subject->name : 'Unknown');
                                            @endphp

                                            @if($slotStart <= $timeSlot && $timeSlot < $slotEnd)
                                                <div
                                                    class="absolute inset-x-1 bg-{{ $subjectColor }}/20 border border-{{ $subjectColor }} rounded group cursor-pointer hover:shadow-lg transition-all"
                                                    style="height: {{ $position['height'] }}rem; z-index: 10;"
                                                    x-data
                                                    x-on:click="document.getElementById('slot-modal-{{ $slot->id }}').showModal()"
                                                >
                                                    <div class="p-1 text-xs">
                                                        <div class="font-semibold truncate">
                                                            {{ $slot->subject ? $slot->subject->name : 'No Subject' }}
                                                        </div>
                                                        <div class="opacity-70">
                                                            {{ $slotStart }} - {{ $slotEnd }}
                                                        </div>
                                                        @if($hasConflict && $showConflicts)
                                                            <div class="text-error">
                                                                <x-icon name="o-exclamation-triangle" class="w-3 h-3" />
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>

                                                <!-- Slot Details Modal -->
                                                <dialog id="slot-modal-{{ $slot->id }}" class="modal">
                                                    <div class="modal-box">
                                                        <form method="dialog">
                                                            <button class="absolute btn btn-sm btn-circle btn-ghost right-2 top-2">✕</button>
                                                        </form>

                                                        <h3 class="text-lg font-bold">Class Details</h3>

                                                        <div class="py-4 space-y-4">
                                                            <div>
                                                                <label class="text-sm font-medium">Subject:</label>
                                                                <div class="text-lg">{{ $slot->subject ? $slot->subject->name : 'No Subject' }}</div>
                                                            </div>

                                                            <div>
                                                                <label class="text-sm font-medium">Day:</label>
                                                                <div>{{ $slot->day }}</div>
                                                            </div>

                                                            <div class="grid grid-cols-2 gap-4">
                                                                <div>
                                                                    <label class="text-sm font-medium">Start Time:</label>
                                                                    <div>{{ $slotStart }}</div>
                                                                </div>
                                                                <div>
                                                                    <label class="text-sm font-medium">End Time:</label>
                                                                    <div>{{ $slotEnd }}</div>
                                                                </div>
                                                            </div>

                                                            <div>
                                                                <label class="text-sm font-medium">Duration:</label>
                                                                <div>{{ $this->getSlotDuration($slot) }} minutes</div>
                                                            </div>

                                                            @if($hasConflict)
                                                                <div class="p-3 rounded bg-error/10">
                                                                    <div class="flex items-center gap-2 text-error">
                                                                        <x-icon name="o-exclamation-triangle" class="w-4 h-4" />
                                                                        <span class="font-medium">Time Conflict Detected</span>
                                                                    </div>
                                                                    <div class="mt-1 text-sm">
                                                                        This slot overlaps with another class on the same day.
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        </div>

                                                        <div class="flex gap-2 modal-action">
                                                            <x-button
                                                                label="Edit"
                                                                icon="o-pencil"
                                                                wire:click="editSlot({{ $slot->id }})"
                                                                color="info"
                                                            />
                                                            <x-button
                                                                label="Delete"
                                                                icon="o-trash"
                                                                color="error"
                                                                x-data
                                                                x-on:click="
                                                                    if (confirm('Are you sure you want to delete this timetable slot?')) {
                                                                        $wire.deleteSlot({{ $slot->id }});
                                                                        document.getElementById('slot-modal-{{ $slot->id }}').close();
                                                                    }
                                                                "
                                                            />
                                                            <form method="dialog">
                                                                <x-button label="Close" />
                                                            </form>
                                                        </div>
                                                    </div>
                                                    <form method="dialog" class="modal-backdrop">
                                                        <button>close</button>
                                                    </form>
                                                </dialog>
                                            @endif
                                        @endforeach
                                    @endif

                                    <!-- Add new slot button (visible on hover) -->
                                    <div class="absolute inset-0 flex items-center justify-center transition-opacity opacity-0 group-hover:opacity-100 hover:bg-base-200/50">
                                        <x-button
                                            icon="o-plus"
                                            wire:click="createSlot('{{ $day }}', '{{ $timeSlot }}')"
                                            class="btn-xs btn-ghost"
                                            tooltip="Add class"
                                        />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div class="p-4 mt-6 rounded bg-base-100">
            <h4 class="mb-2 font-semibold">Legend:</h4>
            <div class="flex flex-wrap gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 border rounded bg-primary/20 border-primary"></div>
                    <span>Scheduled Class</span>
                </div>
                @if($stats['conflicts'] > 0)
                    <div class="flex items-center gap-2">
                        <x-icon name="o-exclamation-triangle" class="w-4 h-4 text-error" />
                        <span class="text-error">Time Conflict</span>
                    </div>
                @endif
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 rounded bg-base-200"></div>
                    <span>Weekend</span>
                </div>
            </div>
        </div>
    </x-card>

    <!-- Empty State -->
    @if($stats['total_slots'] == 0)
        <x-card class="mt-6">
            <div class="py-8 text-center">
                <x-icon name="o-calendar" class="w-16 h-16 mx-auto mb-4 text-gray-400" />
                <h3 class="text-lg font-semibold text-gray-600">No timetable slots found</h3>
                <p class="mb-4 text-gray-500">This teacher doesn't have any scheduled classes yet.</p>
                <x-button
                    label="Add First Class"
                    icon="o-plus"
                    wire:click="createSlot('Monday', '09:00')"
                    class="btn-primary"
                />
            </div>
        </x-card>
    @endif
</div>

<!-- Add hover effect for time slots -->
<style>
    .grid > div:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }

    .timetable-slot {
        transition: all 0.2s ease;
    }

    .timetable-slot:hover {
        transform: scale(1.02);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
</style>
