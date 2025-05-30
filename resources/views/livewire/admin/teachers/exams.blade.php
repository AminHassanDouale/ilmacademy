<?php

use App\Models\TeacherProfile;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Teacher Exams')] class extends Component {
    use WithPagination;
    use Toast;

    // Teacher to display exams for
    public TeacherProfile $teacher;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $examType = '';

    #[Url]
    public string $dateRange = '';

    #[Url]
    public string $academicYear = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public array $sortBy = ['column' => 'exam_date', 'direction' => 'desc'];

    #[Url]
    public bool $showFilters = false;

    // Component initialization - parameter name should match route parameter
    public function mount(TeacherProfile $teacherProfile): void
    {
        $this->teacher = $teacherProfile;

        // Log access to exams page
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'access',
            'description' => "Viewed exams for teacher: " . ($teacherProfile->user ? $teacherProfile->user->name : 'Unknown'),
            'loggable_type' => TeacherProfile::class,
            'loggable_id' => $teacherProfile->id,
            'ip_address' => request()->ip(),
        ]);
    }

    // Sort data
    public function sortBy(string $column): void
    {
        if ($this->sortBy['column'] === $column) {
            $this->sortBy['direction'] = $this->sortBy['direction'] === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy['column'] = $column;
            $this->sortBy['direction'] = 'asc';
        }
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->examType = '';
        $this->dateRange = '';
        $this->academicYear = '';
        $this->resetPage();
    }

    // Go back to teacher profile
    public function backToProfile(): void
    {
        redirect()->route('admin.teachers.show', $this->teacher);
    }

    // Get filtered and paginated exams
    public function exams(): LengthAwarePaginator
    {
        // Check if the teacher has exams relationship
        if (!method_exists($this->teacher, 'exams')) {
            // If no exams relationship, return empty paginator
            return new LengthAwarePaginator(
                collect([]),
                0,
                $this->perPage,
                request()->get('page', 1),
                ['path' => request()->url(), 'pageName' => 'page']
            );
        }

        return $this->teacher->exams()
            ->with(['subject', 'academicYear']) // Eager load relationships
            ->when($this->search, function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->where('title', 'like', '%' . $this->search . '%')
                      ->orWhereHas('subject', function (Builder $subjectQuery) {
                          $subjectQuery->where('name', 'like', '%' . $this->search . '%');
                      });
                });
            })
            ->when($this->examType, function (Builder $query) {
                $query->where('type', $this->examType);
            })
            ->when($this->academicYear, function (Builder $query) {
                $query->whereHas('academicYear', function (Builder $yearQuery) {
                    $yearQuery->where('year', $this->academicYear);
                });
            })
            ->when($this->dateRange, function (Builder $query) {
                // Parse date range if present
                if ($this->dateRange === 'today') {
                    $query->whereDate('exam_date', now()->toDateString());
                } elseif ($this->dateRange === 'upcoming') {
                    $query->where('exam_date', '>', now());
                } elseif ($this->dateRange === 'past') {
                    $query->where('exam_date', '<', now());
                } elseif ($this->dateRange === 'week') {
                    $query->whereBetween('exam_date', [
                        now()->startOfWeek(),
                        now()->endOfWeek()
                    ]);
                } elseif ($this->dateRange === 'month') {
                    $query->whereBetween('exam_date', [
                        now()->startOfMonth(),
                        now()->endOfMonth()
                    ]);
                }
            })
            ->when($this->sortBy['column'], function (Builder $query) {
                // Apply sort if column exists
                $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
            }, function (Builder $query) {
                // Default sorting
                $query->latest('exam_date');
            })
            ->paginate($this->perPage);
    }

    // Get exam statistics
    public function getExamStats()
    {
        // Check if the teacher has exams relationship
        if (!method_exists($this->teacher, 'exams')) {
            return [
                'total' => 0,
                'upcoming' => 0,
                'past' => 0,
                'this_month' => 0,
                'exam_types' => []
            ];
        }

        $total = $this->teacher->exams()->count();
        $upcoming = $this->teacher->exams()->where('exam_date', '>', now())->count();
        $past = $this->teacher->exams()->where('exam_date', '<=', now())->count();
        $thisMonth = $this->teacher->exams()
            ->whereBetween('exam_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        // Get exam types distribution
        $examTypes = $this->teacher->exams()
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return [
            'total' => $total,
            'upcoming' => $upcoming,
            'past' => $past,
            'this_month' => $thisMonth,
            'exam_types' => $examTypes
        ];
    }

    // Get available exam types
    public function getExamTypes()
    {
        if (!method_exists($this->teacher, 'exams')) {
            return [];
        }

        return $this->teacher->exams()
            ->distinct()
            ->pluck('type')
            ->filter()
            ->map(function ($type) {
                return [
                    'label' => ucfirst(str_replace('_', ' ', $type)),
                    'value' => $type
                ];
            })
            ->values()
            ->toArray();
    }

    // Get available academic years
    public function getAcademicYears()
    {
        if (!method_exists($this->teacher, 'exams')) {
            return [];
        }

        return $this->teacher->exams()
            ->with('academicYear')
            ->get()
            ->pluck('academicYear')
            ->filter()
            ->unique('id')
            ->map(function ($year) {
                return [
                    'label' => $year->year,
                    'value' => $year->year
                ];
            })
            ->values()
            ->toArray();
    }

    // View exam results
    public function viewExamResults(int $examId): void
    {
        redirect()->route('admin.exams.results', $examId);
    }

    public function with(): array
    {
        return [
            'exams' => $this->exams(),
            'examStats' => $this->getExamStats(),
            'examTypes' => $this->getExamTypes(),
            'academicYears' => $this->getAcademicYears()
        ];
    }
};?>

