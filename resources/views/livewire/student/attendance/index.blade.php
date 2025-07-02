<?php
// resources/views/livewire/student/attendance/history.blade.php

use App\Models\Student;
use App\Models\Attendance;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Attendance History')] class extends Component {
    use Toast, WithPagination;

    public string $selectedSubject = '';
    public string $selectedMonth = '';
    public string $selectedYear = '';
    public string $statusFilter = '';

    public function mount(): void
    {
        $this->selectedMonth = now()->month;
        $this->selectedYear = now()->year;
    }

    public function getStudentProperty(): ?Student
    {
        return auth()->user()->student;
    }

    public function getAttendanceRecordsProperty()
    {
        $student = $this->student;

        if (!$student) {
            return Attendance::query()->where('id', 0); // Return empty query
        }

        $query = Attendance::with(['subject', 'student.user'])
            ->where('student_id', $student->id);

        if ($this->selectedSubject) {
            $query->where('subject_id', $this->selectedSubject);
        }

        if ($this->selectedMonth && $this->selectedYear) {
            $query->whereMonth('date', $this->selectedMonth)
                  ->whereYear('date', $this->selectedYear);
        } elseif ($this->selectedYear) {
            $query->whereYear('date', $this->selectedYear);
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        return $query->orderBy('date', 'desc')->paginate(20);
    }

    public function getSubjectsProperty(): Collection
    {
        $student = $this->student;

        if (!$student || !$student->curriculum) {
            return collect();
        }

        return Subject::where('curriculum_id', $student->curriculum_id)
            ->orderBy('name')
            ->get();
    }

    public function getAttendanceStatsProperty(): array
    {
        $student = $this->student;

        if (!$student) {
            return [
                'total_classes' => 0,
                'present_count' => 0,
                'absent_count' => 0,
                'late_count' => 0,
                'attendance_rate' => 0,
                'current_month_rate' => 0
            ];
        }

        $allAttendance = Attendance::where('student_id', $student->id)->get();
        $currentMonthAttendance = $allAttendance->where('date', '>=', now()->startOfMonth());

        $presentCount = $allAttendance->where('status', 'present')->count();
        $absentCount = $allAttendance->where('status', 'absent')->count();
        $lateCount = $allAttendance->where('status', 'late')->count();
        $totalClasses = $allAttendance->count();

        $currentMonthPresent = $currentMonthAttendance->where('status', 'present')->count();
        $currentMonthTotal = $currentMonthAttendance->count();

        return [
            'total_classes' => $totalClasses,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'late_count' => $lateCount,
            'attendance_rate' => $totalClasses > 0 ? ($presentCount / $totalClasses) * 100 : 0,
            'current_month_rate' => $currentMonthTotal > 0 ? ($currentMonthPresent / $currentMonthTotal) * 100 : 0
        ];
    }

    public function getAvailableYearsProperty(): Collection
    {
        $student = $this->student;

        if (!$student) {
            return collect([now()->year]);
        }

        return Attendance::where('student_id', $student->id)
            ->selectRaw('YEAR(date) as year')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year')
            ->when(
                fn($years) => $years->isEmpty(),
                fn($years) => collect([now()->year])
            );
    }

    public function getStatusColor(string $status): string
    {
        return match($status) {
            'present' => 'text-green-800 bg-green-100',
            'absent' => 'text-red-800 bg-red-100',
            'late' => 'text-yellow-800 bg-yellow-100',
            'excused' => 'text-blue-800 bg-blue-100',
            default => 'text-gray-800 bg-gray-100'
        };
    }

    public function getStatusIcon(string $status): string
    {
        return match($status) {
            'present' => 'o-check-circle',
            'absent' => 'o-x-circle',
            'late' => 'o-clock',
            'excused' => 'o-document-text',
            default => 'o-question-mark-circle'
        };
    }

    public function resetFilters(): void
    {
        $this->selectedSubject = '';
        $this->selectedMonth = now()->month;
        $this->selectedYear = now()->year;
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function updatedSelectedSubject(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedMonth(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedYear(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function getMonthlyAttendanceChart(): array
    {
        $student = $this->student;

        if (!$student) {
            return [];
        }

        $monthlyData = [];
        for ($i = 1; $i <= 12; $i++) {
            $attendance = Attendance::where('student_id', $student->id)
                ->whereYear('date', $this->selectedYear)
                ->whereMonth('date', $i)
                ->get();

            $total = $attendance->count();
            $present = $attendance->where('status', 'present')->count();

            $monthlyData[] = [
                'month' => date('M', mktime(0, 0, 0, $i, 1)),
                'rate' => $total > 0 ? ($present / $total) * 100 : 0,
                'total' => $total,
                'present' => $present
            ];
        }

        return $monthlyData;
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Attendance History" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <!-- Subject Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="selectedSubject"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Subjects</option>
                        @foreach($this->subjects as $subject)
                            <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Month Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="selectedMonth"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Months</option>
                        @for($i = 1; $i <= 12; $i++)
                            <option value="{{ $i }}">{{ date('F', mktime(0, 0, 0, $i, 1)) }}</option>
                        @endfor
                    </select>
                </div>

                <!-- Year Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="selectedYear"
                        class="w-full select select-bordered select-sm"
                    >
                        @foreach($this->availableYears as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Status Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="statusFilter"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Status</option>
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                        <option value="late">Late</option>
                        <option value="excused">Excused</option>
                    </select>
                </div>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Reset Filters"
                icon="o-x-mark"
                wire:click="resetFilters"
                class="btn-ghost btn-sm"
            />
            <x-button
                label="Download Report"
                icon="o-arrow-down-tray"
                class="btn-outline"
            />
        </x-slot:actions>
    </x-header>

    <!-- Attendance Statistics -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-4">
        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Total Classes</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->attendanceStats['total_classes'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Present</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->attendanceStats['present_count'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-red-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Absent</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->attendanceStats['absent_count'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-purple-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Attendance Rate</p>
                    <p class="text-lg font-semibold text-gray-900">{{ number_format($this->attendanceStats['attendance_rate'], 1) }}%</p>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Monthly Progress Chart -->
    <div class="mb-6 bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Monthly Attendance Rate ({{ $selectedYear }})</h3>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-6 gap-4 md:grid-cols-12">
                @foreach($this->getMonthlyAttendanceChart() as $month)
                    <div class="text-center">
                        <div class="mb-2 text-xs font-medium text-gray-700">{{ $month['month'] }}</div>
                        <div class="mx-auto w-16 h-16 rounded-full flex items-center justify-center text-xs font-semibold
                            {{ $month['rate'] >= 90 ? 'bg-green-100 text-green-800' :
                               ($month['rate'] >= 75 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                            {{ number_format($month['rate'], 0) }}%
                        </div>
                        <div class="mt-1 text-xs text-gray-500">{{ $month['present'] }}/{{ $month['total'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Attendance Records Table -->
    <div class="overflow-hidden bg-white rounded-lg shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Date
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Subject
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Status
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Time
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Notes
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($this->attendanceRecords as $record)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $record->date->format('M d, Y') }}
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ $record->date->format('l') }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $record->subject->name ?? 'N/A' }}
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ $record->subject->code ?? '' }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($record->status) }}">
                                    <x-icon name="{{ $this->getStatusIcon($record->status) }}" class="w-3 h-3 mr-1" />
                                    {{ ucfirst($record->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 whitespace-nowrap">
                                @if($record->check_in_time)
                                    {{ $record->check_in_time->format('H:i') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $record->notes ?? '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <div class="text-center">
                                    <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900">No attendance records found</h3>
                                    <p class="mt-1 text-sm text-gray-500">
                                        @if($selectedSubject || $selectedMonth || $statusFilter)
                                            Try adjusting your filter criteria.
                                        @else
                                            Your attendance records will appear here once classes begin.
                                        @endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($this->attendanceRecords->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $this->attendanceRecords->links() }}
            </div>
        @endif
    </div>
</div>
