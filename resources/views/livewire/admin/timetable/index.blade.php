<?php

use App\Models\TimetableSlot;
use App\Models\Subject;
use App\Models\TeacherProfile;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Timetable Management')] class extends Component {
    use Toast;

    public string $selectedWeek = '';
    public string $selectedCurriculum = '';
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
        $query = TimetableSlot::with(['subject.curriculum', 'teacherProfile.user'])
            ->whereBetween('start_time', [
                now()->setDateFrom($this->selectedWeek)->startOfWeek(),
                now()->setDateFrom($this->selectedWeek)->endOfWeek()
            ]);

        if ($this->selectedCurriculum) {
            $query->whereHas('subject', function($q) {
                $q->where('curriculum_id', $this->selectedCurriculum);
            });
        }

        return $query->get();
    }

    public function getCurriculaProperty(): Collection
    {
        return \App\Models\Curriculum::orderBy('name')->get();
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

    public function deleteSlot($slotId): void
    {
        $slot = TimetableSlot::find($slotId);

        if ($slot) {
            $slot->delete();
            $this->success('Timetable slot deleted successfully.');
        } else {
            $this->error('Timetable slot not found.');
        }
    }

    public function getWeekDisplayProperty(): string
    {
        $start = now()->setDateFrom($this->selectedWeek)->startOfWeek();
        $end = $start->copy()->endOfWeek();

        return $start->format('M d') . ' - ' . $end->format('M d, Y');
    }

    public function with(): array
    {
        return [];
    }
};?>
<div>
    <!-- Page header -->
    <x-header title="Timetable Management" separator>
        <x-slot:middle>
            <div class="flex flex-col space-y-3 lg:flex-row lg:items-center lg:space-y-0 lg:space-x-4">
                <!-- Week Navigation -->
                <div class="flex items-center justify-center space-x-2 lg:justify-start">
                    <x-button
                        icon="o-chevron-left"
                        wire:click="previousWeek"
                        class="btn-sm btn-ghost"
                    />
                    <span class="text-sm font-semibold text-gray-700 lg:text-base">{{ $this->weekDisplay }}</span>
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

                <!-- Curriculum Filter -->
                <div class="flex-1 max-w-xs min-w-0">
                    <select
                        wire:model.live="selectedCurriculum"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Curricula</option>
                        @foreach($this->curricula as $curriculum)
                            <option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Add Slot"
                icon="o-plus"
                link="{{ route('admin.timetable.create') }}"
                color="primary"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Mobile Day Selector (visible only on mobile) -->
    <div class="block mb-4 lg:hidden">
        <x-card>
            <div class="p-4">
                <label class="block mb-2 text-sm font-medium text-gray-700">Select Day</label>
                <select wire:model.live="selectedDay" class="w-full select select-bordered">
                    @foreach($days as $day)
                        <option value="{{ $day }}">{{ $day }}</option>
                    @endforeach
                </select>
            </div>
        </x-card>
    </div>

    <!-- Mobile Daily View (hidden on desktop) -->
    <div class="block space-y-4 lg:hidden">
        @foreach($timeSlots as $time => $timeDisplay)
            @php
                $slot = $this->getSlotForDayAndTime($selectedDay ?? $days[0], $time);
            @endphp
            <x-card>
                <div class="p-4">
                    <!-- Time Header -->
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-lg font-semibold text-gray-900">{{ $time }}</h3>
                        @if(!$slot)
                            <a href="{{ route('admin.timetable.create', ['day' => $selectedDay ?? $days[0], 'time' => $time]) }}"
                               class="p-2 transition-colors bg-blue-100 rounded-lg hover:bg-blue-200">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                            </a>
                        @endif
                    </div>

                    @if($slot)
                        <!-- Slot Content -->
                        <div class="p-4 border border-blue-200 rounded-lg bg-blue-50">
                            <!-- Subject Info -->
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-lg font-semibold text-blue-900 truncate">
                                        {{ $slot->subject->name ?? 'N/A' }}
                                    </h4>
                                    @if($slot->subject->code)
                                        <p class="mt-1 text-sm text-blue-700">{{ $slot->subject->code }}</p>
                                    @endif
                                </div>
                                <!-- Actions -->
                                <div class="flex gap-2 ml-3">
                                    <a href="{{ route('admin.timetable.edit', $slot->id) }}"
                                       class="p-2 transition-colors bg-white rounded-lg shadow-sm hover:bg-gray-100">
                                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </a>
                                    <button wire:click="deleteSlot({{ $slot->id }})"
                                            wire:confirm="Are you sure you want to delete this timetable slot?"
                                            class="p-2 transition-colors bg-white rounded-lg shadow-sm hover:bg-red-100">
                                        <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Teacher and Time -->
                            <div class="grid grid-cols-1 gap-3 mb-3 sm:grid-cols-2">
                                <div>
                                    <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Teacher</label>
                                    <p class="text-sm text-gray-900">{{ $slot->teacherProfile->user->name ?? 'No Teacher' }}</p>
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Time</label>
                                    <p class="text-sm text-gray-900">
                                        {{ $slot->start_time->format('H:i') }} - {{ $slot->end_time->format('H:i') }}
                                    </p>
                                </div>
                            </div>

                            <!-- Curriculum Badge -->
                            @if($slot->subject && $slot->subject->curriculum)
                                <div class="flex justify-start">
                                    <span class="inline-flex items-center px-3 py-1 text-xs font-medium text-white bg-blue-800 rounded-full">
                                        {{ $slot->subject->curriculum->name }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    @else
                        <!-- Empty Slot -->
                        <div class="p-8 text-center border-2 border-gray-300 border-dashed rounded-lg">
                            <p class="text-sm text-gray-500">No class scheduled</p>
                            <p class="mt-1 text-xs text-gray-400">Tap the + button to add a class</p>
                        </div>
                    @endif
                </div>
            </x-card>
        @endforeach
    </div>

    <!-- Desktop Grid View (hidden on mobile) -->
    <div class="hidden overflow-hidden bg-white rounded-lg shadow lg:block">
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
                                        <div class="relative h-full p-3 transition-colors bg-blue-100 border border-blue-200 rounded-lg group hover:bg-blue-200">
                                            <!-- Subject and Teacher Info -->
                                            <div class="text-xs font-semibold text-blue-800 truncate">
                                                {{ $slot->subject->name ?? 'N/A' }}
                                            </div>
                                            <div class="text-xs text-blue-600 truncate">
                                                {{ $slot->subject->code ?? '' }}
                                            </div>
                                            <div class="mt-1 text-xs text-blue-600 truncate">
                                                {{ $slot->teacherProfile->user->name ?? 'No Teacher' }}
                                            </div>
                                            <div class="text-xs text-blue-500">
                                                {{ $slot->start_time->format('H:i') }} - {{ $slot->end_time->format('H:i') }}
                                            </div>

                                            <!-- Action Buttons (show on hover) -->
                                            <div class="absolute transition-opacity opacity-0 top-1 right-1 group-hover:opacity-100">
                                                <div class="flex space-x-1">
                                                    <a href="{{ route('admin.timetable.edit', $slot->id) }}"
                                                       class="p-1 bg-white rounded-full shadow-sm hover:bg-gray-100">
                                                        <svg class="w-3 h-3 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </a>
                                                    <button wire:click="deleteSlot({{ $slot->id }})"
                                                            wire:confirm="Are you sure you want to delete this timetable slot?"
                                                            class="p-1 bg-white rounded-full shadow-sm hover:bg-red-100">
                                                        <svg class="w-3 h-3 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Curriculum Badge -->
                                            @if($slot->subject && $slot->subject->curriculum)
                                                <div class="absolute bottom-1 left-1">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-800 text-white">
                                                        {{ Str::limit($slot->subject->curriculum->name, 10) }}
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <!-- Empty slot - click to add -->
                                        <a href="{{ route('admin.timetable.create', ['day' => $day, 'time' => $time]) }}"
                                           class="flex items-center justify-center w-full h-full transition-colors border-2 border-gray-300 border-dashed rounded-lg hover:border-blue-400 hover:bg-blue-50 group">
                                            <svg class="w-6 h-6 text-gray-400 group-hover:text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                            </svg>
                                        </a>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-2 gap-4 mt-6 lg:grid-cols-4">
        <x-card>
            <div class="flex items-center p-4">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4"></path>
                        </svg>
                    </div>
                </div>
                <div class="flex-1 min-w-0 ml-3">
                    <p class="text-sm font-medium text-gray-900 truncate">Total Slots</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->timetableData->count() }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center p-4">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                    </div>
                </div>
                <div class="flex-1 min-w-0 ml-3">
                    <p class="text-sm font-medium text-gray-900 truncate">Subjects</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->timetableData->pluck('subject_id')->unique()->count() }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center p-4">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-purple-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                    </div>
                </div>
                <div class="flex-1 min-w-0 ml-3">
                    <p class="text-sm font-medium text-gray-900 truncate">Teachers</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->timetableData->pluck('teacher_profile_id')->unique()->count() }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center p-4">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-orange-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                    </div>
                </div>
                <div class="flex-1 min-w-0 ml-3">
                    <p class="text-sm font-medium text-gray-900 truncate">Curricula</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->timetableData->pluck('subject.curriculum_id')->unique()->count() }}</p>
                </div>
            </div>
        </x-card>
    </div>
</div>