<div>
    <x-header title="Exams for {{ $teacher->user ? $teacher->user->name : 'Unknown Teacher' }}" separator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <x-slot:actions>
            <div class="flex gap-2">
                <x-button
                    label="Filters"
                    icon="o-funnel"
                    :badge="count(array_filter([$examType, $dateRange, $academicYear]))"
                    badge-classes="font-mono"
                    @click="$wire.showFilters = true"
                    class="bg-base-300"
                    responsive />

                <x-button
                    label="Back to Profile"
                    icon="o-arrow-left"
                    wire:click="backToProfile"
                    class="btn-ghost"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-4">
        <x-card class="bg-base-100">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold">{{ $examStats['total'] }}</h3>
                    <p class="text-sm opacity-70">Total Exams</p>
                </div>
                <div class="p-3 rounded-full bg-primary/10">
                    <x-icon name="o-document-text" class="w-6 h-6 text-primary" />
                </div>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold">{{ $examStats['upcoming'] }}</h3>
                    <p class="text-sm opacity-70">Upcoming Exams</p>
                </div>
                <div class="p-3 rounded-full bg-info/10">
                    <x-icon name="o-calendar-days" class="w-6 h-6 text-info" />
                </div>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold">{{ $examStats['past'] }}</h3>
                    <p class="text-sm opacity-70">Past Exams</p>
                </div>
                <div class="p-3 rounded-full bg-success/10">
                    <x-icon name="o-check-circle" class="w-6 h-6 text-success" />
                </div>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold">{{ $examStats['this_month'] }}</h3>
                    <p class="text-sm opacity-70">This Month</p>
                </div>
                <div class="p-3 rounded-full bg-warning/10">
                    <x-icon name="o-calendar" class="w-6 h-6 text-warning" />
                </div>
            </div>
        </x-card>
    </div>

    <!-- Exams Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('title')">
                            <div class="flex items-center">
                                Title
                                @if ($sortBy['column'] === 'title')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Subject</th>
                        <th class="cursor-pointer" wire:click="sortBy('exam_date')">
                            <div class="flex items-center">
                                Exam Date
                                @if ($sortBy['column'] === 'exam_date')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('type')">
                            <div class="flex items-center">
                                Type
                                @if ($sortBy['column'] === 'type')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Academic Year</th>
                        <th>Results</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($exams as $exam)
                        <tr class="hover">
                            <td>
                                <div class="font-semibold">{{ $exam->title }}</div>
                                <div class="text-xs text-gray-500">
                                    ID: {{ $exam->id }}
                                </div>
                            </td>
                            <td>
                                @if($exam->subject)
                                    <x-badge label="{{ $exam->subject->name }}" color="info" />
                                @else
                                    <span class="text-sm italic text-gray-400">No subject</span>
                                @endif
                            </td>
                            <td>
                                @if($exam->exam_date)
                                    <div>{{ $exam->exam_date->format('d/m/Y') }}</div>
                                    <div class="text-xs">{{ $exam->exam_date->format('l') }}</div>
                                    <div class="mt-1">
                                        @if($exam->exam_date->isFuture())
                                            <x-badge label="Upcoming" color="info" />
                                        @elseif($exam->exam_date->isToday())
                                            <x-badge label="Today" color="warning" />
                                        @else
                                            <x-badge label="Past" color="secondary" />
                                        @endif
                                    </div>
                                @else
                                    <div class="text-sm italic text-gray-400">Not scheduled</div>
                                @endif
                            </td>
                            <td>
                                @if($exam->type)
                                    <x-badge
                                        label="{{ ucfirst(str_replace('_', ' ', $exam->type)) }}"
                                        color="{{ match($exam->type) {
                                            'quiz' => 'primary',
                                            'midterm' => 'warning',
                                            'final' => 'error',
                                            'assignment' => 'success',
                                            default => 'secondary'
                                        } }}"
                                    />
                                @else
                                    <span class="text-sm italic text-gray-400">No type</span>
                                @endif
                            </td>
                            <td>
                                @if($exam->academicYear)
                                    <span class="font-medium">{{ $exam->academicYear->year }}</span>
                                @else
                                    <span class="text-sm italic text-gray-400">No year</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $resultsCount = $exam->examResults()->count();
                                    $averageScore = $exam->examResults()->avg('score');
                                @endphp
                                <div class="text-sm">
                                    <div>{{ $resultsCount }} result{{ $resultsCount !== 1 ? 's' : '' }}</div>
                                    @if($averageScore)
                                        <div class="text-xs text-gray-500">
                                            Avg: {{ number_format($averageScore, 1) }}%
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    @if($exam->examResults()->count() > 0)
                                        <x-button
                                            icon="o-chart-bar"
                                            wire:click="viewExamResults({{ $exam->id }})"
                                            color="success"
                                            size="sm"
                                            tooltip="View Results"
                                        />
                                    @endif

                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-document-text" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No exams found</h3>
                                    <p class="text-gray-500">Try modifying your search criteria</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $exams->links() }}
        </div>
    </x-card>

    <!-- Filters Drawer -->
    <x-drawer wire:model="showFilters" title="Exam Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by title or subject"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by exam type"
                    placeholder="All types"
                    :options="$examTypes"
                    wire:model.live="examType"
                    option-label="label"
                    option-value="value"
                    empty-message="No exam types found"
                />
            </div>

            <div>
                <x-select
                    label="Filter by date"
                    placeholder="All dates"
                    :options="[
                        ['label' => 'Today', 'value' => 'today'],
                        ['label' => 'This Week', 'value' => 'week'],
                        ['label' => 'This Month', 'value' => 'month'],
                        ['label' => 'Upcoming', 'value' => 'upcoming'],
                        ['label' => 'Past', 'value' => 'past']
                    ]"
                    wire:model.live="dateRange"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Filter by academic year"
                    placeholder="All years"
                    :options="$academicYears"
                    wire:model.live="academicYear"
                    option-label="label"
                    option-value="value"
                    empty-message="No academic years found"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[10, 25, 50, 100]"
                    wire:model.live="perPage"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Reset" icon="o-x-mark" wire:click="resetFilters" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" />
        </x-slot:actions>
    </x-drawer>
</div>
