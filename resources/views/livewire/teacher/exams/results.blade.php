<?php

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Exam Results')] class extends Component {
    use WithPagination;
    use Toast;

    // Exam model
    public Exam $exam;

    // Filter and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $perPage = 25;

    #[Url]
    public array $sortBy = ['column' => 'score', 'direction' => 'desc'];

    // Results statistics
    public $stats = [
        'total' => 0,
        'attended' => 0,
        'passed' => 0,
        'failed' => 0,
        'average' => 0,
        'highest' => 0,
        'lowest' => 0,
    ];

    // Score distribution
    public $distribution = [];

    public function mount(Exam $exam): void
    {
        $this->exam = $exam;

        // Load the exam with relationships
        $this->exam->load(['subject', 'teacherProfile.user']);

        // Check if current teacher is the owner of this exam
        $teacherProfile = Auth::user()->teacherProfile;
        $isOwner = $teacherProfile && $teacherProfile->id === $this->exam->teacher_profile_id;

        if (!$isOwner) {
            $this->error('You do not have permission to view these exam results.');
            redirect()->route('teacher.exams.index');
            return;
        }

        // Check if exam is completed and graded
        $now = Carbon::now();
        $examDate = Carbon::parse($this->exam->date);
        $isCompleted = $examDate->isBefore($now->startOfDay());

        if (!$isCompleted) {
            $this->error('This exam has not been completed yet.');
            redirect()->route('teacher.exams.show', $this->exam->id);
            return;
        }

        if (!$this->exam->is_graded) {
            $this->error('This exam has not been graded yet.');
            redirect()->route('teacher.exams.grade', $this->exam->id);
            return;
        }

        // Calculate result statistics
        $this->calculateStatistics();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher viewed exam results: ' . $this->exam->title,
            Exam::class,
            $this->exam->id,
            ['ip' => request()->ip()]
        );
    }

    // Calculate result statistics
    private function calculateStatistics(): void
    {
        try {
            // Get all results for this exam
            $results = $this->exam->results()->get();

            // Basic stats
            $this->stats['attended'] = $results->count();
            $this->stats['passed'] = $results->where('score', '>=', $this->exam->passing_mark)->count();
            $this->stats['failed'] = $results->where('score', '<', $this->exam->passing_mark)->count();

            // Total enrolled students
            if (method_exists($this->exam->subject, 'enrolledStudents')) {
                $this->stats['total'] = $this->exam->subject->enrolledStudents()->count();
            } else {
                $this->stats['total'] = $this->stats['attended'];
            }

            // Score stats
            if ($this->stats['attended'] > 0) {
                $this->stats['average'] = round($results->avg('score'));
                $this->stats['highest'] = $results->max('score');
                $this->stats['lowest'] = $results->min('score');
            }

            // Calculate score distribution
            $this->calculateDistribution($results);
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error calculating exam statistics: ' . $e->getMessage(),
                Exam::class,
                $this->exam->id,
                ['ip' => request()->ip()]
            );
        }
    }

    // Calculate score distribution
    private function calculateDistribution($results): void
    {
        // Define score ranges
        $ranges = [
            ['min' => 90, 'max' => 100, 'label' => '90-100', 'count' => 0, 'color' => 'success'],
            ['min' => 80, 'max' => 89, 'label' => '80-89', 'count' => 0, 'color' => 'success'],
            ['min' => 70, 'max' => 79, 'label' => '70-79', 'count' => 0, 'color' => 'success'],
            ['min' => $this->exam->passing_mark, 'max' => 69, 'label' => $this->exam->passing_mark . '-69', 'count' => 0, 'color' => 'success'],
            ['min' => 0, 'max' => $this->exam->passing_mark - 1, 'label' => '0-' . ($this->exam->passing_mark - 1), 'count' => 0, 'color' => 'error'],
        ];

        // Count scores in each range
        foreach ($results as $result) {
            $score = $result->score;
            $scorePercentage = ($score / $this->exam->total_marks) * 100;

            foreach ($ranges as &$range) {
                if ($scorePercentage >= $range['min'] && $scorePercentage <= $range['max']) {
                    $range['count']++;
                    break;
                }
            }
        }

        // Calculate percentages for each range
        $totalResults = $results->count();
        foreach ($ranges as &$range) {
            $range['percentage'] = $totalResults > 0 ? round(($range['count'] / $totalResults) * 100) : 0;
        }

        $this->distribution = $ranges;
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
        $this->status = '';
        $this->resetPage();
    }

    // Export results to CSV
    public function exportResults(): void
    {
        try {
            // Implement export functionality
            // This would typically generate a CSV file with all results

            $this->success('Results exported successfully.');
        } catch (\Exception $e) {
            $this->error('Failed to export results: ' . $e->getMessage());
        }
    }

    // Get exam results with filters
    public function results()
    {
        try {
            $query = $this->exam->results()
                ->with(['childProfile.user', 'childProfile.program'])
                ->when($this->search, function ($query) {
                    $query->whereHas('childProfile.user', function ($q) {
                        $q->where('name', 'like', '%' . $this->search . '%')
                            ->orWhere('email', 'like', '%' . $this->search . '%');
                    });
                })
                ->when($this->status, function ($query) {
                    if ($this->status === 'passed') {
                        $query->where('score', '>=', $this->exam->passing_mark);
                    } elseif ($this->status === 'failed') {
                        $query->where('score', '<', $this->exam->passing_mark);
                    }
                });

            // Get results
            $results = $query->orderBy($this->sortBy['column'], $this->sortBy['direction'])
                ->paginate($this->perPage);

            // Calculate percentage and status for each result
            foreach ($results as $result) {
                $result->percentage = round(($result->score / $this->exam->total_marks) * 100);
                $result->status = $result->score >= $this->exam->passing_mark ? 'passed' : 'failed';
            }

            return $results;
        } catch (\Exception $e) {
            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading exam results: ' . $e->getMessage(),
                Exam::class,
                $this->exam->id,
                ['ip' => request()->ip()]
            );

            return [];
        }
    }

    // Format date in d/m/Y format
    public function formatDate($date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    public function with(): array
    {
        return [
            'results' => $this->results(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Exam Results" separator progress-indicator>
        <x-slot:subtitle>
            {{ $exam->title }} | {{ $exam->subject->name ?? 'Unknown Subject' }} | {{ formatDate($exam->date) }}
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search students..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Export Results"
                icon="o-arrow-down-tray"
                wire:click="exportResults"
                class="btn-outline"
                responsive
            />
            <x-button
                label="Back to Exam"
                icon="o-arrow-left"
                href="{{ route('teacher.exams.show', $exam->id) }}"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Results statistics -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-users" class="w-8 h-8" />
            </div>
            <div class="stat-title">Students</div>
            <div class="stat-value">{{ $stats['attended'] }}/{{ $stats['total'] }}</div>
            <div class="stat-desc">{{ $stats['total'] > 0 ? round(($stats['attended'] / $stats['total']) * 100) : 0 }}% Attendance</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Passed</div>
            <div class="stat-value text-success">{{ $stats['passed'] }}</div>
            <div class="stat-desc">{{ $stats['attended'] > 0 ? round(($stats['passed'] / $stats['attended']) * 100) : 0 }}% Pass Rate</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-error">
                <x-icon name="o-x-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Failed</div>
            <div class="stat-value text-error">{{ $stats['failed'] }}</div>
            <div class="stat-desc">{{ $stats['attended'] > 0 ? round(($stats['failed'] / $stats['attended']) * 100) : 0 }}% Fail Rate</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-secondary">
                <x-icon name="o-calculator" class="w-8 h-8" />
            </div>
            <div class="stat-title">Average</div>
            <div class="stat-value text-secondary">{{ $stats['average'] }}</div>
            <div class="stat-desc">Range: {{ $stats['lowest'] }} - {{ $stats['highest'] }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-3">
        <!-- Score Distribution Chart -->
        <div class="md:col-span-2">
            <x-card title="Score Distribution">
                <div class="space-y-4">
                    @foreach ($distribution as $range)
                        <div>
                            <div class="flex justify-between mb-1 text-sm">
                                <span>{{ $range['label'] }}%</span>
                                <span>{{ $range['count'] }} students ({{ $range['percentage'] }}%)</span>
                            </div>
                            <div class="w-full h-4 rounded-full bg-base-300">
                                <div class="h-full rounded-full bg-{{ $range['color'] }}" style="width: {{ $range['percentage'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>

        <!-- Exam Summary -->
        <div>
            <x-card title="Exam Summary">
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span class="font-medium">Type:</span>
                        <span>{{ ucfirst($exam->type) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium">Total Marks:</span>
                        <span>{{ $exam->total_marks }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium">Passing Mark:</span>
                        <span>{{ $exam->passing_mark }} ({{ round(($exam->passing_mark / $exam->total_marks) * 100) }}%)</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium">Duration:</span>
                        <span>{{ $exam->duration }} minutes</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium">Location:</span>
                        <span>{{ $exam->is_online ? 'Online' : ($exam->location ?? 'Not specified') }}</span>
                    </div>
                </div>

                <div class="divider"></div>

                <div>
                    <h3 class="mb-2 font-medium">Result Filters</h3>
                    <div class="flex flex-wrap gap-2">
                        <x-button
                            label="All"
                            size="xs"
                            wire:click="$set('status', '')"
                            class="{{ $status === '' ? 'btn-primary' : 'btn-outline' }}"
                        />
                        <x-button
                            label="Passed"
                            size="xs"
                            wire:click="$set('status', 'passed')"
                            class="{{ $status === 'passed' ? 'btn-success' : 'btn-outline' }}"
                        />
                        <x-button
                            label="Failed"
                            size="xs"
                            wire:click="$set('status', 'failed')"
                            class="{{ $status === 'failed' ? 'btn-error' : 'btn-outline' }}"
                        />
                    </div>

                    <div class="mt-4">
                        <x-select
                            label="Results per page"
                            :options="[10, 25, 50, 100]"
                            wire:model.live="perPage"
                        />
                    </div>
                </div>
            </x-card>
        </div>
    </div>

    <!-- Results Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th class="cursor-pointer" wire:click="sortBy('score')">
                            <div class="flex items-center">
                                Score
                                @if ($sortBy['column'] === 'score')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Percentage</th>
                        <th>Status</th>
                        <th class="cursor-pointer" wire:click="sortBy('submitted_at')">
                            <div class="flex items-center">
                                Submitted
                                @if ($sortBy['column'] === 'submitted_at')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($results as $result)
                        <tr class="hover">
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar">
                                        <div class="w-10 h-10 mask mask-squircle">
                                            @if ($result->childProfile->photo)
                                                <img src="{{ asset('storage/' . $result->childProfile->photo) }}" alt="{{ $result->childProfile->user->name ?? 'Student' }}">
                                            @else
                                                <img src="{{ $result->childProfile->user->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Student&color=7F9CF5&background=EBF4FF' }}" alt="{{ $result->childProfile->user->name ?? 'Student' }}">
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <div class="font-bold">{{ $result->childProfile->user->name ?? 'Unknown Student' }}</div>
                                        <div class="text-sm opacity-50">{{ $result->childProfile->program->name ?? 'No Program' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="font-semibold {{ $result->status === 'passed' ? 'text-success' : 'text-error' }}">
                                    {{ $result->score }} / {{ $exam->total_marks }}
                                </span>
                            </td>
                            <td>
                                {{ $result->percentage }}%
                            </td>
                            <td>
                                @if ($result->status === 'passed')
                                    <x-badge label="Passed" color="success" />
                                @else
                                    <x-badge label="Failed" color="error" />
                                @endif
                            </td>
                            <td>
                                @if ($result->submitted_at)
                                    {{ \Carbon\Carbon::parse($result->submitted_at)->format('d/m/Y H:i') }}
                                @else
                                    <span class="text-gray-400">No timestamp</span>
                                @endif
                            </td>
                            <td>
                                @if ($result->comments)
                                    <div class="max-w-xs truncate">{{ $result->comments }}</div>
                                @else
                                    <span class="text-gray-400">No comments</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-document-chart-bar" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No results found</h3>
                                    <p class="text-gray-500">No exam results match your current filters</p>
                                    <x-button
                                        label="Clear Filters"
                                        icon="o-x-mark"
                                        wire:click="resetFilters"
                                        class="mt-2"
                                    />
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if ($results instanceof \Illuminate\Pagination\LengthAwarePaginator)
            <div class="mt-4">
                {{ $results->links() }}
            </div>
        @endif
    </x-card>
</div>
