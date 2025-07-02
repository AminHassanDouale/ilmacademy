<?php
// resources/views/livewire/teacher/timetable/index.blade.php

use App\Models\TimetableSlot;
use App\Models\Subject;
use App\Models\TeacherProfile;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('My Timetable')] class extends Component {
    use Toast;

    public string $selectedWeek = '';
    public array $timeSlots = [];
    public array $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function mount(): void
    {
        // Set current week as default
        $this->selectedWeek = now()->startOfWeek()->format('Y-m-d');

        // Define standard time slots
        $this->timeSlots = [
            '08:00' => '08:00 - 09:00',
            '09:00' => '09:00 - 10:00',
            '10:00' => '10:00 - 11:00',
            '11:00' => '11:00 - 12:00',
            '12:00' => '12:00 - 13:00',
            '13:00' => '13:00 - 14:00',
            '14:00' => '14:00 - 15:00',
            '15:00' => '15:00 - 16:00',
            '16:00' => '16:00 - 17:00',
            '17:00' => '17:00 - 18:00',
        ];
    }

    public function getTimetableDataProperty(): Collection
    {
        // Get current teacher's profile
        $teacherProfile = auth()->user()->teacherProfile;

        if (!$teacherProfile) {
            return collect();
        }

        return TimetableSlot::with(['subject.curriculum', 'teacherProfile.user'])
            ->where('teacher_profile_id', $teacherProfile->id)
            ->whereBetween('start_time', [
                now()->setDateFrom($this->selectedWeek)->startOfWeek(),
                now()->setDateFrom($this->selectedWeek)->endOfWeek()
            ])
            ->get();
    }

    public function getSlotForDayAndTime(string $day, string $time): ?TimetableSlot
    {
        $dayIndex = array_search($day, $this->days);
        $targetDate = now()->setDateFrom($this->selectedWeek)->startOfWeek()->addDays($dayIndex);

        return $this->timetableData->first(function ($slot) use ($targetDate, $time) {
            return $slot->start_time->format('Y-m-d') === $targetDate->format('Y-m-d') &&
                   $slot->start_time->format('H:i') === $time;
        });
    }

    public function previousWeek(): void
    {
        $this->selectedWeek = now()->setDateFrom($this->selectedWeek)->subWeek()->format('Y-m-d');
    }

    public function nextWeek(): void
    {
        $this->selectedWeek = now()->setDateFrom($this->selectedWeek)->addWeek()->format('Y-m-d');
    }

    public function currentWeek(): void
    {
        $this->selectedWeek = now()->startOfWeek()->format('Y-m-d');
    }

    public function getWeekDisplayProperty(): string
    {
        $start = now()->setDateFrom($this->selectedWeek)->startOfWeek();
        $end = $start->copy()->endOfWeek();

        return $start->format('M d') . ' - ' . $end->format('M d, Y');
    }

    public function getTotalHoursProperty(): int
    {
        return $this->timetableData->count();
    }

    public function getUpcomingClassesProperty(): Collection
    {
        $teacherProfile = auth()->user()->teacherProfile;

        if (!$teacherProfile) {
            return collect();
        }

        return TimetableSlot::with(['subject.curriculum'])
            ->where('teacher_profile_id', $teacherProfile->id)
            ->where('start_time', '>=', now())
            ->orderBy('start_time')
            ->limit(5)
            ->get();
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="My Timetable" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <!-- Week Navigation -->
                <div class="flex items-center space-x-2">
                    <x-button
                        icon="o-chevron-left"
                        wire:click="previousWeek"
                        class="btn-sm btn-ghost"
                    />
                    <span class="font-semibold text-gray-700">{{ $this->weekDisplay }}</span>
                    <x-button
                        icon="o-chevron-right"
                        wire:click="nextWeek"
                        class="btn-sm btn-ghost"
                    />
                    <x-button
                        label="Today"
                        wire:click="currentWeek"
                        class="btn-sm btn-ghost"
                    />
                </div>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Export Schedule"
                icon="o-arrow-down-tray"
                class="btn-outline"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <!-- Timetable Grid -->
        <div class="lg:col-span-3">
            <div class="overflow-hidden bg-white rounded-lg shadow">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="w-24 px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    Time
                                </th>
                                @foreach($days as $day)
                                    <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                        {{ $day }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($timeSlots as $time => $timeDisplay)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900 whitespace-nowrap bg-gray-50">
                                        {{ $time }}
                                    </td>
                                    @foreach($days as $day)
                                        @php
                                            $slot = $this->getSlotForDayAndTime($day, $time);
                                        @endphp
                                        <td class="relative h-20 px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                            @if($slot)
                                                <div class="relative h-full p-3 transition-colors bg-green-100 border border-green-200 rounded-lg">
                                                    <!-- Subject Info -->
                                                    <div class="text-xs font-semibold text-green-800 truncate">
                                                        {{ $slot->subject->name ?? 'N/A' }}
                                                    </div>
                                                    <div class="text-xs text-green-600 truncate">
                                                        {{ $slot->subject->code ?? '' }}
                                                    </div>
                                                    <div class="text-xs text-green-500">
                                                        {{ $slot->start_time->format('H:i') }} - {{ $slot->end_time->format('H:i') }}
                                                    </div>

                                                    <!-- Curriculum Badge -->
                                                    @if($slot->subject && $slot->subject->curriculum)
                                                        <div class="absolute bottom-1 left-1">
                                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-green-800 text-white">
                                                                {{ Str::limit($slot->subject->curriculum->name, 8) }}
                                                            </span>
                                                        </div>
                                                    @endif
                                                </div>
                                            @else
                                                <!-- Empty slot -->
                                                <div class="flex items-center justify-center w-full h-full border-2 border-gray-200 border-dashed rounded-lg">
                                                    <span class="text-xs text-gray-400">Free</span>
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Quick Stats -->
            <x-card title="This Week" class="bg-gradient-to-r from-blue-50 to-indigo-50">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Total Classes</span>
                        <span class="text-lg font-semibold text-blue-600">{{ $this->totalHours }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Subjects</span>
                        <span class="text-lg font-semibold text-blue-600">{{ $this->timetableData->pluck('subject_id')->unique()->count() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Teaching Hours</span>
                        <span class="text-lg font-semibold text-blue-600">{{ $this->totalHours }}h</span>
                    </div>
                </div>
            </x-card>

            <!-- Upcoming Classes -->
            <x-card title="Upcoming Classes">
                <div class="space-y-3">
                    @forelse($this->upcomingClasses as $class)
                        <div class="p-3 border border-gray-200 rounded-lg">
                            <div class="text-sm font-medium text-gray-900">
                                {{ $class->subject->name }}
                            </div>
                            <div class="text-xs text-gray-500">
                                {{ $class->start_time->format('M d, Y - H:i') }}
                            </div>
                            <div class="text-xs text-blue-600">
                                {{ $class->subject->curriculum->name ?? 'N/A' }}
                            </div>
                        </div>
                    @empty
                        <p class="py-4 text-sm text-center text-gray-500">No upcoming classes</p>
                    @endforelse
                </div>
            </x-card>

            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-2">
                    <x-button
                        label="View All Students"
                        icon="o-users"
                        link="{{ route('teacher.students.index') }}"
                        class="w-full btn-outline btn-sm"
                    />
                    <x-button
                        label="Class Reports"
                        icon="o-document-text"
                        class="w-full btn-outline btn-sm"
                    />
                    <x-button
                        label="Attendance"
                        icon="o-check-circle"
                        class="w-full btn-outline btn-sm"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
